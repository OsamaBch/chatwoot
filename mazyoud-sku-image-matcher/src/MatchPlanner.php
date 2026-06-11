<?php
/**
 * Dry-run scan: builds the full match plan without writing anything.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

use Mazyoud\SkuImageMatcher\Matching\MatchContext;
use Mazyoud\SkuImageMatcher\Matching\MatcherInterface;
use Mazyoud\SkuImageMatcher\Matching\MatchResult;
use Mazyoud\SkuImageMatcher\Matching\ParentProductSkuMatcher;
use Mazyoud\SkuImageMatcher\Matching\ProductIdMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Scans recent Media Library images, resolves each filename through the
 * matcher pipeline and produces:
 *
 *  - a concrete per-product plan (featured attachment + ordered gallery),
 *  - a report (matched / not found / variation SKU / duplicate SKU /
 *    duplicate-rename warnings / skipped per the existing-images rule).
 *
 * Everything is bulk: one paginated media query, one in-memory SKU map,
 * one product-ID set, chunked meta lookups. No per-file SQL.
 */
class MatchPlanner {

	/**
	 * Media rows fetched per scan page.
	 */
	private const MEDIA_CHUNK = 5000;

	/**
	 * Product IDs per meta-lookup IN() chunk.
	 */
	private const META_CHUNK = 500;

	/**
	 * Products per Action Scheduler batch.
	 */
	public const BATCH_SIZE = 25;

	/**
	 * Constructor.
	 *
	 * @param FilenameParser $parser   Filename parser.
	 * @param SkuResolver    $resolver SKU resolver.
	 * @param Settings       $settings Settings accessor.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct(
		private FilenameParser $parser,
		private SkuResolver $resolver,
		private Settings $settings,
		private Logger $logger
	) {
	}

	/**
	 * The matcher pipeline, extensible via the `msim_matchers` filter.
	 *
	 * @return MatcherInterface[]
	 */
	public function get_matchers() {
		$defaults = array(
			new ParentProductSkuMatcher(),
			new ProductIdMatcher(),
		);

		/**
		 * Filter the matcher pipeline.
		 *
		 * Matchers run in order; the first non-null MatchResult wins and an
		 * error result stops the pipeline for that file. Register a custom
		 * implementation of MatcherInterface here (e.g. a v2
		 * VariationSkuMatcher) without touching core code.
		 *
		 * @param MatcherInterface[] $matchers Pipeline, in priority order.
		 */
		$matchers = apply_filters( 'msim_matchers', $defaults );

		return array_values(
			array_filter(
				(array) $matchers,
				static function ( $matcher ) {
					return $matcher instanceof MatcherInterface;
				}
			)
		);
	}

	/**
	 * Run the dry-run scan and persist the plan as a new "planned" run.
	 *
	 * @param RunRepository $runs Run repository (creates the run record).
	 * @return array{run_id:int, report:array} Run id and UI report.
	 */
	public function scan( RunRepository $runs ) {
		$settings        = $this->settings->all();
		$mode            = $settings['existing_mode'];
		$only_unattached = (bool) $settings['only_unattached'];
		$cutoff          = $this->settings->cutoff_for( $settings['uploaded_since'] );

		$sku_map  = $this->resolver->load_product_sku_map();
		$id_set   = $this->resolver->load_product_id_set();
		$context  = new MatchContext( $sku_map, $id_set );
		$matchers = $this->get_matchers();

		$report = array(
			'not_found'        => array(),
			'variation'        => array(),
			'duplicate_sku'    => array(),
			'duplicate_rename' => array(),
			'warnings'         => array(),
			'skipped_existing' => array(),
		);

		$product_files = array(); // product_id => file entries.
		$identifiers   = array(); // product_id => [type => identifier].
		$unresolved    = array(); // Files with no match, pending variation classification.
		$total_files   = 0;

		foreach ( $this->fetch_media( $cutoff, $only_unattached ) as $row ) {
			++$total_files;

			$attached_file = (string) $row->attached_file;
			if ( '' === $attached_file ) {
				continue;
			}

			$parsed = $this->parser->parse( $attached_file );
			if ( '' === $parsed->stem ) {
				continue;
			}

			// WP duplicate-upload rename (ABC-2.jpg re-uploaded → ABC-2-1.jpg):
			// flagged, never silently assigned.
			if ( $this->parser->is_wp_duplicate_rename( $parsed, (string) $row->post_title ) ) {
				$report['duplicate_rename'][] = array(
					'id'    => (int) $row->ID,
					'file'  => $parsed->file,
					'title' => (string) $row->post_title,
				);
				continue;
			}

			$result = $this->run_pipeline( $matchers, $parsed, $context );

			if ( null === $result ) {
				$unresolved[] = array(
					'id'         => (int) $row->ID,
					'file'       => $parsed->file,
					'candidates' => $parsed->all_candidates(),
				);
				continue;
			}

			if ( $result->is_error() ) {
				$report['duplicate_sku'][] = array(
					'id'          => (int) $row->ID,
					'file'        => $parsed->file,
					'sku'         => $result->identifier,
					'product_ids' => $result->error_product_ids,
				);
				continue;
			}

			$pid = $result->product_id;
			if ( ! isset( $product_files[ $pid ] ) ) {
				$product_files[ $pid ] = array();
				$identifiers[ $pid ]   = array();
			}

			$product_files[ $pid ][] = array(
				'id'       => (int) $row->ID,
				'file'     => $parsed->file,
				'date'     => (string) $row->post_date,
				'role'     => MatchResult::ROLE_MAIN === $result->role ? 'main' : 'gallery',
				'position' => $result->position,
			);

			$identifiers[ $pid ][ $result->matched_by ] = $result->identifier;

			foreach ( $result->warnings as $warning ) {
				$report['warnings'][] = $warning;
			}
		}

		// Classify unresolved files: known variation SKU vs truly not found.
		$this->classify_unresolved( $unresolved, $report );

		// Per-product assembly: featured/gallery ordering, duplicate handling.
		$products = array();
		foreach ( $product_files as $pid => $files ) {
			$grouped = $this->group_product_files( $files );
			if ( null === $grouped ) {
				continue;
			}

			foreach ( $grouped['warnings'] as $w ) {
				$report['warnings'][] = sprintf( 'Product #%d: %s', $pid, $w );
			}

			$products[ $pid ] = array(
				'product_id' => (int) $pid,
				'identifier' => $identifiers[ $pid ],
				'featured'   => $grouped['featured'],
				'gallery'    => $grouped['gallery'],
				'files'      => $grouped['files'],
				'warnings'   => $grouped['warnings'],
			);
		}

		// Current product state (name + existing images) for the
		// existing-images rule and the report. Chunked bulk queries.
		$states = $this->fetch_product_states( array_keys( $products ) );

		foreach ( $products as $pid => &$product ) {
			$state                    = isset( $states[ $pid ] ) ? $states[ $pid ] : array(
				'name'      => '#' . $pid,
				'thumbnail' => '',
				'gallery'   => '',
			);
			$product['product_name']  = $state['name'];
			$product['has_images']    = '' !== $state['thumbnail'] || '' !== $state['gallery'];
			$product['current_count'] = ( '' !== $state['thumbnail'] ? 1 : 0 )
				+ ( '' !== $state['gallery'] ? count( array_filter( explode( ',', $state['gallery'] ) ) ) : 0 );
		}
		unset( $product );

		// Existing-images rule, evaluated for the report. Skip mode removes
		// the product from the plan entirely (re-checked again at run time).
		if ( Settings::MODE_SKIP === $mode ) {
			foreach ( $products as $pid => $product ) {
				if ( ! empty( $product['has_images'] ) ) {
					$report['skipped_existing'][] = array(
						'product_id' => (int) $pid,
						'name'       => $product['product_name'],
						'files'      => count( $product['files'] ),
					);
					unset( $products[ $pid ] );
				}
			}
		}

		$product_ids    = array_keys( $products );
		$attachment_ids = array();
		foreach ( $products as $product ) {
			$attachment_ids[] = (int) $product['featured'];
			foreach ( $product['gallery'] as $gid ) {
				$attachment_ids[] = (int) $gid;
			}
		}

		$counts = array(
			'files_scanned'    => $total_files,
			'matched_products' => count( $products ),
			'matched_files'    => count( $attachment_ids ),
			'not_found'        => count( $report['not_found'] ),
			'variation'        => count( $report['variation'] ),
			'duplicate_sku'    => count( $report['duplicate_sku'] ),
			'duplicate_rename' => count( $report['duplicate_rename'] ),
			'skipped_existing' => count( $report['skipped_existing'] ),
			'warnings'         => count( $report['warnings'] ),
		);
		$report['counts'] = $counts;

		$plan = array(
			'version'        => 1,
			'created'        => current_time( 'mysql' ),
			'mode'           => $mode,
			'filters'        => array(
				'only_unattached' => $only_unattached,
				'uploaded_since'  => $settings['uploaded_since'],
				'cutoff'          => $cutoff,
			),
			'products'       => $products,
			'batches'        => array_chunk( $product_ids, self::BATCH_SIZE ),
			'attachment_ids' => array_values( array_unique( $attachment_ids ) ),
			'report'         => $report,
		);

		$run_id = $runs->create_planned_run( $plan, $counts );

		$this->logger->info(
			sprintf( 'Scan complete (run #%d)', $run_id ),
			$counts
		);

		return array(
			'run_id' => $run_id,
			'report' => $report,
		);
	}

	/**
	 * Run the matcher pipeline for one parsed filename.
	 *
	 * @param MatcherInterface[] $matchers Pipeline.
	 * @param ParsedFilename     $parsed   Parsed filename.
	 * @param MatchContext       $context  Lookup context.
	 * @return MatchResult|null First match/error result, or null.
	 */
	public function run_pipeline( array $matchers, ParsedFilename $parsed, MatchContext $context ) {
		foreach ( $matchers as $matcher ) {
			$result = $matcher->match( $parsed, $context );
			if ( null !== $result ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Assemble one product's matched files into featured + ordered gallery.
	 *
	 * Rules (each emitting a warning):
	 *  - several bare-identifier files: most recent becomes featured, the
	 *    others are prepended to the gallery;
	 *  - duplicate gallery position: most recent wins, others skipped;
	 *  - no bare-identifier file: the lowest-numbered file is promoted to
	 *    featured — a matched product never ends up without a featured image.
	 *
	 * @param array $files Entries: {id, file, date, role(main|gallery), position}.
	 * @return array{featured:int, gallery:int[], files:array, warnings:string[]}|null
	 */
	public function group_product_files( array $files ) {
		if ( empty( $files ) ) {
			return null;
		}

		$warnings = array();
		$mains    = array();
		$by_pos   = array();

		foreach ( $files as $entry ) {
			if ( 'main' === $entry['role'] ) {
				$mains[] = $entry;
				continue;
			}

			$pos = (int) $entry['position'];
			if ( isset( $by_pos[ $pos ] ) ) {
				$keep = $this->newest( $by_pos[ $pos ], $entry );
				$drop = ( $keep === $by_pos[ $pos ] ) ? $entry : $by_pos[ $pos ];

				$by_pos[ $pos ] = $keep;
				$warnings[]     = sprintf(
					'Duplicate gallery position %1$d: kept most recent "%2$s", skipped "%3$s".',
					$pos,
					$keep['file'],
					$drop['file']
				);
				continue;
			}

			$by_pos[ $pos ] = $entry;
		}

		// Most recent bare file first.
		usort(
			$mains,
			function ( $a, $b ) {
				return ( $this->newest( $a, $b ) === $a ) ? -1 : 1;
			}
		);

		$featured = null;
		$demoted  = array();

		if ( ! empty( $mains ) ) {
			$featured = array_shift( $mains );
			$demoted  = $mains;

			if ( ! empty( $demoted ) ) {
				$warnings[] = sprintf(
					'Multiple bare-identifier files: most recent "%1$s" set as featured; %2$s prepended to the gallery.',
					$featured['file'],
					implode( ', ', wp_list_pluck( $demoted, 'file' ) )
				);
			}
		}

		ksort( $by_pos, SORT_NUMERIC );

		if ( null === $featured ) {
			if ( empty( $by_pos ) ) {
				return null;
			}

			$lowest_pos = array_key_first( $by_pos );
			$featured   = $by_pos[ $lowest_pos ];
			unset( $by_pos[ $lowest_pos ] );

			$warnings[] = sprintf(
				'No bare-identifier file: promoted lowest-numbered "%s" to featured image.',
				$featured['file']
			);
		}

		$gallery_entries = array_merge( $demoted, array_values( $by_pos ) );

		$used   = array_merge( array( $featured ), $gallery_entries );
		$marked = array();
		foreach ( $used as $i => $entry ) {
			$entry['assigned'] = 0 === $i ? 'featured' : 'gallery';
			$marked[]          = $entry;
		}

		return array(
			'featured' => (int) $featured['id'],
			'gallery'  => array_map( 'intval', wp_list_pluck( $gallery_entries, 'id' ) ),
			'files'    => $marked,
			'warnings' => $warnings,
		);
	}

	/**
	 * Pick the most recently uploaded of two file entries (post_date, then
	 * attachment ID as tie-breaker).
	 *
	 * @param array $a First entry.
	 * @param array $b Second entry.
	 * @return array The newer entry.
	 */
	private function newest( array $a, array $b ) {
		$cmp = strcmp( (string) $a['date'], (string) $b['date'] );
		if ( 0 !== $cmp ) {
			return $cmp > 0 ? $a : $b;
		}

		return ( (int) $a['id'] >= (int) $b['id'] ) ? $a : $b;
	}

	/**
	 * Classify unresolved files as "variation SKU — planned for v2" or
	 * "not found", using one chunked IN() query over the candidates.
	 *
	 * @param array $unresolved Entries: {id, file, candidates}.
	 * @param array $report     Report (modified in place).
	 * @return void
	 */
	private function classify_unresolved( array $unresolved, array &$report ) {
		if ( empty( $unresolved ) ) {
			return;
		}

		$all_candidates = array();
		foreach ( $unresolved as $entry ) {
			foreach ( $entry['candidates'] as $candidate ) {
				$all_candidates[ $candidate ] = true;
			}
		}

		$variation_skus = $this->resolver->find_variation_skus( array_keys( $all_candidates ) );

		foreach ( $unresolved as $entry ) {
			$variation_sku = null;
			foreach ( $entry['candidates'] as $candidate ) { // Longest first.
				if ( isset( $variation_skus[ $candidate ] ) ) {
					$variation_sku = $candidate;
					break;
				}
			}

			if ( null !== $variation_sku ) {
				$report['variation'][] = array(
					'id'   => $entry['id'],
					'file' => $entry['file'],
					'sku'  => $variation_sku,
				);
			} else {
				$report['not_found'][] = array(
					'id'   => $entry['id'],
					'file' => $entry['file'],
				);
			}
		}
	}

	/**
	 * Paginated media scan: attachments with image mime types uploaded since
	 * the cutoff (site timezone), optionally unattached only. Single query
	 * shape, keyset-paginated in chunks of 5,000 rows.
	 *
	 * @param string $cutoff          MySQL datetime lower bound (post_date, site tz).
	 * @param bool   $only_unattached Restrict to post_parent = 0.
	 * @return \Generator<object> Rows: ID, post_title, post_date, post_parent, attached_file.
	 */
	private function fetch_media( $cutoff, $only_unattached ) {
		global $wpdb;

		$last_id = 0;

		do {
			$parent_clause = $only_unattached ? 'AND p.post_parent = 0' : '';

			// $parent_clause is a fixed literal chosen above — everything
			// dynamic goes through prepare().
			$sql = "SELECT p.ID, p.post_title, p.post_date, p.post_parent, pm.meta_value AS attached_file
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'
				   AND p.post_mime_type LIKE 'image/%'
				   AND p.post_date >= %s
				   {$parent_clause}
				   AND p.ID > %d
				 ORDER BY p.ID ASC
				 LIMIT %d";

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $cutoff, $last_id, self::MEDIA_CHUNK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$count = is_array( $rows ) ? count( $rows ) : 0;

			foreach ( (array) $rows as $row ) {
				$last_id = max( $last_id, (int) $row->ID );
				yield $row;
			}
		} while ( self::MEDIA_CHUNK === $count );
	}

	/**
	 * Bulk-fetch product name + current featured/gallery meta for the
	 * matched products (chunked IN() queries of 500 IDs).
	 *
	 * @param int[] $product_ids Product IDs.
	 * @return array<int, array{name:string, thumbnail:string, gallery:string}>
	 */
	private function fetch_product_states( array $product_ids ) {
		global $wpdb;

		$states = array();

		foreach ( array_chunk( array_map( 'intval', $product_ids ), self::META_CHUNK ) as $chunk ) {
			if ( empty( $chunk ) ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			$sql = "SELECT p.ID, p.post_title,
					MAX( CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END ) AS thumbnail,
					MAX( CASE WHEN pm.meta_key = '_product_image_gallery' THEN pm.meta_value END ) AS gallery
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID
					AND pm.meta_key IN ( '_thumbnail_id', '_product_image_gallery' )
				 WHERE p.ID IN ( {$placeholders} )
				 GROUP BY p.ID, p.post_title";

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			foreach ( (array) $rows as $row ) {
				$states[ (int) $row->ID ] = array(
					'name'      => (string) $row->post_title,
					'thumbnail' => trim( (string) $row->thumbnail ),
					'gallery'   => trim( (string) $row->gallery ),
				);
			}
		}

		return $states;
	}
}
