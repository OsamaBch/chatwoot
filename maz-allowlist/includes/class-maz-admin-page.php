<?php
/**
 * Settings page (WooCommerce → Maz Allowlist).
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: rule configuration, allowlist import, seed reroll.
 */
class Maz_Allowlist_Admin_Page {

	const CAPABILITY = 'manage_options';
	const SLUG       = 'maz-allowlist';

	/**
	 * Wire hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_post_maz_save_config', array( __CLASS__, 'handle_save_config' ) );
		add_action( 'admin_post_maz_import_allowlist', array( __CLASS__, 'handle_import_allowlist' ) );
		add_action( 'admin_post_maz_clear_allowlist', array( __CLASS__, 'handle_clear_allowlist' ) );
		add_action( 'admin_post_maz_reroll_seed', array( __CLASS__, 'handle_reroll_seed' ) );
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Maz Allowlist', 'maz-allowlist' ),
			__( 'Maz Allowlist', 'maz-allowlist' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings page URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/* ---------------------------------------------------------------------
	 * Action handlers (admin-post.php)
	 * ------------------------------------------------------------------- */

	/**
	 * Common guard for all POST handlers.
	 *
	 * @param string $nonce_action Nonce action name.
	 */
	private static function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Maz Allowlist settings.', 'maz-allowlist' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Save rule configuration.
	 */
	public static function handle_save_config() {
		self::guard( 'maz_save_config' );

		$errors = array();
		$config = Maz_Allowlist_Config::sanitize( wp_unslash( $_POST ), $errors ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Maz_Allowlist_Config::update( $config, 'config_save' );

		foreach ( $errors as $error ) {
			self::add_message( $error, 'error' );
		}
		self::add_message( __( 'Settings saved.', 'maz-allowlist' ), 'success' );
		self::redirect_back();
	}

	/**
	 * Import allowlist IDs from pasted text and/or an uploaded CSV file.
	 */
	public static function handle_import_allowlist() {
		self::guard( 'maz_import_allowlist' );

		$text = isset( $_POST['maz_ids_text'] ) ? (string) wp_unslash( $_POST['maz_ids_text'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! empty( $_FILES['maz_ids_file']['tmp_name'] ) && is_uploaded_file( $_FILES['maz_ids_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$size = isset( $_FILES['maz_ids_file']['size'] ) ? (int) $_FILES['maz_ids_file']['size'] : 0;
			if ( $size > 10 * MB_IN_BYTES ) {
				self::add_message( __( 'Uploaded file is larger than 10 MB.', 'maz-allowlist' ), 'error' );
				self::redirect_back();
			}
			$text .= "\n" . (string) file_get_contents( $_FILES['maz_ids_file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		$bad_tokens = array();
		$ids        = Maz_Allowlist_Table::parse_ids( $text, $bad_tokens );

		if ( ! $ids && ! $bad_tokens ) {
			self::add_message( __( 'Nothing to import: paste IDs or choose a CSV file.', 'maz-allowlist' ), 'error' );
			self::redirect_back();
		}

		$result = $ids ? Maz_Allowlist_Table::add_ids( $ids ) : array(
			'added'       => 0,
			'duplicates'  => 0,
			'nonexistent' => Maz_Allowlist_Table::find_nonexistent(),
		);

		Maz_Allowlist_Audit_Log::record(
			'allowlist_import',
			sprintf(
				'imported %d IDs (%d new, %d already present, %d invalid tokens); list now has %d entries',
				count( $ids ),
				$result['added'],
				$result['duplicates'],
				count( $bad_tokens ),
				Maz_Allowlist_Table::count()
			)
		);
		do_action( 'maz_allowlist_config_changed', null, null ); // Allowlist content affects visibility everywhere.

		self::add_message(
			sprintf(
				/* translators: 1: new count 2: duplicate count 3: total count */
				__( 'Import finished: %1$d new IDs added, %2$d were already in the list. The allowlist now contains %3$d IDs.', 'maz-allowlist' ),
				$result['added'],
				$result['duplicates'],
				Maz_Allowlist_Table::count()
			),
			'success'
		);

		// Never silently drop anything: report both bad tokens and nonexistent IDs.
		if ( $bad_tokens ) {
			self::add_message(
				sprintf(
					/* translators: 1: count 2: token list */
					__( '%1$d tokens were not valid order IDs and were skipped: %2$s', 'maz-allowlist' ),
					count( $bad_tokens ),
					esc_html( self::truncate_list( $bad_tokens ) )
				),
				'warning'
			);
		}
		if ( $result['nonexistent'] ) {
			self::add_message(
				sprintf(
					/* translators: 1: count 2: ID list */
					__( '%1$d IDs in the allowlist do not match any existing order (they stay in the list but currently match nothing): %2$s', 'maz-allowlist' ),
					count( $result['nonexistent'] ),
					esc_html( self::truncate_list( $result['nonexistent'] ) )
				),
				'warning'
			);
		}

		self::redirect_back();
	}

	/**
	 * Clear the allowlist.
	 */
	public static function handle_clear_allowlist() {
		self::guard( 'maz_clear_allowlist' );

		$removed = Maz_Allowlist_Table::clear();
		Maz_Allowlist_Audit_Log::record( 'allowlist_clear', sprintf( 'cleared allowlist (%d entries removed)', $removed ) );
		do_action( 'maz_allowlist_config_changed', null, null );

		/* translators: %d: number of removed IDs */
		self::add_message( sprintf( __( 'Allowlist cleared (%d IDs removed).', 'maz-allowlist' ), $removed ), 'success' );
		self::redirect_back();
	}

	/**
	 * Reroll the sample seed.
	 */
	public static function handle_reroll_seed() {
		self::guard( 'maz_reroll_seed' );

		Maz_Allowlist_Config::reroll_seed();
		self::add_message( __( 'Sample seed rerolled. A different deterministic subset of orders is now visible.', 'maz-allowlist' ), 'success' );
		self::redirect_back();
	}

	/* ---------------------------------------------------------------------
	 * Feedback messages
	 * ------------------------------------------------------------------- */

	/**
	 * Queue a one-shot message for the current user.
	 *
	 * @param string $text Message text (unescaped).
	 * @param string $type success|error|warning|info.
	 */
	private static function add_message( $text, $type = 'info' ) {
		$key      = 'maz_allowlist_msgs_' . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! is_array( $messages ) ) {
			$messages = array();
		}
		$messages[] = array(
			'text' => $text,
			'type' => $type,
		);
		set_transient( $key, $messages, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Render + flush queued messages.
	 */
	private static function render_messages() {
		$key      = 'maz_allowlist_msgs_' . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! is_array( $messages ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $messages as $message ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( in_array( $message['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $message['type'] : 'info' ),
				wp_kses_post( $message['text'] )
			);
		}
	}

	/**
	 * Redirect back to the settings page and stop.
	 */
	private static function redirect_back() {
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * "1, 2, 3 … and N more" style list truncation.
	 *
	 * @param array $items Items.
	 * @param int   $max   Max shown.
	 * @return string
	 */
	private static function truncate_list( array $items, $max = 50 ) {
		$shown = array_slice( $items, 0, $max );
		$rest  = count( $items ) - count( $shown );
		$text  = implode( ', ', array_map( 'strval', $shown ) );
		if ( $rest > 0 ) {
			/* translators: %d: number of additional hidden items */
			$text .= ' ' . sprintf( __( '… and %d more', 'maz-allowlist' ), $rest );
		}
		return $text;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Render the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$config     = Maz_Allowlist_Config::get();
		$rules      = $config['rules'];
		$count      = Maz_Allowlist_Table::count();
		$currencies = Maz_Allowlist_Config::detect_currencies();
		$multi_cur  = count( $currencies ) > 1;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Maz Allowlist — Order Visibility', 'maz-allowlist' ); ?></h1>

			<?php self::render_messages(); ?>

			<?php if ( defined( 'MAZ_ALLOWLIST_DISABLE' ) && MAZ_ALLOWLIST_DISABLE ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Kill switch active: MAZ_ALLOWLIST_DISABLE is defined in wp-config.php — the plugin is fully disabled.', 'maz-allowlist' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Filters which WooCommerce orders are visible in the wp-admin orders list and in WooCommerce Analytics. Read-path only: order data is never modified. Users with the "maz_view_all_orders" capability (administrators by default) always see everything.', 'maz-allowlist' ); ?>
			</p>

			<?php if ( $multi_cur ) : ?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Multiple currencies detected in orders:', 'maz-allowlist' ); ?></strong>
					<?php echo esc_html( implode( ', ', $currencies ) ); ?> —
					<?php esc_html_e( 'a single numeric amount threshold is meaningless across currencies, so the amount rule cannot be enabled on this site.', 'maz-allowlist' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'maz_save_config' ); ?>
				<input type="hidden" name="action" value="maz_save_config" />

				<h2><?php esc_html_e( 'Rules', 'maz-allowlist' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '(a) Allowlist', 'maz-allowlist' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="allowlist_enabled" value="1" <?php checked( ! empty( $rules['allowlist']['enabled'] ) ); ?> />
								<?php
								printf(
									/* translators: %d: number of IDs currently in the allowlist */
									esc_html__( 'Only orders whose ID is in the allowlist are visible. The list currently contains %d IDs (managed below).', 'maz-allowlist' ),
									(int) $count
								);
								?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '(b) Random sample', 'maz-allowlist' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sample_enabled" value="1" <?php checked( ! empty( $rules['sample']['enabled'] ) ); ?> />
								<?php esc_html_e( 'Show a deterministic random sample of orders.', 'maz-allowlist' ); ?>
							</label>
							<p>
								<label>
									<?php esc_html_e( 'Visible percentage:', 'maz-allowlist' ); ?>
									<input type="number" name="sample_visible_pct" min="0" max="100" step="1" value="<?php echo esc_attr( (int) $rules['sample']['visible_pct'] ); ?>" style="width:5em" /> %
								</label>
								<span class="description"><?php esc_html_e( '(default 10% visible / 90% hidden)', 'maz-allowlist' ); ?></span>
							</p>
							<p class="description">
								<?php esc_html_e( 'The sample is seeded, never re-randomized at query time, so pagination and report totals are stable. Current seed:', 'maz-allowlist' ); ?>
								<code><?php echo esc_html( $rules['sample']['seed'] ); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '(c) Amount threshold', 'maz-allowlist' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="amount_enabled" value="1" <?php checked( ! empty( $rules['amount']['enabled'] ) ); ?> <?php disabled( $multi_cur ); ?> />
								<?php esc_html_e( 'Hide orders by total amount.', 'maz-allowlist' ); ?>
							</label>
							<p>
								<select name="amount_mode">
									<option value="above" <?php selected( 'above', $rules['amount']['mode'] ); ?>><?php esc_html_e( 'Hide orders ABOVE X', 'maz-allowlist' ); ?></option>
									<option value="below" <?php selected( 'below', $rules['amount']['mode'] ); ?>><?php esc_html_e( 'Hide orders BELOW X', 'maz-allowlist' ); ?></option>
									<option value="band" <?php selected( 'band', $rules['amount']['mode'] ); ?>><?php esc_html_e( 'Hide orders WITHIN band X–Y', 'maz-allowlist' ); ?></option>
								</select>
								<label> X: <input type="text" name="amount_x" value="<?php echo esc_attr( $rules['amount']['x'] ); ?>" style="width:8em" inputmode="decimal" /></label>
								<label> Y: <input type="text" name="amount_y" value="<?php echo esc_attr( $rules['amount']['y'] ); ?>" style="width:8em" inputmode="decimal" /> <span class="description"><?php esc_html_e( '(band mode only)', 'maz-allowlist' ); ?></span></label>
							</p>
							<p>
								<label>
									<input type="radio" name="amount_basis" value="net" <?php checked( 'net', $rules['amount']['basis'] ); ?> />
									<?php esc_html_e( 'NET total (excluding shipping and tax) — default', 'maz-allowlist' ); ?>
								</label><br />
								<label>
									<input type="radio" name="amount_basis" value="gross" <?php checked( 'gross', $rules['amount']['basis'] ); ?> />
									<?php esc_html_e( 'GROSS total (as shown on the order)', 'maz-allowlist' ); ?>
								</label>
							</p>
							<p class="description"><?php esc_html_e( 'The same basis is applied in the orders list AND in Analytics, so the two can never disagree.', 'maz-allowlist' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rule precedence', 'maz-allowlist' ); ?></th>
						<td>
							<label>
								<input type="radio" name="precedence" value="and" <?php checked( 'and', $config['precedence'] ); ?> />
								<strong>AND</strong> — <?php esc_html_e( 'strict: an order must satisfy EVERY enabled rule to be shown. Any single enabled rule can hide an order.', 'maz-allowlist' ); ?>
							</label><br />
							<label>
								<input type="radio" name="precedence" value="or" <?php checked( 'or', $config['precedence'] ); ?> />
								<strong>OR</strong> — <?php esc_html_e( 'lenient: an order is shown if it satisfies AT LEAST ONE enabled rule. It is hidden only when every enabled rule would hide it.', 'maz-allowlist' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Example with allowlist + 10% sample enabled: AND shows only allowlisted orders that also fall in the 10% sample; OR shows all allowlisted orders plus the 10% sample of everything else.', 'maz-allowlist' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="description">
					<?php esc_html_e( 'Note: when the orders-list search box is used, ALL rules are bypassed for that search so any specific order can always be found. Searching does not affect Analytics.', 'maz-allowlist' ); ?>
				</p>

				<?php submit_button( __( 'Save settings', 'maz-allowlist' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Allowlist management', 'maz-allowlist' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %d: number of IDs */
					esc_html__( 'Current allowlist size: %d order IDs.', 'maz-allowlist' ),
					(int) $count
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'maz_import_allowlist' ); ?>
				<input type="hidden" name="action" value="maz_import_allowlist" />
				<p>
					<label for="maz_ids_text"><?php esc_html_e( 'Paste order IDs (newline-, comma-, or space-separated; a leading # is tolerated):', 'maz-allowlist' ); ?></label><br />
					<textarea id="maz_ids_text" name="maz_ids_text" rows="6" cols="60" placeholder="123&#10;456, 789 1011"></textarea>
				</p>
				<p>
					<label for="maz_ids_file"><?php esc_html_e( 'Or upload a CSV / text file:', 'maz-allowlist' ); ?></label>
					<input type="file" id="maz_ids_file" name="maz_ids_file" accept=".csv,.txt,text/csv,text/plain" />
				</p>
				<p class="description"><?php esc_html_e( 'IDs are added to the existing list (duplicates are ignored). Invalid tokens and IDs that match no existing order are reported back, never silently dropped.', 'maz-allowlist' ); ?></p>
				<?php submit_button( __( 'Import IDs', 'maz-allowlist' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em"
				onsubmit="return confirm('<?php echo esc_js( __( 'Remove ALL IDs from the allowlist?', 'maz-allowlist' ) ); ?>');">
				<?php wp_nonce_field( 'maz_clear_allowlist' ); ?>
				<input type="hidden" name="action" value="maz_clear_allowlist" />
				<?php submit_button( __( 'Clear all', 'maz-allowlist' ), 'delete', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Sample seed', 'maz-allowlist' ); ?></h2>
			<p>
				<?php esc_html_e( 'Current seed:', 'maz-allowlist' ); ?> <code><?php echo esc_html( $rules['sample']['seed'] ); ?></code>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php echo esc_js( __( 'Reroll the seed? A different subset of orders will become visible everywhere (list + Analytics).', 'maz-allowlist' ) ); ?>');">
				<?php wp_nonce_field( 'maz_reroll_seed' ); ?>
				<input type="hidden" name="action" value="maz_reroll_seed" />
				<?php submit_button( __( 'Reroll seed', 'maz-allowlist' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php
			/**
			 * Extension point for later sections (dry-run preview, audit log).
			 */
			do_action( 'maz_allowlist_settings_sections', $config );
			?>
		</div>
		<?php
	}
}
