<?php
/**
 * Matching context (SKU map + product existence lookup).
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher\Matching;

defined( 'ABSPATH' ) || exit;

/**
 * Data the matchers resolve against.
 *
 * For bulk scans the context is fully preloaded (one indexed query for the
 * whole SKU map, one for the product-ID set) so matching is pure in-memory
 * hash lookups. For the single-file auto-attach path a mini context is built
 * from one IN() query over just that file's prefix candidates, plus a lazy
 * product-ID existence callback.
 */
final class MatchContext {

	/**
	 * Lowercased SKU key → list of product IDs.
	 *
	 * Keys contain both the raw lowercased SKU and its sanitize_file_name()
	 * transform, so SKUs whose special characters WordPress strips from
	 * filenames still match. A key mapping to more than one product ID
	 * signals dirty duplicate-SKU data.
	 *
	 * @var array<string, int[]>
	 */
	private array $sku_map;

	/**
	 * Product existence check. Either a preloaded set (array<int,true>) or a
	 * callable(int): bool for lazy lookups.
	 *
	 * @var array<int,bool>|callable
	 */
	private $product_ids;

	/**
	 * Constructor.
	 *
	 * @param array<string,int[]>          $sku_map     Lowercased SKU key → product IDs.
	 * @param array<int,bool>|callable $product_ids Product-ID set or lazy callback.
	 */
	public function __construct( array $sku_map, $product_ids ) {
		$this->sku_map     = $sku_map;
		$this->product_ids = $product_ids;
	}

	/**
	 * Resolve a lowercased SKU key to its product IDs.
	 *
	 * @param string $key Lowercased candidate.
	 * @return int[] Empty when unknown; more than one element = duplicate SKU.
	 */
	public function resolve_sku( $key ) {
		return isset( $this->sku_map[ $key ] ) ? $this->sku_map[ $key ] : array();
	}

	/**
	 * Whether a published/draft product (post_type "product" only — never a
	 * variation) exists with this ID.
	 *
	 * @param int $product_id Candidate product ID.
	 * @return bool
	 */
	public function product_exists( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( is_callable( $this->product_ids ) ) {
			return (bool) call_user_func( $this->product_ids, $product_id );
		}

		return isset( $this->product_ids[ $product_id ] );
	}
}
