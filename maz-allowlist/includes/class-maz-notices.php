<?php
/**
 * Persistent "this view is filtered" admin notice.
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whenever at least one rule is active, the Orders and Analytics screens
 * announce that the dataset is filtered and which rules are on. Dismissible
 * per user session (12 h); reappears whenever the configuration changes.
 */
class Maz_Allowlist_Notices {

	const USER_META = 'maz_allowlist_notice_dismissed';
	const TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * Wire hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_post_maz_dismiss_notice', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Is the current screen one of the filtered surfaces?
	 *
	 * @return bool
	 */
	private static function is_relevant_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// HPOS orders list / legacy orders list.
		if ( in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return true;
		}

		// WooCommerce Analytics (React app under the wc-admin page).
		if ( 'woocommerce_page_wc-admin' === $screen->id ) {
			$path = isset( $_GET['path'] ) ? (string) wp_unslash( $_GET['path'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '' === $path || 0 === strpos( $path, '/analytics' );
		}

		// Legacy WooCommerce → Reports.
		if ( 'woocommerce_page_wc-reports' === $screen->id ) {
			return true;
		}

		return false;
	}

	/**
	 * Render the notice when appropriate.
	 */
	public static function maybe_render() {
		if ( ! Maz_Allowlist_Config::any_rule_active() || ! self::is_relevant_screen() ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Dismissed recently for this exact configuration?
		$fingerprint = Maz_Allowlist_Config::fingerprint();
		$dismissed   = get_user_meta( get_current_user_id(), self::USER_META, true );
		if ( is_array( $dismissed )
			&& isset( $dismissed['fingerprint'], $dismissed['time'] )
			&& $dismissed['fingerprint'] === $fingerprint
			&& ( time() - (int) $dismissed['time'] ) < self::TTL ) {
			return;
		}

		$config = Maz_Allowlist_Config::get();
		$active = array();
		if ( ! empty( $config['rules']['allowlist']['enabled'] ) ) {
			/* translators: %d: allowlist size */
			$active[] = sprintf( __( 'allowlist (%d IDs)', 'maz-allowlist' ), Maz_Allowlist_Table::count() );
		}
		if ( ! empty( $config['rules']['sample']['enabled'] ) ) {
			/* translators: %d: visible percentage */
			$active[] = sprintf( __( 'random sample (%d%% visible)', 'maz-allowlist' ), (int) $config['rules']['sample']['visible_pct'] );
		}
		if ( ! empty( $config['rules']['amount']['enabled'] ) ) {
			$amount = $config['rules']['amount'];
			$bounds = ( 'band' === $amount['mode'] ) ? $amount['x'] . '–' . $amount['y'] : $amount['x'];
			$active[] = sprintf( 'amount threshold (hide %s %s, %s basis)', $amount['mode'], $bounds, $amount['basis'] );
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'maz_dismiss_notice',
					'redirect' => rawurlencode( self::current_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			'maz_dismiss_notice'
		);

		$bypassing = maz_allowlist_user_can_bypass();
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Maz Allowlist:', 'maz-allowlist' ); ?></strong>
				<?php if ( $bypassing ) : ?>
					<?php esc_html_e( 'order visibility rules are ACTIVE for users without the bypass capability — you hold "maz_view_all_orders", so YOU are seeing the complete, unfiltered data.', 'maz-allowlist' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'this view is FILTERED — it does not show all orders, and totals reflect only the visible subset.', 'maz-allowlist' ); ?>
				<?php endif; ?>
				<?php esc_html_e( 'Active rules:', 'maz-allowlist' ); ?>
				<?php echo esc_html( implode( ' · ', $active ) ); ?>
				(<?php echo esc_html( 'or' === $config['precedence'] ? __( 'OR precedence: hidden only if every rule hides it', 'maz-allowlist' ) : __( 'AND precedence: any rule can hide an order', 'maz-allowlist' ) ); ?>).
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss for this session', 'maz-allowlist' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Record dismissal for the current config fingerprint.
	 */
	public static function handle_dismiss() {
		check_admin_referer( 'maz_dismiss_notice' );
		update_user_meta(
			get_current_user_id(),
			self::USER_META,
			array(
				'fingerprint' => Maz_Allowlist_Config::fingerprint(),
				'time'        => time(),
			)
		);
		$redirect = isset( $_GET['redirect'] ) ? rawurldecode( (string) wp_unslash( $_GET['redirect'] ) ) : '';
		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}

	/**
	 * Current admin URL (for the dismiss round-trip).
	 *
	 * @return string
	 */
	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return esc_url_raw( home_url( $uri ) );
	}
}
