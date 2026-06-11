<?php
/**
 * Plugin Name:       Mazyoud SKU Image Matcher
 * Plugin URI:        https://mazyoud.com/
 * Description:       Automatically attaches Media Library images to WooCommerce products by parsing the SKU (or product ID) from the image filename. Dry-run scan, background processing via Action Scheduler, full rollback.
 * Version:           1.0.0
 * Author:            Mazyoud
 * Author URI:        https://mazyoud.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mazyoud-sku-image-matcher
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.8
 *
 * @package Mazyoud\SkuImageMatcher
 */

defined( 'ABSPATH' ) || exit;

define( 'MSIM_VERSION', '1.0.0' );
define( 'MSIM_DB_VERSION', '1.0.0' );
define( 'MSIM_FILE', __FILE__ );
define( 'MSIM_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSIM_URL', plugin_dir_url( __FILE__ ) );

require_once MSIM_DIR . 'src/Autoloader.php';
\Mazyoud\SkuImageMatcher\Autoloader::register();

register_activation_hook( __FILE__, array( \Mazyoud\SkuImageMatcher\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Mazyoud\SkuImageMatcher\Plugin::class, 'deactivate' ) );

/*
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * The plugin never touches order data, so it is fully compatible.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/*
 * Boot after WooCommerce (which bootstraps on plugins_loaded at priority 10).
 * Plugin::boot() registers nothing on frontend requests except the
 * add_attachment listener, and only when auto-attach is enabled.
 */
add_action( 'plugins_loaded', array( \Mazyoud\SkuImageMatcher\Plugin::class, 'boot' ), 20 );
