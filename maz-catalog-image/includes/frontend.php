<?php
/**
 * Catalogue-loop scoping for the image-id filter.
 *
 * `woocommerce_product_get_image_id` fires in far more places than the
 * shop grid (cart, emails, admin, REST), so the filter is never
 * registered globally. It is attached on loop-item entry and detached on
 * loop-item exit, and — for block themes — around the render of the
 * `woocommerce/product-image` block only.
 *
 * @package maz-catalog-image
 */

defined( 'ABSPATH' ) || exit;

/**
 * Swap the loop image for the catalogue image when one is set and valid.
 *
 * Only ever active between the scoping hooks below.
 *
 * @param int        $image_id Featured image attachment ID.
 * @param WC_Product $product  Product being rendered.
 * @return int
 */
function maz_catalog_image_filter_image_id( $image_id, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $image_id;
	}

	$catalog_id = absint( get_post_meta( $product->get_id(), MAZ_CATALOG_IMAGE_META_KEY, true ) );

	if ( $catalog_id < 1 || ! wp_attachment_is_image( $catalog_id ) ) {
		return $image_id;
	}

	return $catalog_id;
}

/**
 * Attach the image-id filter for the duration of one loop item.
 */
function maz_catalog_image_enter_loop_item() {
	add_filter( 'woocommerce_product_get_image_id', 'maz_catalog_image_filter_image_id', 10, 2 );
}
add_action( 'woocommerce_before_shop_loop_item', 'maz_catalog_image_enter_loop_item', 1 );

/**
 * Detach the image-id filter once the loop item is done.
 */
function maz_catalog_image_exit_loop_item() {
	remove_filter( 'woocommerce_product_get_image_id', 'maz_catalog_image_filter_image_id', 10 );
}
add_action( 'woocommerce_after_shop_loop_item', 'maz_catalog_image_exit_loop_item', 999 );

/**
 * Block-theme scoping: the Product Collection / Products blocks do not
 * fire `woocommerce_before_shop_loop_item`, so additionally scope the
 * filter around the render of the `woocommerce/product-image` block.
 * Harmless on classic themes where the block never renders.
 *
 * @param array $parsed_block Parsed block about to render.
 * @return array
 */
function maz_catalog_image_before_block_render( $parsed_block ) {
	if ( ! is_admin() && isset( $parsed_block['blockName'] ) && 'woocommerce/product-image' === $parsed_block['blockName'] ) {
		add_filter( 'woocommerce_product_get_image_id', 'maz_catalog_image_filter_image_id', 10, 2 );
	}

	return $parsed_block;
}
add_filter( 'render_block_data', 'maz_catalog_image_before_block_render', 10, 1 );

/**
 * Detach the filter after the `woocommerce/product-image` block rendered.
 *
 * @param string $block_content Rendered block markup.
 * @param array  $block         Parsed block that rendered.
 * @return string
 */
function maz_catalog_image_after_block_render( $block_content, $block ) {
	if ( isset( $block['blockName'] ) && 'woocommerce/product-image' === $block['blockName'] ) {
		remove_filter( 'woocommerce_product_get_image_id', 'maz_catalog_image_filter_image_id', 10 );
	}

	return $block_content;
}
add_filter( 'render_block', 'maz_catalog_image_after_block_render', 999, 2 );
