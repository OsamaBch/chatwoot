<?php
/**
 * Plugin settings access and sanitization.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the small autoloaded settings array.
 *
 * This is the only autoloaded option the plugin creates. Everything large
 * (match plans, reports) is stored with autoload = no.
 */
class Settings {

	public const OPTION = 'msim_settings';

	public const MODE_SKIP    = 'skip';
	public const MODE_REPLACE = 'replace';
	public const MODE_MERGE   = 'merge';

	public const SINCE_TODAY  = 'today';
	public const SINCE_7_DAYS = '7days';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'existing_mode'   => self::MODE_SKIP,
			'only_unattached' => true,
			'uploaded_since'  => self::SINCE_TODAY,
			'auto_attach'     => false,
		);
	}

	/**
	 * Get the full sanitized settings array.
	 *
	 * @return array
	 */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return $this->sanitize( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * Get one setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether the auto-attach-on-upload listener is enabled.
	 *
	 * @return bool
	 */
	public function auto_attach_enabled() {
		return (bool) $this->get( 'auto_attach' );
	}

	/**
	 * Sanitize a raw settings array (register_setting sanitize callback).
	 *
	 * @param mixed $raw Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults();

		$mode = isset( $raw['existing_mode'] ) ? sanitize_key( $raw['existing_mode'] ) : $defaults['existing_mode'];
		if ( ! in_array( $mode, array( self::MODE_SKIP, self::MODE_REPLACE, self::MODE_MERGE ), true ) ) {
			$mode = self::MODE_SKIP;
		}

		$since = isset( $raw['uploaded_since'] ) ? sanitize_key( $raw['uploaded_since'] ) : $defaults['uploaded_since'];
		if ( ! in_array( $since, array( self::SINCE_TODAY, self::SINCE_7_DAYS ), true ) ) {
			$since = self::SINCE_TODAY;
		}

		return array(
			'existing_mode'   => $mode,
			'only_unattached' => ! empty( $raw['only_unattached'] ),
			'uploaded_since'  => $since,
			'auto_attach'     => ! empty( $raw['auto_attach'] ),
		);
	}

	/**
	 * Media-scan date cutoff in site-timezone MySQL format.
	 *
	 * Hard safety constraint: the scan can never look back further than
	 * 7 calendar days, so a run can never touch the existing catalog's
	 * images — only recently uploaded media.
	 *
	 * @param string $since One of the SINCE_* constants.
	 * @return string MySQL datetime (site timezone, matching posts.post_date).
	 */
	public function cutoff_for( $since ) {
		$now_local = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		if ( self::SINCE_7_DAYS === $since ) {
			// 7 calendar days including today — never more than 7 days back.
			return gmdate( 'Y-m-d 00:00:00', $now_local - ( 6 * DAY_IN_SECONDS ) );
		}

		return gmdate( 'Y-m-d 00:00:00', $now_local );
	}
}
