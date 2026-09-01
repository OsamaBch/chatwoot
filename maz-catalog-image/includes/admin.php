<?php
/**
 * Admin UI: meta box, save handler, products-list column, asset enqueue.
 *
 * @package maz-catalog-image
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Catalogue image meta box under Product image / gallery.
 */
function maz_catalog_image_add_meta_box() {
	add_meta_box(
		'maz_catalog_image',
		__( 'Catalogue image', 'maz-catalog-image' ),
		'maz_catalog_image_render_meta_box',
		'product',
		'side',
		'low'
	);
}
add_action( 'add_meta_boxes_product', 'maz_catalog_image_add_meta_box' );

/**
 * Render the meta box.
 *
 * @param WP_Post $post Product post.
 */
function maz_catalog_image_render_meta_box( $post ) {
	$id = absint( get_post_meta( $post->ID, MAZ_CATALOG_IMAGE_META_KEY, true ) );

	if ( $id && ! wp_attachment_is_image( $id ) ) {
		$id = 0;
	}

	wp_nonce_field( 'maz_catalog_image_save', 'maz_catalog_image_nonce' );
	?>
	<div id="maz-catalog-image-field">
		<div id="maz-catalog-image-preview">
			<?php
			if ( $id ) {
				echo wp_get_attachment_image( $id, 'thumbnail' );
			} else {
				echo '<p class="description">' . esc_html__( 'No catalogue image set.', 'maz-catalog-image' ) . '</p>';
			}
			?>
		</div>
		<input type="hidden" id="maz-catalog-image-id" name="maz_catalog_image_id" value="<?php echo esc_attr( $id ? (string) $id : '' ); ?>" />
		<p>
			<a href="#" id="maz-catalog-image-set"
				data-set-text="<?php echo esc_attr__( 'Set catalogue image', 'maz-catalog-image' ); ?>"
				data-replace-text="<?php echo esc_attr__( 'Replace catalogue image', 'maz-catalog-image' ); ?>"
				data-empty-text="<?php echo esc_attr__( 'No catalogue image set.', 'maz-catalog-image' ); ?>"
				data-frame-title="<?php echo esc_attr__( 'Choose catalogue image', 'maz-catalog-image' ); ?>"
				data-frame-button="<?php echo esc_attr__( 'Use this image', 'maz-catalog-image' ); ?>">
				<?php echo $id ? esc_html__( 'Replace catalogue image', 'maz-catalog-image' ) : esc_html__( 'Set catalogue image', 'maz-catalog-image' ); ?>
			</a>
			<a href="#" id="maz-catalog-image-remove"<?php echo $id ? '' : ' style="display:none"'; ?>>
				<?php esc_html_e( 'Remove catalogue image', 'maz-catalog-image' ); ?>
			</a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Shown in the shop and category grids; the product page keeps the featured image.', 'maz-catalog-image' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Save handler for the meta box.
 *
 * @param int     $post_id Product ID.
 * @param WP_Post $post    Product post.
 */
function maz_catalog_image_save( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
		return;
	}

	if ( ! isset( $_POST['maz_catalog_image_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['maz_catalog_image_nonce'] ) ), 'maz_catalog_image_save' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_product', $post_id ) ) {
		return;
	}

	if ( ! isset( $_POST['maz_catalog_image_id'] ) ) {
		return;
	}

	$raw = trim( sanitize_text_field( wp_unslash( $_POST['maz_catalog_image_id'] ) ) );

	if ( '' === $raw || '0' === $raw ) {
		delete_post_meta( $post_id, MAZ_CATALOG_IMAGE_META_KEY );
		return;
	}

	$id = absint( $raw );

	if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
		return;
	}

	update_post_meta( $post_id, MAZ_CATALOG_IMAGE_META_KEY, $id );
}
add_action( 'save_post_product', 'maz_catalog_image_save', 10, 2 );

/**
 * Enqueue the media picker only on the product edit screens.
 *
 * @param string $hook_suffix Current admin page.
 */
function maz_catalog_image_enqueue_assets( $hook_suffix ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen || 'product' !== $screen->post_type ) {
		return;
	}

	wp_enqueue_media();

	wp_enqueue_script(
		'maz-catalog-image-admin',
		MAZ_CATALOG_IMAGE_URL . 'assets/admin.js',
		array( 'media-editor' ),
		MAZ_CATALOG_IMAGE_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'maz_catalog_image_enqueue_assets' );

/**
 * Add a read-only Catalogue image column to the products list table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function maz_catalog_image_list_column( $columns ) {
	$new = array();

	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;

		if ( 'thumb' === $key ) {
			$new['maz_catalog_image'] = __( 'Catalogue image', 'maz-catalog-image' );
		}
	}

	if ( ! isset( $new['maz_catalog_image'] ) ) {
		$new['maz_catalog_image'] = __( 'Catalogue image', 'maz-catalog-image' );
	}

	return $new;
}
add_filter( 'manage_product_posts_columns', 'maz_catalog_image_list_column' );

/**
 * Render the Catalogue image column: a 40px thumb or an em-dash.
 *
 * @param string $column  Column key.
 * @param int    $post_id Product ID.
 */
function maz_catalog_image_render_list_column( $column, $post_id ) {
	if ( 'maz_catalog_image' !== $column ) {
		return;
	}

	$id = absint( get_post_meta( $post_id, MAZ_CATALOG_IMAGE_META_KEY, true ) );

	if ( $id && wp_attachment_is_image( $id ) ) {
		echo wp_get_attachment_image( $id, array( 40, 40 ) );
	} else {
		echo '&mdash;';
	}
}
add_action( 'manage_product_posts_custom_column', 'maz_catalog_image_render_list_column', 10, 2 );
