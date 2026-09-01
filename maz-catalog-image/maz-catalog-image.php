<?php
/**
 * Plugin Name:       Maz Catalogue Image
 * Description:       Lets a product show one image in catalogue loops (shop, category, tag, search, related, up-sells, cross-sells) and a different image on its own product page.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Maz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       maz-catalog-image
 */

defined( 'ABSPATH' ) || exit;

define( 'MAZ_CATALOG_IMAGE_VERSION', '1.0.0' );
define( 'MAZ_CATALOG_IMAGE_FILE', __FILE__ );
define( 'MAZ_CATALOG_IMAGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAZ_CATALOG_IMAGE_URL', plugin_dir_url( __FILE__ ) );
define( 'MAZ_CATALOG_IMAGE_META_KEY', '_maz_catalog_image_id' );

/**
 * Boot the plugin once all plugins are loaded. No-ops cleanly when
 * WooCommerce is not active.
 */
function maz_catalog_image_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require MAZ_CATALOG_IMAGE_DIR . 'includes/frontend.php';

	if ( is_admin() ) {
		require MAZ_CATALOG_IMAGE_DIR . 'includes/admin.php';
	}

	add_action( 'init', 'maz_catalog_image_register_meta' );
}
add_action( 'plugins_loaded', 'maz_catalog_image_bootstrap' );

/**
 * Register the product meta so the OMS sync can set it over REST.
 */
function maz_catalog_image_register_meta() {
	register_post_meta(
		'product',
		MAZ_CATALOG_IMAGE_META_KEY,
		array(
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'auth_callback'     => 'maz_catalog_image_meta_auth',
			'sanitize_callback' => 'maz_catalog_image_sanitize_meta',
		)
	);
}

/**
 * Only users who can edit the specific product may write the meta.
 *
 * @param bool   $allowed  Unused default.
 * @param string $meta_key Meta key being written.
 * @param int    $post_id  Product ID.
 * @return bool
 */
function maz_catalog_image_meta_auth( $allowed, $meta_key, $post_id ) {
	return current_user_can( 'edit_product', $post_id );
}

/**
 * Coerce to a positive attachment ID that is really an image; anything
 * else becomes 0.
 *
 * @param mixed $value Raw meta value.
 * @return int
 */
function maz_catalog_image_sanitize_meta( $value ) {
	$id = absint( $value );

	if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
		return 0;
	}

	return $id;
}

/**
 * Declare HPOS compatibility — the plugin never touches order data.
 */
function maz_catalog_image_declare_wc_compat() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MAZ_CATALOG_IMAGE_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'maz_catalog_image_declare_wc_compat' );
