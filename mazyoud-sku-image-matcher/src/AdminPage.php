<?php
/**
 * WooCommerce → SKU Image Matcher admin screen + AJAX endpoints.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the admin page (settings, scan, progress, run history) and
 * handles its AJAX endpoints.
 *
 * Security: every endpoint checks the msim_admin nonce AND
 * current_user_can('manage_woocommerce'). Assets are enqueued only on this
 * screen, with filemtime cache busting.
 */
class AdminPage {

	private const PAGE_SLUG = 'msim';
	private const NONCE     = 'msim_admin';

	/**
	 * Hook suffix returned by add_submenu_page (screen check for assets).
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings  Settings.
	 * @param MatchPlanner       $planner   Planner.
	 * @param RunRepository      $runs      Run repository.
	 * @param SnapshotRepository $snapshots Snapshot repository.
	 * @param Queue              $queue     Queue.
	 * @param Logger             $logger    Logger.
	 */
	public function __construct(
		private Settings $settings,
		private MatchPlanner $planner,
		private RunRepository $runs,
		private SnapshotRepository $snapshots,
		private Queue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Register all admin hooks (menu, settings, assets, AJAX).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_filter(
			'option_page_capability_msim_settings',
			static function () {
				return 'manage_woocommerce';
			}
		);

		add_action( 'wp_ajax_msim_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_msim_run', array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_msim_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_msim_report', array( $this, 'ajax_report' ) );
		add_action( 'wp_ajax_msim_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_msim_recover', array( $this, 'ajax_recover' ) );
		add_action( 'wp_ajax_msim_rollback', array( $this, 'ajax_rollback' ) );
		add_action( 'wp_ajax_msim_rollback_force', array( $this, 'ajax_rollback_force' ) );
		add_action( 'wp_ajax_msim_export', array( $this, 'ajax_export' ) );
	}

	/**
	 * Add the submenu page under WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'SKU Image Matcher', 'mazyoud-sku-image-matcher' ),
			__( 'SKU Image Matcher', 'mazyoud-sku-image-matcher' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings group used by the options.php form.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'msim_settings',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Enqueue the plugin's one JS and one CSS file — only on its own screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		$css = MSIM_DIR . 'assets/admin.css';
		$js  = MSIM_DIR . 'assets/admin.js';

		wp_enqueue_style(
			'msim-admin',
			MSIM_URL . 'assets/admin.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : MSIM_VERSION
		);

		wp_enqueue_script(
			'msim-admin',
			MSIM_URL . 'assets/admin.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : MSIM_VERSION,
			true
		);

		$planned = $this->latest_run_with_status( RunRepository::STATUS_PLANNED );
		$active  = $this->runs->active_run_id();

		wp_add_inline_script(
			'msim-admin',
			'window.msimData = ' . wp_json_encode(
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( self::NONCE ),
					'plannedRunId' => $planned ? (int) $planned['id'] : 0,
					'activeRunId'  => (int) $active,
					'i18n'         => array(
						'scanning'       => __( 'Scanning media library…', 'mazyoud-sku-image-matcher' ),
						'confirmRun'     => __( 'Apply this plan? Products will be modified in the background.', 'mazyoud-sku-image-matcher' ),
						'confirmCancel'  => __( 'Cancel the active run? Already-processed products keep their changes (use Rollback to revert them).', 'mazyoud-sku-image-matcher' ),
						'confirmRollback' => __( 'Roll back this run? Every product it modified will be restored to its previous images.', 'mazyoud-sku-image-matcher' ),
						'confirmForce'   => __( 'Some products were manually edited after the run. Force-restoring will overwrite those manual edits. Continue?', 'mazyoud-sku-image-matcher' ),
						'error'          => __( 'Request failed. Please try again.', 'mazyoud-sku-image-matcher' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mazyoud-sku-image-matcher' ) );
		}

		Plugin::maybe_upgrade();

		$settings = $this->settings->all();
		$active   = $this->runs->active_run_id();
		?>
		<div class="wrap msim-wrap">
			<h1><?php esc_html_e( 'SKU Image Matcher', 'mazyoud-sku-image-matcher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Attaches recently uploaded Media Library images to WooCommerce products by parsing the SKU (or product ID) from the filename. Always scan first — the scan is a dry run and writes nothing.', 'mazyoud-sku-image-matcher' ); ?>
			</p>

			<?php settings_errors(); ?>

			<div class="msim-card">
				<h2><?php esc_html_e( 'Settings', 'mazyoud-sku-image-matcher' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php settings_fields( 'msim_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Products that already have images', 'mazyoud-sku-image-matcher' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[existing_mode]" value="skip" <?php checked( $settings['existing_mode'], Settings::MODE_SKIP ); ?> />
										<?php esc_html_e( 'Skip — never touch products that already have a featured image or gallery', 'mazyoud-sku-image-matcher' ); ?>
									</label><br/>
									<label>
										<input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[existing_mode]" value="replace" <?php checked( $settings['existing_mode'], Settings::MODE_REPLACE ); ?> />
										<?php esc_html_e( 'Replace all — overwrite the featured image and gallery (old attachments are detached, never deleted)', 'mazyoud-sku-image-matcher' ); ?>
									</label><br/>
									<label>
										<input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[existing_mode]" value="merge" <?php checked( $settings['existing_mode'], Settings::MODE_MERGE ); ?> />
										<?php esc_html_e( 'Merge — keep existing images, append new ones to the gallery, never duplicate an attachment', 'mazyoud-sku-image-matcher' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Media to consider', 'mazyoud-sku-image-matcher' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[only_unattached]" value="1" <?php checked( $settings['only_unattached'] ); ?> />
									<?php esc_html_e( 'Only unattached media (not yet attached to any post)', 'mazyoud-sku-image-matcher' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Uploaded since', 'mazyoud-sku-image-matcher' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( Settings::OPTION ); ?>[uploaded_since]">
									<option value="today" <?php selected( $settings['uploaded_since'], Settings::SINCE_TODAY ); ?>><?php esc_html_e( 'Today', 'mazyoud-sku-image-matcher' ); ?></option>
									<option value="7days" <?php selected( $settings['uploaded_since'], Settings::SINCE_7_DAYS ); ?>><?php esc_html_e( 'Last 7 days', 'mazyoud-sku-image-matcher' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Safety constraint: the scan never looks back more than 7 days (site timezone), so it can only ever touch recently uploaded media.', 'mazyoud-sku-image-matcher' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-attach on upload', 'mazyoud-sku-image-matcher' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[auto_attach]" value="1" <?php checked( $settings['auto_attach'] ); ?> />
									<?php esc_html_e( 'Attach matching images immediately when they are uploaded (logged, no undo snapshot)', 'mazyoud-sku-image-matcher' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save settings', 'mazyoud-sku-image-matcher' ) ); ?>
				</form>
			</div>

			<div class="msim-card">
				<h2><?php esc_html_e( 'Step 1 — Scan (dry run)', 'mazyoud-sku-image-matcher' ); ?></h2>
				<p><?php esc_html_e( 'Builds the full match plan without writing anything, then shows what would happen.', 'mazyoud-sku-image-matcher' ); ?></p>
				<p>
					<button type="button" class="button button-primary" id="msim-scan-btn"><?php esc_html_e( 'Scan media library', 'mazyoud-sku-image-matcher' ); ?></button>
					<span class="spinner" id="msim-scan-spinner"></span>
				</p>
				<div id="msim-report" class="msim-report" hidden></div>
			</div>

			<div class="msim-card">
				<h2><?php esc_html_e( 'Step 2 — Run', 'mazyoud-sku-image-matcher' ); ?></h2>
				<p><?php esc_html_e( 'Applies the plan from the last scan as background jobs (Action Scheduler). You can close this tab — processing continues on the server.', 'mazyoud-sku-image-matcher' ); ?></p>
				<p>
					<button type="button" class="button button-primary" id="msim-run-btn" <?php disabled( true ); ?>><?php esc_html_e( 'Run — assign images', 'mazyoud-sku-image-matcher' ); ?></button>
					<button type="button" class="button" id="msim-cancel-btn" <?php echo $active ? '' : 'hidden'; ?>><?php esc_html_e( 'Cancel active run', 'mazyoud-sku-image-matcher' ); ?></button>
				</p>
				<div id="msim-progress" class="msim-progress" hidden>
					<div class="msim-progress-bar"><div class="msim-progress-fill" style="width:0%"></div></div>
					<p class="msim-progress-text"></p>
				</div>
			</div>

			<div class="msim-card">
				<h2><?php esc_html_e( 'Run history & rollback', 'mazyoud-sku-image-matcher' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The last 10 runs are kept. Rolling back restores every product the run modified to its exact prior state (featured image, gallery, attachment parents).', 'mazyoud-sku-image-matcher' ); ?></p>
				<?php $this->render_history_table(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the run history table (server-side).
	 *
	 * @return void
	 */
	private function render_history_table() {
		$runs = $this->runs->all();

		if ( empty( $runs ) ) {
			echo '<p>' . esc_html__( 'No runs yet.', 'mazyoud-sku-image-matcher' ) . '</p>';

			return;
		}

		$labels = array(
			RunRepository::STATUS_PLANNED                    => __( 'Planned (not executed)', 'mazyoud-sku-image-matcher' ),
			RunRepository::STATUS_RUNNING                    => __( 'Running', 'mazyoud-sku-image-matcher' ),
			RunRepository::STATUS_COMPLETED                  => __( 'Completed', 'mazyoud-sku-image-matcher' ),
			RunRepository::STATUS_COMPLETED_WITH_ERRORS      => __( 'Completed with errors', 'mazyoud-sku-image-matcher' ),
			RunRepository::STATUS_CANCELLED                  => __( 'Cancelled', 'mazyoud-sku-image-matcher' ),
			RunRepository::STATUS_ROLLING_BACK               => __( 'Rolling back', 'mazyoud-sku-image-matcher' ),
			RunRepository::STATUS_ROLLED_BACK                => __( 'Rolled back', 'mazyoud-sku-image-matcher' ),
			RunRepository::STATUS_ROLLED_BACK_WITH_CONFLICTS => __( 'Rolled back (conflicts remain)', 'mazyoud-sku-image-matcher' ),
		);
		?>
		<table class="widefat striped msim-history">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Run', 'mazyoud-sku-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Date', 'mazyoud-sku-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Mode', 'mazyoud-sku-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Products', 'mazyoud-sku-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Results', 'mazyoud-sku-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mazyoud-sku-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'mazyoud-sku-image-matcher' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $runs as $run ) : ?>
				<?php
				$run_id  = (int) $run['id'];
				$status  = (string) $run['status'];
				$results = isset( $run['results'] ) && is_array( $run['results'] ) ? $run['results'] : array();

				$done      = isset( $results[ SnapshotRepository::STATUS_DONE ] ) ? (int) $results[ SnapshotRepository::STATUS_DONE ] : 0;
				$failed    = isset( $results[ SnapshotRepository::STATUS_FAILED ] ) ? (int) $results[ SnapshotRepository::STATUS_FAILED ] : 0;
				$skipped   = isset( $results[ SnapshotRepository::STATUS_SKIPPED ] ) ? (int) $results[ SnapshotRepository::STATUS_SKIPPED ] : 0;
				$conflicts = isset( $results[ SnapshotRepository::STATUS_ROLLBACK_CONFLICT ] ) ? (int) $results[ SnapshotRepository::STATUS_ROLLBACK_CONFLICT ] : 0;
				?>
				<tr>
					<td>#<?php echo esc_html( (string) $run_id ); ?></td>
					<td><?php echo esc_html( (string) $run['created'] ); ?></td>
					<td><?php echo esc_html( (string) $run['mode'] ); ?></td>
					<td><?php echo esc_html( (string) (int) $run['total_products'] ); ?></td>
					<td>
						<?php
						if ( ! empty( $results ) ) {
							printf(
								/* translators: 1: done count, 2: failed count, 3: skipped count. */
								esc_html__( '%1$d done, %2$d failed, %3$d skipped', 'mazyoud-sku-image-matcher' ),
								(int) $done,
								(int) $failed,
								(int) $skipped
							);
							if ( $conflicts > 0 ) {
								printf( ', %s', sprintf( /* translators: %d: conflict count. */ esc_html__( '%d rollback conflicts', 'mazyoud-sku-image-matcher' ), (int) $conflicts ) );
							}
						} else {
							echo '&mdash;';
						}
						?>
					</td>
					<td><span class="msim-badge msim-badge-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ); ?></span></td>
					<td class="msim-actions">
						<?php if ( in_array( $status, array( RunRepository::STATUS_COMPLETED, RunRepository::STATUS_COMPLETED_WITH_ERRORS, RunRepository::STATUS_CANCELLED ), true ) ) : ?>
							<button type="button" class="button button-small msim-rollback-btn" data-run="<?php echo esc_attr( (string) $run_id ); ?>"><?php esc_html_e( 'Rollback this run', 'mazyoud-sku-image-matcher' ); ?></button>
						<?php endif; ?>
						<?php if ( RunRepository::STATUS_ROLLED_BACK_WITH_CONFLICTS === $status ) : ?>
							<button type="button" class="button button-small msim-force-rollback-btn" data-run="<?php echo esc_attr( (string) $run_id ); ?>"><?php esc_html_e( 'Force-restore conflicted products', 'mazyoud-sku-image-matcher' ); ?></button>
						<?php endif; ?>
						<?php if ( in_array( $status, array( RunRepository::STATUS_RUNNING, RunRepository::STATUS_ROLLING_BACK ), true ) ) : ?>
							<button type="button" class="button button-small msim-recover-btn" data-run="<?php echo esc_attr( (string) $run_id ); ?>"><?php esc_html_e( 'Recover (re-enqueue)', 'mazyoud-sku-image-matcher' ); ?></button>
						<?php endif; ?>
						<?php if ( $failed > 0 ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $this->export_url( $run_id, 'failures' ) ); ?>"><?php esc_html_e( 'Failures CSV', 'mazyoud-sku-image-matcher' ); ?></a>
						<?php endif; ?>
						<?php if ( 'replace' === (string) $run['mode'] && $done > 0 ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $this->export_url( $run_id, 'detached' ) ); ?>"><?php esc_html_e( 'Detached attachments CSV', 'mazyoud-sku-image-matcher' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Authorize an AJAX request: nonce + capability. Dies on failure.
	 *
	 * @return void
	 */
	private function authorize() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mazyoud-sku-image-matcher' ) ), 403 );
		}
	}

	/**
	 * AJAX: run the dry-run scan and return the report.
	 *
	 * @return void
	 */
	public function ajax_scan() {
		$this->authorize();
		Plugin::maybe_upgrade();

		try {
			$result = $this->planner->scan( $this->runs );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Scan failed.', array( 'error' => $e->getMessage() ) );
			wp_send_json_error( array( 'message' => __( 'Scan failed: ', 'mazyoud-sku-image-matcher' ) . $e->getMessage() ), 500 );
		}

		wp_send_json_success(
			array(
				'run_id'     => (int) $result['run_id'],
				'report'     => $this->report_for_js( (int) $result['run_id'], $result['report'] ),
				'can_run'    => $result['report']['counts']['matched_products'] > 0 && 0 === $this->runs->active_run_id(),
				'lock_held'  => $this->runs->active_run_id(),
			)
		);
	}

	/**
	 * AJAX: return the stored report of a run (page reload re-render).
	 *
	 * @return void
	 */
	public function ajax_report() {
		$this->authorize();

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$plan   = $this->runs->get_plan( $run_id );

		if ( ! $plan || empty( $plan['report'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No stored report for this run.', 'mazyoud-sku-image-matcher' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'run_id'  => $run_id,
				'report'  => $this->report_for_js( $run_id, $plan['report'] ),
				'can_run' => isset( $plan['products'] ) && count( $plan['products'] ) > 0 && 0 === $this->runs->active_run_id(),
			)
		);
	}

	/**
	 * AJAX: start the run for the current plan.
	 *
	 * @return void
	 */
	public function ajax_run() {
		$this->authorize();

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$result = $this->queue->start_run( $run_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		wp_send_json_success( array( 'run_id' => $run_id ) );
	}

	/**
	 * AJAX: lightweight progress summary (polled every ~3s while running).
	 * One indexed GROUP BY query against the snapshots table.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$this->authorize();

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		if ( ! $run_id ) {
			$run_id = $this->runs->active_run_id();
		}

		$run = $this->runs->get( $run_id );
		if ( ! $run ) {
			wp_send_json_error( array( 'message' => __( 'Unknown run.', 'mazyoud-sku-image-matcher' ) ), 404 );
		}

		$counts = $this->snapshots->count_by_status( $run_id );
		$get    = static function ( $key ) use ( $counts ) {
			return isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
		};

		$total       = (int) $run['total_products'];
		$done        = $get( SnapshotRepository::STATUS_DONE );
		$failed      = $get( SnapshotRepository::STATUS_FAILED );
		$skipped     = $get( SnapshotRepository::STATUS_SKIPPED );
		$rolled_back = $get( SnapshotRepository::STATUS_ROLLED_BACK );
		$conflicts   = $get( SnapshotRepository::STATUS_ROLLBACK_CONFLICT );
		$processed   = $done + $failed + $skipped + $rolled_back + $conflicts;

		$is_active = in_array( $run['status'], array( RunRepository::STATUS_RUNNING, RunRepository::STATUS_ROLLING_BACK ), true );
		$stalled   = $is_active && ! $this->queue->has_pending_actions();

		wp_send_json_success(
			array(
				'run_id'      => $run_id,
				'status'      => (string) $run['status'],
				'total'       => $total,
				'processed'   => $processed,
				'done'        => $done,
				'failed'      => $failed,
				'skipped'     => $skipped,
				'rolled_back' => $rolled_back,
				'conflicts'   => $conflicts,
				'percent'     => $total > 0 ? (int) floor( ( min( $processed, $total ) / $total ) * 100 ) : 100,
				'active'      => $is_active,
				'stalled'     => $stalled,
			)
		);
	}

	/**
	 * AJAX: cancel the active run.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$this->authorize();

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : $this->runs->active_run_id();

		if ( ! $this->queue->cancel_run( $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No active run to cancel.', 'mazyoud-sku-image-matcher' ) ), 409 );
		}

		wp_send_json_success( array( 'run_id' => $run_id ) );
	}

	/**
	 * AJAX: recover a stalled run by re-enqueueing its batches (idempotent).
	 *
	 * @return void
	 */
	public function ajax_recover() {
		$this->authorize();

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;

		if ( ! $this->queue->recover_run( $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This run cannot be recovered.', 'mazyoud-sku-image-matcher' ) ), 409 );
		}

		wp_send_json_success( array( 'run_id' => $run_id ) );
	}

	/**
	 * AJAX: start a rollback.
	 *
	 * @return void
	 */
	public function ajax_rollback() {
		$this->authorize();

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$result = $this->queue->start_rollback( $run_id, false );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		wp_send_json_success( array( 'run_id' => $run_id ) );
	}

	/**
	 * AJAX: force-restore products flagged as rollback conflicts (explicit
	 * confirmation already collected client-side).
	 *
	 * @return void
	 */
	public function ajax_rollback_force() {
		$this->authorize();

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$result = $this->queue->start_rollback( $run_id, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		wp_send_json_success( array( 'run_id' => $run_id ) );
	}

	/**
	 * AJAX (GET): stream a CSV export of a report section, failures or
	 * detached attachments. Uses php://output only — no filesystem writes.
	 *
	 * @return void
	 */
	public function ajax_export() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mazyoud-sku-image-matcher' ), '', array( 'response' => 403 ) );
		}

		$run_id = isset( $_GET['run_id'] ) ? absint( wp_unslash( $_GET['run_id'] ) ) : 0;
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';

		$rows = $this->build_csv_rows( $run_id, $type );
		if ( null === $rows ) {
			wp_die( esc_html__( 'Nothing to export.', 'mazyoud-sku-image-matcher' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="msim-run-' . $run_id . '-' . $type . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/**
	 * Build CSV rows for an export type.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $type   not_found|variation|duplicate_sku|duplicate_rename|warnings|skipped_existing|failures|detached.
	 * @return array[]|null Rows (first row = header) or null when unavailable.
	 */
	private function build_csv_rows( $run_id, $type ) {
		// Failures and detached attachments derive live from snapshots.
		if ( 'failures' === $type ) {
			$rows   = array( array( 'product_id', 'status', 'error' ) );
			$offset = 0;
			do {
				$page = $this->snapshots->get_rows( $run_id, array( SnapshotRepository::STATUS_FAILED ), 500, $offset );
				foreach ( $page as $snapshot ) {
					$rows[] = array( (int) $snapshot->product_id, (string) $snapshot->status, (string) $snapshot->error );
				}
				$offset += 500;
			} while ( count( $page ) === 500 );

			return count( $rows ) > 1 ? $rows : array( array( 'product_id', 'status', 'error' ) );
		}

		if ( 'detached' === $type ) {
			$assigner = new Assigner( $this->snapshots, $this->logger );
			$rows     = array( array( 'product_id', 'detached_attachment_ids' ) );
			$offset   = 0;
			do {
				$page = $this->snapshots->get_rows( $run_id, array( SnapshotRepository::STATUS_DONE ), 500, $offset );
				foreach ( $page as $snapshot ) {
					$prev = $assigner->ids_from_gallery( $snapshot->prev_gallery );
					if ( null !== $snapshot->prev_thumbnail_id && (int) $snapshot->prev_thumbnail_id > 0 ) {
						$prev[] = (int) $snapshot->prev_thumbnail_id;
					}
					$new = $assigner->ids_from_gallery( $snapshot->new_gallery );
					if ( null !== $snapshot->new_thumbnail_id && (int) $snapshot->new_thumbnail_id > 0 ) {
						$new[] = (int) $snapshot->new_thumbnail_id;
					}

					$detached = array_diff( array_unique( $prev ), $new );
					if ( ! empty( $detached ) ) {
						$rows[] = array( (int) $snapshot->product_id, implode( ' ', $detached ) );
					}
				}
				$offset += 500;
			} while ( count( $page ) === 500 );

			return $rows;
		}

		$plan = $this->runs->get_plan( $run_id );
		if ( ! $plan || empty( $plan['report'] ) || ! isset( $plan['report'][ $type ] ) ) {
			return null;
		}

		$section = $plan['report'][ $type ];
		$rows    = array();

		switch ( $type ) {
			case 'not_found':
				$rows[] = array( 'attachment_id', 'filename' );
				foreach ( $section as $item ) {
					$rows[] = array( (int) $item['id'], (string) $item['file'] );
				}
				break;
			case 'variation':
				$rows[] = array( 'attachment_id', 'filename', 'variation_sku' );
				foreach ( $section as $item ) {
					$rows[] = array( (int) $item['id'], (string) $item['file'], (string) $item['sku'] );
				}
				break;
			case 'duplicate_sku':
				$rows[] = array( 'attachment_id', 'filename', 'sku', 'product_ids' );
				foreach ( $section as $item ) {
					$rows[] = array( (int) $item['id'], (string) $item['file'], (string) $item['sku'], implode( ' ', array_map( 'intval', $item['product_ids'] ) ) );
				}
				break;
			case 'duplicate_rename':
				$rows[] = array( 'attachment_id', 'filename', 'original_title' );
				foreach ( $section as $item ) {
					$rows[] = array( (int) $item['id'], (string) $item['file'], (string) $item['title'] );
				}
				break;
			case 'warnings':
				$rows[] = array( 'warning' );
				foreach ( $section as $warning ) {
					$rows[] = array( (string) $warning );
				}
				break;
			case 'skipped_existing':
				$rows[] = array( 'product_id', 'product_name', 'matched_files' );
				foreach ( $section as $item ) {
					$rows[] = array( (int) $item['product_id'], (string) $item['name'], (int) $item['files'] );
				}
				break;
			default:
				return null;
		}

		return $rows;
	}

	/**
	 * Build a nonce-signed export URL.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $type   Export type.
	 * @return string
	 */
	private function export_url( $run_id, $type ) {
		return add_query_arg(
			array(
				'action' => 'msim_export',
				'run_id' => (int) $run_id,
				'type'   => $type,
				'nonce'  => wp_create_nonce( self::NONCE ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Shape the stored report for the JS renderer: cap long lists, attach
	 * matched-product rows (with identifier type) and CSV URLs.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $report Stored report.
	 * @return array
	 */
	private function report_for_js( $run_id, array $report ) {
		$plan    = $this->runs->get_plan( $run_id );
		$matched = array();

		if ( $plan && ! empty( $plan['products'] ) ) {
			foreach ( $plan['products'] as $product ) {
				$identifier = array();
				foreach ( (array) $product['identifier'] as $by => $value ) {
					$identifier[] = ( 'product_id' === $by ? 'ID ' : 'SKU ' ) . $value;
				}

				$matched[] = array(
					'product_id' => (int) $product['product_id'],
					'name'       => (string) ( isset( $product['product_name'] ) ? $product['product_name'] : '' ),
					'matched_by' => implode( ' + ', $identifier ),
					'files'      => count( $product['files'] ),
					'gallery'    => count( $product['gallery'] ),
					'warnings'   => array_values( (array) $product['warnings'] ),
					'edit_link'  => admin_url( 'post.php?post=' . (int) $product['product_id'] . '&action=edit' ),
				);

				if ( count( $matched ) >= 200 ) {
					break;
				}
			}
		}

		$cap = static function ( array $list, $limit = 50 ) {
			return array_slice( $list, 0, $limit );
		};

		return array(
			'counts'           => $report['counts'],
			'matched'          => $matched,
			'not_found'        => $cap( $report['not_found'] ),
			'variation'        => $cap( $report['variation'] ),
			'duplicate_sku'    => $cap( $report['duplicate_sku'] ),
			'duplicate_rename' => $cap( $report['duplicate_rename'] ),
			'warnings'         => $cap( $report['warnings'], 100 ),
			'skipped_existing' => $cap( $report['skipped_existing'] ),
			'csv'              => array(
				'not_found'        => $this->export_url( $run_id, 'not_found' ),
				'variation'        => $this->export_url( $run_id, 'variation' ),
				'duplicate_sku'    => $this->export_url( $run_id, 'duplicate_sku' ),
				'duplicate_rename' => $this->export_url( $run_id, 'duplicate_rename' ),
				'warnings'         => $this->export_url( $run_id, 'warnings' ),
				'skipped_existing' => $this->export_url( $run_id, 'skipped_existing' ),
			),
		);
	}

	/**
	 * Most recent run with a given status.
	 *
	 * @param string $status Run status.
	 * @return array|null
	 */
	private function latest_run_with_status( $status ) {
		foreach ( $this->runs->all() as $run ) {
			if ( $status === $run['status'] ) {
				return $run;
			}
		}

		return null;
	}
}
