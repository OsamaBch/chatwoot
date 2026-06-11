<?php
/**
 * Parsed filename value object.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of parsing one attachment filename.
 *
 * All string fields are lowercased; matching is case-insensitive on the SKU.
 */
final class ParsedFilename {

	/**
	 * Constructor.
	 *
	 * @param string   $file           Original basename including extension.
	 * @param string   $raw_stem       Lowercased stem before WordPress suffix stripping (extension removed).
	 * @param string   $stem           Lowercased stem after stripping -scaled / -rotated / -WxH / -e{ts}.
	 * @param int|null $position       Trailing 1–2 digit gallery position, null for a bare identifier.
	 * @param string   $base           Stem minus the trailing position suffix (equals $stem when $position is null).
	 * @param string[] $base_prefixes  Longest-prefix candidates of $base, longest first (includes $base itself).
	 * @param string[] $stem_prefixes  Longest-prefix candidates of $stem, longest first (includes $stem itself).
	 * @param string   $leading_token  First separator-delimited token of $base (product-ID candidate).
	 * @param bool     $wp_suffix_stripped Whether a WordPress-generated suffix was removed.
	 */
	public function __construct(
		public readonly string $file,
		public readonly string $raw_stem,
		public readonly string $stem,
		public readonly ?int $position,
		public readonly string $base,
		public readonly array $base_prefixes,
		public readonly array $stem_prefixes,
		public readonly string $leading_token,
		public readonly bool $wp_suffix_stripped
	) {
	}

	/**
	 * All distinct SKU candidate strings this file could resolve to,
	 * longest-form first. Used to build single-query lookups (auto-attach)
	 * and the variation-SKU classification.
	 *
	 * @return string[]
	 */
	public function all_candidates() {
		$candidates = array( $this->raw_stem, $this->stem );

		foreach ( $this->stem_prefixes as $prefix ) {
			$candidates[] = $prefix;
		}
		foreach ( $this->base_prefixes as $prefix ) {
			$candidates[] = $prefix;
		}

		return array_values( array_unique( array_filter( $candidates, 'strlen' ) ) );
	}
}
