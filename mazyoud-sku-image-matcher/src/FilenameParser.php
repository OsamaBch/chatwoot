<?php
/**
 * Filename → SKU/position parser.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Parses attachment filenames according to the naming convention:
 *
 *  - SKU.jpg                       → main (featured) image
 *  - SKU-1.jpg / SKU_1.jpg / SKU 1.jpg → gallery position 1
 *  - SKU-2.jpg                     → gallery position 2, ...
 *  - "AB1234 robe fille rouge-2.jpg" → extra words after the SKU are allowed
 *    (resolved by longest-prefix matching against real SKUs).
 *
 * The position suffix is 1–2 digits max; longer digit runs are part of the
 * identifier itself. Accepted separators: hyphen, underscore, space.
 *
 * The parser is pure string logic (no DB access) so it is unit-testable.
 */
class FilenameParser {

	/**
	 * Separator character class between SKU, descriptive words and position.
	 */
	private const SEP = '[-_ ]';

	/**
	 * Parse a stored attachment path/basename into match candidates.
	 *
	 * The input should be the `_wp_attached_file` meta value (the parser
	 * takes the basename itself). WordPress-generated suffixes (-scaled,
	 * -rotated, -{W}x{H}, -e{timestamp}) are stripped before parsing.
	 *
	 * @param string $attached_file Value of _wp_attached_file (path or basename).
	 * @return ParsedFilename
	 */
	public function parse( $attached_file ) {
		$basename = $this->basename( (string) $attached_file );

		$dot      = strrpos( $basename, '.' );
		$raw_stem = ( false !== $dot && $dot > 0 ) ? substr( $basename, 0, $dot ) : $basename;
		$raw_stem = $this->lower( trim( $raw_stem ) );

		$stem     = $this->strip_wp_suffixes( $raw_stem );
		$position = null;
		$base     = $stem;

		// Trailing gallery position: separator(s) + 1–2 digits at the very end.
		if ( preg_match( '/^(?<base>.+?)' . self::SEP . '+(?<pos>\d{1,2})$/', $stem, $m ) ) {
			$position = (int) $m['pos'];
			$base     = $m['base'];
		}

		$base_prefixes = $this->prefixes( $base );
		$stem_prefixes = ( $base === $stem ) ? $base_prefixes : $this->prefixes( $stem );
		$shortest      = end( $base_prefixes );

		return new ParsedFilename(
			$basename,
			$raw_stem,
			$stem,
			$position,
			$base,
			$base_prefixes,
			$stem_prefixes,
			false !== $shortest ? $shortest : $base,
			$stem !== $raw_stem
		);
	}

	/**
	 * Detect WordPress duplicate-upload renaming.
	 *
	 * WordPress renames a re-uploaded duplicate by appending "-N" to the
	 * filename while the attachment post_title keeps the original name.
	 * Re-uploading ABC-2.jpg therefore yields the file ABC-2-1.jpg with the
	 * title "ABC-2" — which would otherwise parse as SKU "ABC-2" position 1.
	 * When the stored filename equals the (file-sanitized) title plus a
	 * trailing "-N", the file must be flagged and never silently assigned.
	 *
	 * @param ParsedFilename $parsed     Parsed filename.
	 * @param string         $post_title Attachment post_title (original upload name).
	 * @return bool True when the file looks like a WP duplicate rename.
	 */
	public function is_wp_duplicate_rename( ParsedFilename $parsed, $post_title ) {
		$title = trim( (string) $post_title );
		if ( '' === $title ) {
			return false;
		}

		// Normalize the title into "filename space" (accents stripped,
		// spaces → hyphens) so it is comparable with the stored file name.
		$title_stem = $this->lower( $this->sanitize_like_filename( $title ) );

		// Defensive: drop an extension if the title happens to contain one.
		$title_stem = preg_replace( '/\.(jpe?g|png|gif|webp|avif|bmp|tiff?)$/i', '', $title_stem );

		if ( '' === $title_stem || $parsed->stem === $title_stem ) {
			return false;
		}

		return (bool) preg_match( '/^' . preg_quote( $title_stem, '/' ) . '-\d+$/', $parsed->stem );
	}

	/**
	 * Strip WordPress-generated filename suffixes from a stem (already
	 * lowercased): -scaled, -rotated, -{W}x{H} intermediate sizes and
	 * -e{timestamp} image-edit suffixes. Applied repeatedly because suffixes
	 * can stack (e.g. "photo-2048x1536-rotated").
	 *
	 * @param string $stem Lowercased filename stem.
	 * @return string
	 */
	public function strip_wp_suffixes( $stem ) {
		$patterns = array(
			'/-scaled$/',
			'/-rotated$/',
			'/-e\d{10,}$/',          // Image-edit suffix (Unix timestamp).
			'/-\d{1,4}x\d{1,4}$/',   // Intermediate size, e.g. -300x200.
		);

		do {
			$before = $stem;
			foreach ( $patterns as $pattern ) {
				$stem = (string) preg_replace( $pattern, '', $stem );
			}
		} while ( $stem !== $before && '' !== $stem );

		return '' === $stem ? $before : $stem;
	}

	/**
	 * Build longest-prefix candidates of a name: the full string, then the
	 * string with one trailing token removed, and so on down to the first
	 * token. Original separators are preserved inside each candidate so SKUs
	 * containing separators keep matching.
	 *
	 * Example: "ab1234-robe-fille" → ["ab1234-robe-fille", "ab1234-robe", "ab1234"].
	 *
	 * @param string $name Lowercased name.
	 * @return string[] Candidates, longest first (never empty).
	 */
	public function prefixes( $name ) {
		$candidates = array();
		$current    = $name;

		while ( '' !== $current ) {
			$candidates[] = $current;
			$shorter      = preg_replace( '/' . self::SEP . '+[^-_ ]*$/', '', $current );
			if ( null === $shorter || $shorter === $current || '' === $shorter ) {
				break;
			}
			$current = $shorter;
		}

		return $candidates;
	}

	/**
	 * Lowercase helper (multibyte-safe when available).
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Apply the same transform WordPress applies to uploaded filenames.
	 * Falls back to a minimal equivalent outside WordPress (unit tests).
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public function sanitize_like_filename( $value ) {
		if ( function_exists( 'sanitize_file_name' ) ) {
			return sanitize_file_name( $value );
		}

		return preg_replace( '/\s+/', '-', trim( $value ) );
	}

	/**
	 * Basename helper tolerant of both / and \ separators.
	 *
	 * @param string $path Path or filename.
	 * @return string
	 */
	private function basename( $path ) {
		if ( function_exists( 'wp_basename' ) ) {
			return wp_basename( $path );
		}

		return basename( str_replace( '\\', '/', $path ) );
	}
}
