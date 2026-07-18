<?php
/**
 * Clean uninstall: drop the allowlist table, delete options and user meta.
 * Order data itself is never touched — this plugin is strictly read-path.
 *
 * @package maz-allowlist
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Custom table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}maz_order_allowlist" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Options.
delete_option( 'maz_allowlist_config' );
delete_option( 'maz_allowlist_list_rev' );
delete_option( 'maz_allowlist_audit_log' );

// Transients.
delete_transient( 'maz_allowlist_currencies' );
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_maz\_allowlist\_%'
	    OR option_name LIKE '\_transient\_timeout\_maz\_allowlist\_%'"
);

// Per-user notice dismissals.
delete_metadata( 'user', 0, 'maz_allowlist_notice_dismissed', '', true );

// Capability.
foreach ( wp_roles()->role_objects as $maz_role ) {
	if ( $maz_role->has_cap( 'maz_view_all_orders' ) ) {
		$maz_role->remove_cap( 'maz_view_all_orders' );
	}
}
