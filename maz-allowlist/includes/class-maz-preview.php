<?php
/**
 * Dry-run preview: report what a configuration WOULD hide, without saving.
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings-page "Preview" button. Counts are computed with a single grouped
 * SQL query over the order store using the same clause builder as every
 * integration point — never by looping orders in PHP (196k+ rows).
 */
class Maz_Allowlist_Preview {

	/**
	 * Wire hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_maz_preview', array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'maz_allowlist_settings_sections', array( __CLASS__, 'render_section' ) );
	}

	/**
	 * Enqueue the settings-page script.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( $hook_suffix ) {
		$page_hooks = array(
			'tools_page_' . Maz_Allowlist_Admin_Page::SLUG,
			'woocommerce_page_' . Maz_Allowlist_Admin_Page::SLUG,
		);
		if ( ! in_array( $hook_suffix, $page_hooks, true ) ) {
			return;
		}
		wp_enqueue_script(
			'maz-allowlist-admin',
			MAZ_ALLOWLIST_URL . 'assets/admin.js',
			array(),
			MAZ_ALLOWLIST_VERSION,
			true
		);
		wp_localize_script(
			'maz-allowlist-admin',
			'MazAllowlist',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'maz_preview' ),
				'error'   => __( 'Preview failed — see the browser console for details.', 'maz-allowlist' ),
			)
		);
	}

	/**
	 * Render the preview section on the settings page.
	 */
	public static function render_section() {
		?>
		<hr />
		<h2><?php esc_html_e( 'Dry-run preview', 'maz-allowlist' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Evaluates the settings currently in the form above (NOT the saved settings) against all existing orders, without saving anything. Per-rule numbers count each rule alone, so they can overlap.', 'maz-allowlist' ); ?></p>
		<p><button type="button" class="button" id="maz-preview-btn"><?php esc_html_e( 'Preview this configuration', 'maz-allowlist' ); ?></button></p>
		<div id="maz-preview-result" style="max-width:640px"></div>
		<?php
	}

	/**
	 * AJAX handler: sanitize the posted (unsaved) form config and count.
	 */
	public static function handle() {
		if ( ! current_user_can( Maz_Allowlist_Admin_Page::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'maz_preview', 'maz_preview_nonce' );

		$errors = array();
		$config = Maz_Allowlist_Config::sanitize( wp_unslash( $_POST ), $errors ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		wp_send_json_success( array_merge( self::counts( $config ), array( 'warnings' => $errors ) ) );
	}

	/**
	 * Compute totals in one SQL pass over the authoritative order store.
	 *
	 * @param array $config Sanitized (unsaved) config.
	 * @return array{total:int, visible:int, hidden:int, per_rule:array<string,int>}
	 */
	public static function counts( array $config ) {
		global $wpdb;

		$hpos = maz_allowlist_hpos_enabled();
		$ctx  = $hpos ? 'hpos_list' : 'legacy_list';
		if ( $hpos ) {
			$base_from  = "{$wpdb->prefix}wc_orders";
			$base_where = "{$base_from}.type = 'shop_order' AND {$base_from}.status NOT IN ('trash','auto-draft','wc-checkout-draft')";
		} else {
			$base_from  = $wpdb->posts;
			$base_where = "{$base_from}.post_type = 'shop_order' AND {$base_from}.post_status NOT IN ('trash','auto-draft')";
		}

		$selects = array( 'COUNT(*) AS total' );
		$joins   = array();

		$combined = maz_visibility_sql_clause( $ctx, $config );
		if ( '' !== $combined['where'] ) {
			$joins     = $combined['join'];
			$selects[] = "COALESCE(SUM(CASE WHEN {$combined['where']} THEN 1 ELSE 0 END), 0) AS visible";
		}

		// Per-rule counts: each rule evaluated alone via the same builder.
		foreach ( array( 'allowlist', 'sample', 'amount' ) as $rule ) {
			if ( empty( $config['rules'][ $rule ]['enabled'] ) ) {
				continue;
			}
			$solo = $config;
			foreach ( array_keys( $solo['rules'] ) as $other ) {
				$solo['rules'][ $other ]['enabled'] = ( $other === $rule );
			}
			$fragment = maz_visibility_sql_clause( $ctx, $solo );
			if ( '' === $fragment['where'] ) {
				continue;
			}
			$joins     = array_merge( $joins, $fragment['join'] );
			$selects[] = "COALESCE(SUM(CASE WHEN NOT {$fragment['where']} THEN 1 ELSE 0 END), 0) AS hidden_{$rule}";
		}

		$sql = 'SELECT ' . implode( ', ', $selects )
			. " FROM {$base_from} " . implode( ' ', array_unique( $joins ) )
			. " WHERE {$base_where}";

		$row   = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = isset( $row['total'] ) ? (int) $row['total'] : 0;
		$vis   = isset( $row['visible'] ) ? (int) $row['visible'] : $total;

		return array(
			'total'    => $total,
			'visible'  => $vis,
			'hidden'   => $total - $vis,
			'per_rule' => array(
				'allowlist' => isset( $row['hidden_allowlist'] ) ? (int) $row['hidden_allowlist'] : null,
				'sample'    => isset( $row['hidden_sample'] ) ? (int) $row['hidden_sample'] : null,
				'amount'    => isset( $row['hidden_amount'] ) ? (int) $row['hidden_amount'] : null,
			),
		);
	}
}
