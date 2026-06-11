<?php
/**
 * Bulk SKU / product-ID resolution against wc_product_meta_lookup.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * All SKU database access lives here. Two strategies, both bulk — the
 * plugin never calls wc_get_product_id_by_sku() in a loop:
 *
 *  - Bulk scan: the whole product SKU set is loaded once per scan into an
 *    in-memory lowercase hash map (keyset-paginated indexed queries against
 *    wc_product_meta_lookup). The map carries both the raw lowercased SKU
 *    and its sanitize_file_name() transform, so matching is guaranteed
 *    case-insensitive and survives WordPress filename sanitization,
 *    regardless of database collation.
 *
 *  - Auto-attach (single file): the file's prefix candidates are resolved
 *    in ONE chunked IN() query ($wpdb->prepare with dynamic placeholders)
 *    against the indexed sku column — the full SKU set is never loaded on
 *    the upload path.
 *
 * Product post statuses include publish, draft, private and pending
 * (images often arrive before publication, and stores legitimately keep
 * live products private); trash is always excluded. The set is filterable
 * via `msim_matchable_post_statuses`.
 */
class SkuResolver {

	/**
	 * Rows per keyset-paginated SKU map query.
	 */
	private const LOAD_CHUNK = 20000;

	/**
	 * Candidates per IN() query chunk.
	 */
	private const IN_CHUNK = 500;

	/**
	 * Default post statuses considered matchable.
	 *
	 * @var string[]
	 */
	private const DEFAULT_STATUSES = array( 'publish', 'draft', 'private', 'pending' );

	/**
	 * Post statuses a product may have to be matchable.
	 *
	 * @return string[] Sanitized, never empty.
	 */
	public function matchable_statuses() {
		/**
		 * Filter the product post statuses eligible for matching.
		 *
		 * Trash should never be included. Defaults to publish, draft,
		 * private and pending.
		 *
		 * @param string[] $statuses Eligible post statuses.
		 */
		$statuses = apply_filters( 'msim_matchable_post_statuses', self::DEFAULT_STATUSES );

		$statuses = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $statuses ) ) ) );

		return empty( $statuses ) ? array( 'publish' ) : $statuses;
	}

	/**
	 * Load the full parent-product SKU map: lowercased key → product IDs.
	 *
	 * Each SKU is keyed by both strtolower(sku) and
	 * strtolower(sanitize_file_name(sku)); a key with multiple product IDs
	 * indicates duplicate-SKU data and is reported as an error by matchers.
	 *
	 * @return array<string, int[]>
	 */
	public function load_product_sku_map() {
		global $wpdb;

		$parser   = new FilenameParser();
		$map      = array();
		$last_id  = 0;
		$statuses = $this->matchable_statuses();
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		do {
			$sql = "SELECT l.product_id, l.sku
				 FROM {$wpdb->prefix}wc_product_meta_lookup l
				 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
				 WHERE p.post_type = 'product'
				   AND p.post_status IN ( {$status_placeholders} )
				   AND l.sku <> ''
				   AND l.product_id > %d
				 ORDER BY l.product_id ASC
				 LIMIT %d";

			$params = array_merge( $statuses, array( $last_id, self::LOAD_CHUNK ) );
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			foreach ( (array) $rows as $row ) {
				$product_id = (int) $row->product_id;
				$last_id    = max( $last_id, $product_id );

				$raw_key       = $parser->lower( trim( (string) $row->sku ) );
				$sanitized_key = $parser->lower( $parser->sanitize_like_filename( (string) $row->sku ) );

				foreach ( array_unique( array( $raw_key, $sanitized_key ) ) as $key ) {
					if ( '' === $key ) {
						continue;
					}
					if ( ! isset( $map[ $key ] ) ) {
						$map[ $key ] = array();
					}
					if ( ! in_array( $product_id, $map[ $key ], true ) ) {
						$map[ $key ][] = $product_id;
					}
				}
			}

			$count = is_array( $rows ) ? count( $rows ) : 0;
		} while ( self::LOAD_CHUNK === $count );

		return $map;
	}

	/**
	 * Load the set of valid product IDs (post_type "product", matchable
	 * statuses) for the product-ID fallback matcher. Variations are never
	 * included.
	 *
	 * @return array<int, true> Flipped set for O(1) membership checks.
	 */
	public function load_product_id_set() {
		global $wpdb;

		$statuses = $this->matchable_statuses();
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product'
				   AND post_status IN ( {$status_placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$statuses
			)
		);

		$set = array();
		foreach ( (array) $ids as $id ) {
			$set[ (int) $id ] = true;
		}

		return $set;
	}

	/**
	 * Resolve a list of candidate SKU strings with chunked, prepared IN()
	 * queries against the indexed sku column, grouped by post type. Used by
	 * the auto-attach path (one file = ONE query covering products and
	 * variations) and by variation-SKU classification.
	 *
	 * Case-insensitivity relies on the (default) *_ci MySQL collation, with
	 * a PHP-side lowercase comparison as a defensive post-filter.
	 *
	 * @param string[] $candidates Lowercased candidate strings.
	 * @param string[] $post_types Post types to match, e.g. ['product', 'product_variation'].
	 * @return array<string, array<string, int[]>> post_type → (lowercased SKU → IDs).
	 */
	public function resolve_candidates_grouped( array $candidates, array $post_types = array( 'product' ) ) {
		global $wpdb;

		$candidates = array_values( array_unique( array_filter( array_map( 'strval', $candidates ), 'strlen' ) ) );
		if ( empty( $candidates ) || empty( $post_types ) ) {
			return array();
		}

		$parser = new FilenameParser();
		$wanted = array();
		foreach ( $candidates as $candidate ) {
			$wanted[ $parser->lower( $candidate ) ] = true;
		}

		$statuses            = $this->matchable_statuses();
		$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$found = array();

		foreach ( array_chunk( $candidates, self::IN_CHUNK ) as $chunk ) {
			$sku_placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			$sql = "SELECT l.product_id, l.sku, p.post_type
				 FROM {$wpdb->prefix}wc_product_meta_lookup l
				 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
				 WHERE p.post_type IN ( {$type_placeholders} )
				   AND p.post_status IN ( {$status_placeholders} )
				   AND l.sku IN ( {$sku_placeholders} )";

			$params = array_merge( $post_types, $statuses, $chunk );
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			foreach ( (array) $rows as $row ) {
				$key = $parser->lower( trim( (string) $row->sku ) );

				// Defensive: only keep rows the caller actually asked for
				// (protects against accent-insensitive collation surprises).
				if ( ! isset( $wanted[ $key ] ) ) {
					continue;
				}

				$type = (string) $row->post_type;
				if ( ! isset( $found[ $type ][ $key ] ) ) {
					$found[ $type ][ $key ] = array();
				}
				$id = (int) $row->product_id;
				if ( ! in_array( $id, $found[ $type ][ $key ], true ) ) {
					$found[ $type ][ $key ][] = $id;
				}
			}
		}

		return $found;
	}

	/**
	 * Among the given candidates, return those that exist as variation SKUs.
	 * Used to classify unmatched files under "variation SKU — planned for
	 * v2" instead of "not found". Bounded by the number of scanned files,
	 * never by catalog size.
	 *
	 * @param string[] $candidates Lowercased candidate strings.
	 * @return array<string, int[]> Lowercased SKU → variation IDs.
	 */
	public function find_variation_skus( array $candidates ) {
		$grouped = $this->resolve_candidates_grouped( $candidates, array( 'product_variation' ) );

		return isset( $grouped['product_variation'] ) ? $grouped['product_variation'] : array();
	}

	/**
	 * Whether a product (post_type "product", matchable status) exists with
	 * the given ID. Single targeted query — used by the auto-attach path only.
	 *
	 * @param int $product_id Candidate ID.
	 * @return bool
	 */
	public function product_exists( $product_id ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return false;
		}

		$statuses = $this->matchable_statuses();
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE ID = %d
				   AND post_type = 'product'
				   AND post_status IN ( {$status_placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $product_id ), $statuses )
			)
		);

		return null !== $found;
	}
}
