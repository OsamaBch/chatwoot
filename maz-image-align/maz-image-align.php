<?php
/**
 * Plugin Name: Mazyoud Image Align
 * Description: One-click alignment of product-on-hanger photos to identical negative space: same gap above the hanger ball on every image, per-type garment size, background sampled per image. Buttons in the Media Library and on WooCommerce product images, bulk processing in the background, before/after preview, one pristine backup per image and one-click revert.
 * Version: 2.0.1
 * Author: Mazyoud
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MAZ_IA_VERSION', '2.0.1' );
define( 'MAZ_IA_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-maz-ia-engine.php';
require_once __DIR__ . '/includes/class-maz-ia-settings.php';
require_once __DIR__ . '/includes/class-maz-ia-files.php';
require_once __DIR__ . '/includes/class-maz-ia-queue.php';
require_once __DIR__ . '/includes/class-maz-ia-admin.php';

Maz_IA_Settings::init();
Maz_IA_Files::init();
Maz_IA_Queue::init();
if ( is_admin() ) Maz_IA_Admin::init();

/** Migrate v1.x options: drop removed keys ('mode', 'threshold', 'bg_delta', 'keep_backup'), keep the rest. */
add_action( 'admin_init', function () {
	if ( get_option( 'maz_ia_version' ) === MAZ_IA_VERSION ) return;
	$old = get_option( Maz_IA_Settings::OPT, [] );
	if ( is_array( $old ) && $old ) {
		$old = array_intersect_key( $old, Maz_IA_Settings::defaults() );
		// 2.0.1: the ball is found by colour distance; the old luminance cut (205) chopped light wooden balls.
		if ( isset( $old['ball_threshold'] ) && (int) $old['ball_threshold'] <= 205 ) $old['ball_threshold'] = 250;
		update_option( Maz_IA_Settings::OPT, Maz_IA_Settings::sanitize( $old ) );
	}
	update_option( 'maz_ia_version', MAZ_IA_VERSION, false );
} );
