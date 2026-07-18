<?php
/**
 * Plugin bootstrap.
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads modules and wires activation.
 *
 * Loading policy:
 *  - Admin-UI modules (settings page, notices, orders list) load only when is_admin().
 *  - The Analytics module loads on every request type: the Analytics UI talks to
 *    /wc-analytics/* REST endpoints (is_admin() is false there) and the Analytics
 *    CSV export runs in Action Scheduler (cron) context. Those hooks only ever fire
 *    on Analytics report queries, which no storefront/customer request executes.
 */
class Maz_Allowlist_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Maz_Allowlist_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get/boot the singleton.
	 *
	 * @return Maz_Allowlist_Plugin|null
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return null; // WooCommerce not active: do nothing.
			}
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: load modules.
	 */
	private function __construct() {
		$includes = MAZ_ALLOWLIST_DIR . 'includes/';

		require_once $includes . 'class-maz-config.php';
		require_once $includes . 'class-maz-audit-log.php';
		require_once $includes . 'class-maz-allowlist-table.php';
		require_once $includes . 'class-maz-visibility.php';

		Maz_Allowlist_Config::maybe_upgrade();

		// Analytics filtering must be active for admin pages, /wc-analytics/*
		// REST requests AND Action Scheduler (CSV export) runs — see the
		// class doc-block for why this is not gated on is_admin().
		require_once $includes . 'class-maz-analytics.php';
		Maz_Allowlist_Analytics::init();

		if ( is_admin() ) {
			require_once $includes . 'class-maz-admin-page.php';
			require_once $includes . 'class-maz-notices.php';
			require_once $includes . 'class-maz-orders-list.php';
			Maz_Allowlist_Admin_Page::init();
			Maz_Allowlist_Notices::init();
			Maz_Allowlist_Orders_List::init();
		}
	}

	/**
	 * Activation: create table, grant capability, seed options.
	 */
	public static function activate() {
		$includes = MAZ_ALLOWLIST_DIR . 'includes/';
		require_once $includes . 'class-maz-config.php';
		require_once $includes . 'class-maz-audit-log.php';
		require_once $includes . 'class-maz-allowlist-table.php';

		Maz_Allowlist_Table::install();
		Maz_Allowlist_Config::ensure_exists();

		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'maz_view_all_orders' ) ) {
			$role->add_cap( 'maz_view_all_orders' );
		}

		Maz_Allowlist_Audit_Log::record( 'activate', 'Plugin activated (v' . MAZ_ALLOWLIST_VERSION . ')' );
	}
}
