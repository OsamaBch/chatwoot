<?php
/**
 * Product-ID fallback matcher.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher\Matching;

use Mazyoud\SkuImageMatcher\ParsedFilename;

defined( 'ABSPATH' ) || exit;

/**
 * Numeric product-ID fallback, registered after ParentProductSkuMatcher so
 * SKU always wins over ID (protects stores with numeric SKUs).
 *
 * When no SKU matched and the leading separator-delimited token of the
 * remaining name is purely numeric, it is treated as a WooCommerce product
 * ID — matching only if a product (post_type "product", never a variation)
 * exists with that ID. Examples: 4821.jpg → main image of product #4821;
 * 4821-2.jpg → gallery position 2.
 */
class ProductIdMatcher implements MatcherInterface {

	/**
	 * Match a parsed filename against product IDs.
	 *
	 * @param ParsedFilename $parsed  Parsed filename data.
	 * @param MatchContext   $context SKU map / product lookups.
	 * @return MatchResult|null
	 */
	public function match( ParsedFilename $parsed, MatchContext $context ) {
		$token = $parsed->leading_token;

		if ( '' === $token || ! ctype_digit( $token ) ) {
			return null;
		}

		$product_id = (int) $token;
		if ( $product_id <= 0 || ! $context->product_exists( $product_id ) ) {
			return null;
		}

		if ( null !== $parsed->position ) {
			return MatchResult::gallery( $product_id, (int) $parsed->position, MatchResult::BY_PRODUCT_ID, $token );
		}

		return MatchResult::main( $product_id, MatchResult::BY_PRODUCT_ID, $token );
	}
}
