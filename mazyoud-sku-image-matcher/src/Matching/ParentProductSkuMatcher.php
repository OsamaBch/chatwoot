<?php
/**
 * Parent-product SKU matcher (v1 core algorithm).
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher\Matching;

use Mazyoud\SkuImageMatcher\ParsedFilename;

defined( 'ABSPATH' ) || exit;

/**
 * Smart longest-prefix SKU matching against parent products.
 *
 * Resolution order for a file (edge-case precedence per spec):
 *
 *  ① The full remaining name tried as an exact SKU → main image. Both the
 *    pre-suffix-strip stem and the stripped stem are tried, protecting SKUs
 *    that themselves look like WordPress size suffixes (e.g. "MAT-100x200").
 *  ② Only then: extract the trailing 1–2 digit position and longest-prefix
 *    match the remainder (progressively stripping trailing tokens) → gallery.
 *
 * If both interpretations resolve to existing SKUs an ambiguity warning is
 * emitted and interpretation ① wins. A SKU mapped to several products is a
 * hard error (the plugin never guesses). When a purely numeric SKU matches
 * while a different product carries that ID, an informational warning is
 * added (SKU always wins over ID).
 */
class ParentProductSkuMatcher implements MatcherInterface {

	/**
	 * Match a parsed filename against parent-product SKUs.
	 *
	 * @param ParsedFilename $parsed  Parsed filename data.
	 * @param MatchContext   $context SKU map / product lookups.
	 * @return MatchResult|null
	 */
	public function match( ParsedFilename $parsed, MatchContext $context ) {
		// ① Exact full-name SKU → main image. Pre-strip stem first so SKUs
		// containing -WxH / -scaled style endings are never mangled.
		$exact_keys = array_values( array_unique( array( $parsed->raw_stem, $parsed->stem ) ) );

		foreach ( $exact_keys as $key ) {
			$ids = $context->resolve_sku( $key );

			if ( count( $ids ) > 1 ) {
				return MatchResult::duplicate_sku( $key, $ids );
			}

			if ( 1 === count( $ids ) ) {
				$result = MatchResult::main( $ids[0], MatchResult::BY_SKU, $key );
				$this->maybe_warn_ambiguous_position( $parsed, $context, $result );
				$this->maybe_warn_numeric_sku_id_clash( $context, $result );

				return $result;
			}
		}

		if ( null !== $parsed->position ) {
			// ② Trailing position extracted; longest-prefix match the base.
			return $this->match_prefixes( $parsed->base_prefixes, $parsed, $context, true );
		}

		// Bare identifier with extra descriptive words: longest-prefix match
		// the stem (the full stem was already tried above).
		return $this->match_prefixes( array_slice( $parsed->stem_prefixes, 1 ), $parsed, $context, false );
	}

	/**
	 * Walk prefix candidates longest-first and return the first SKU hit.
	 *
	 * @param string[]       $prefixes    Candidates, longest first.
	 * @param ParsedFilename $parsed      Parsed filename.
	 * @param MatchContext   $context     Lookup context.
	 * @param bool           $as_gallery  Whether a hit is a gallery match.
	 * @return MatchResult|null
	 */
	private function match_prefixes( array $prefixes, ParsedFilename $parsed, MatchContext $context, $as_gallery ) {
		foreach ( $prefixes as $key ) {
			$ids = $context->resolve_sku( $key );

			if ( empty( $ids ) ) {
				continue;
			}

			// Longest matching prefix is duplicated across products: hard
			// error — trying a shorter prefix instead would be guessing.
			if ( count( $ids ) > 1 ) {
				return MatchResult::duplicate_sku( $key, $ids );
			}

			$result = $as_gallery
				? MatchResult::gallery( $ids[0], (int) $parsed->position, MatchResult::BY_SKU, $key )
				: MatchResult::main( $ids[0], MatchResult::BY_SKU, $key );

			$this->maybe_warn_numeric_sku_id_clash( $context, $result );

			return $result;
		}

		return null;
	}

	/**
	 * Interpretation ① matched the full name as a SKU, but the name also has
	 * a trailing position whose base resolves to a SKU as well — both
	 * readings are valid. Warn; interpretation ① is preferred.
	 *
	 * @param ParsedFilename $parsed  Parsed filename.
	 * @param MatchContext   $context Lookup context.
	 * @param MatchResult    $result  The chosen (main image) result.
	 * @return void
	 */
	private function maybe_warn_ambiguous_position( ParsedFilename $parsed, MatchContext $context, MatchResult $result ) {
		if ( null === $parsed->position ) {
			return;
		}

		foreach ( $parsed->base_prefixes as $key ) {
			$alt = $context->resolve_sku( $key );
			if ( ! empty( $alt ) ) {
				$result->add_warning(
					sprintf(
						'Ambiguous filename "%1$s": matched as main image of SKU "%2$s", but could also be gallery position %3$d of SKU "%4$s". Full-SKU interpretation preferred.',
						$parsed->file,
						$result->identifier,
						(int) $parsed->position,
						$key
					)
				);

				return;
			}
		}
	}

	/**
	 * Informational warning when a purely numeric SKU matched while a
	 * different product exists with that same ID (SKU wins over ID).
	 *
	 * @param MatchContext $context Lookup context.
	 * @param MatchResult  $result  Match result to annotate.
	 * @return void
	 */
	private function maybe_warn_numeric_sku_id_clash( MatchContext $context, MatchResult $result ) {
		$identifier = $result->identifier;

		if ( ! ctype_digit( $identifier ) ) {
			return;
		}

		$as_id = (int) $identifier;
		if ( $as_id !== $result->product_id && $context->product_exists( $as_id ) ) {
			$result->add_warning(
				sprintf(
					'Numeric SKU "%1$s" matched product #%2$d, but a different product #%3$d has this ID. SKU match wins.',
					$identifier,
					$result->product_id,
					$as_id
				)
			);
		}
	}
}
