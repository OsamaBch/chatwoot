<?php
/**
 * Plugin Name: Order Access Control
 * Plugin URI:  https://www.mazyoud.com
 * Description: Role-based access control for WooCommerce orders: limits which orders restricted staff roles can see in the wp-admin orders list and Analytics. Read-path only; never modifies order data. Full-access administrators always see everything.
 * Version:     1.2.0
 * Author:      Mazyoud
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * Text Domain: maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

define( 'MAZ_ALLOWLIST_VERSION', '1.2.0' );
define( 'MAZ_ALLOWLIST_FILE', __FILE__ );
define( 'MAZ_ALLOWLIST_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAZ_ALLOWLIST_URL', plugin_dir_url( __FILE__ ) );

/*
 * Kill switch. Add to wp-config.php to make the plugin no-op entirely
 * (no hooks, no filtering, no admin page):
 *
 *     define( 'MAZ_ALLOWLIST_DISABLE', true );
 */
if ( defined( 'MAZ_ALLOWLIST_DISABLE' ) && MAZ_ALLOWLIST_DISABLE ) {
	return;
}

// Declare HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

require_once MAZ_ALLOWLIST_DIR . 'includes/class-maz-plugin.php';

register_activation_hook( __FILE__, array( 'Maz_Allowlist_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'Maz_Allowlist_Plugin', 'instance' ), 20 );
