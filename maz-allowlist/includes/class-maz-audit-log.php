<?php
/**
 * Audit log for configuration changes.
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capped ring buffer of config-change events, stored in an option.
 * Records who, when, what changed, and the seed value at the time.
 */
class Maz_Allowlist_Audit_Log {

	const OPTION      = 'maz_allowlist_audit_log';
	const MAX_ENTRIES = 200;

	/**
	 * Record an event.
	 *
	 * @param string $action Action slug (config_save, allowlist_import, allowlist_clear, seed_reroll, activate).
	 * @param string $detail Human-readable description of what changed.
	 * @param string $seed   Seed value at the time of the change ('' if not relevant).
	 */
	public static function record( $action, $detail, $seed = '' ) {
		$user    = wp_get_current_user();
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$entries[] = array(
			'time'       => time(),
			'user_id'    => $user ? (int) $user->ID : 0,
			'user_login' => ( $user && $user->exists() ) ? $user->user_login : '(system)',
			'action'     => (string) $action,
			'detail'     => (string) $detail,
			'seed'       => (string) $seed,
		);

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Get entries, newest first.
	 *
	 * @param int $limit Max entries to return.
	 * @return array[]
	 */
	public static function get_entries( $limit = 50 ) {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return array_slice( array_reverse( $entries ), 0, $limit );
	}
}
