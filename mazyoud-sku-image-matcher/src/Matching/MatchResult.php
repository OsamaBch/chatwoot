<?php
/**
 * Matcher result value object.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher\Matching;

defined( 'ABSPATH' ) || exit;

/**
 * Target object produced by a matcher: which product, and in which role
 * (featured "main" image or gallery position) the file should be attached.
 *
 * A result can also represent a hard matching error (duplicate SKU across
 * several products) which stops the matcher pipeline: the plugin never
 * guesses between candidate products.
 */
final class MatchResult {

	public const ROLE_MAIN    = 'main';
	public const ROLE_GALLERY = 'gallery';

	public const BY_SKU        = 'sku';
	public const BY_PRODUCT_ID = 'product_id';

	public const ERROR_DUPLICATE_SKU = 'duplicate_sku';

	/**
	 * Constructor — use the static factories instead.
	 *
	 * @param int      $product_id        Target product ID (0 for error results).
	 * @param string   $role              self::ROLE_MAIN or self::ROLE_GALLERY.
	 * @param int|null $position          Gallery position (null for main).
	 * @param string   $matched_by        self::BY_SKU or self::BY_PRODUCT_ID.
	 * @param string   $identifier        The SKU string or product ID that matched.
	 * @param string[] $warnings          Human-readable warnings raised while matching.
	 * @param string   $error             Error code ('' when the result is a match).
	 * @param int[]    $error_product_ids Product IDs involved in the error.
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly string $role,
		public readonly ?int $position,
		public readonly string $matched_by,
		public readonly string $identifier,
		public array $warnings = array(),
		public readonly string $error = '',
		public readonly array $error_product_ids = array()
	) {
	}

	/**
	 * Build a featured ("main") image match.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $matched_by BY_SKU or BY_PRODUCT_ID.
	 * @param string $identifier Matched identifier.
	 * @return self
	 */
	public static function main( $product_id, $matched_by, $identifier ) {
		return new self( (int) $product_id, self::ROLE_MAIN, null, $matched_by, (string) $identifier );
	}

	/**
	 * Build a gallery-position match.
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $position   Gallery position (1-based by convention).
	 * @param string $matched_by BY_SKU or BY_PRODUCT_ID.
	 * @param string $identifier Matched identifier.
	 * @return self
	 */
	public static function gallery( $product_id, $position, $matched_by, $identifier ) {
		return new self( (int) $product_id, self::ROLE_GALLERY, (int) $position, $matched_by, (string) $identifier );
	}

	/**
	 * Build a duplicate-SKU error result (SKU present on several products).
	 *
	 * @param string $sku         Duplicated SKU.
	 * @param int[]  $product_ids Products sharing the SKU.
	 * @return self
	 */
	public static function duplicate_sku( $sku, array $product_ids ) {
		return new self( 0, self::ROLE_MAIN, null, self::BY_SKU, (string) $sku, array(), self::ERROR_DUPLICATE_SKU, array_map( 'intval', $product_ids ) );
	}

	/**
	 * Whether this result is a hard error (no assignment must happen).
	 *
	 * @return bool
	 */
	public function is_error() {
		return '' !== $this->error;
	}

	/**
	 * Append a warning message.
	 *
	 * @param string $message Warning text.
	 * @return void
	 */
	public function add_warning( $message ) {
		$this->warnings[] = (string) $message;
	}
}
