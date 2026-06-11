<?php
/**
 * Clean uninstall: drops the snapshots table, deletes every plugin option
 * and meta marker, and removes any remaining scheduled actions. Leaves no
 * tables, options or scheduled actions behind.
 *
 * @package Mazyoud\SkuImageMatcher
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
defined( 'ABSPATH' ) || exit;

global $wpdb;

// 1. Scheduled actions. Prefer the Action Scheduler API when available
// (WooCommerce active); otherwise remove rows directly if the AS tables
// exist so nothing orphaned remains.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'msim_process_batch', array(), 'msim' );
	as_unschedule_all_actions( 'msim_rollback_batch', array(), 'msim' );
} else {
	$as_table = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table ) ) === $as_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$action_ids = $wpdb->get_col(
			"SELECT action_id FROM {$as_table} WHERE hook IN ( 'msim_process_batch', 'msim_rollback_batch' )" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! empty( $action_ids ) ) {
			$ids_sql = implode( ',', array_map( 'intval', $action_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$as_table} WHERE action_id IN ( {$ids_sql} )" );

			$logs_table = $wpdb->prefix . 'actionscheduler_logs';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$logs_table} WHERE action_id IN ( {$ids_sql} )" );
			}
		}
	}
}

// 2. Custom table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}msim_snapshots" );

// 3. Options: fixed names plus every per-run plan option (msim_plan_{id}).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->esc_like( 'msim_' ) . '%'
	)
);

// 4. Managed-product marker meta.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'_msim_managed'
	)
);

wp_cache_flush();
