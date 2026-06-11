<?php
/**
 * Matcher contract — the extensibility seam for v2 matchers.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher\Matching;

use Mazyoud\SkuImageMatcher\ParsedFilename;

defined( 'ABSPATH' ) || exit;

/**
 * A matcher turns parsed filename data into a target object (product ID +
 * role). Matchers run as a pipeline (registered via the `msim_matchers`
 * filter, in order): the first non-null result wins; an error result stops
 * the pipeline for that file.
 *
 * v1 ships ParentProductSkuMatcher (longest-prefix SKU matching on parent
 * products) and ProductIdMatcher (numeric product-ID fallback). A future
 * VariationSkuMatcher (variation swatch images) can be added without
 * touching core code — see README.md "Extending the matcher pipeline".
 */
interface MatcherInterface {

	/**
	 * Attempt to match a parsed filename to a product.
	 *
	 * @param ParsedFilename $parsed  Parsed filename data.
	 * @param MatchContext   $context SKU map / product lookups.
	 * @return MatchResult|null A match or error result, or null to let the
	 *                          next matcher in the pipeline try.
	 */
	public function match( ParsedFilename $parsed, MatchContext $context );
}
