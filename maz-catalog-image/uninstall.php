<?php
/**
 * Uninstall handler: remove every `_maz_catalog_image_id` row.
 * Runs only on uninstall — deactivation deletes nothing.
 *
 * @package maz-catalog-image
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_post_meta_by_key( '_maz_catalog_image_id' );
