<?php
/**
 * Plugin bootstrap.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together with a strict zero-frontend-footprint policy:
 *
 *  - admin requests (including admin-ajax): full UI + AJAX + job hooks;
 *  - cron / WP-CLI requests: job hooks only (Action Scheduler's runner can
 *    execute in those contexts);
 *  - frontend requests: nothing at all — except the single add_attachment
 *    listener, and only while the auto-attach setting is enabled (reading
 *    that setting costs zero queries: the settings option is autoloaded).
 *
 * No wp-cron events are registered, ever.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service instances.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Bootstrap on plugins_loaded (priority 20, after WooCommerce).
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			if ( is_admin() ) {
				add_action(
					'admin_notices',
					static function () {
						echo '<div class="notice notice-error"><p>';
						esc_html_e( 'Mazyoud SKU Image Matcher requires WooCommerce to be installed and active.', 'mazyoud-sku-image-matcher' );
						echo '</p></div>';
					}
				);
			}

			return;
		}

		self::instance()->init();
	}

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks for the current request context.
	 *
	 * @return void
	 */
	public function init() {
		$settings = $this->settings();

		// The plugin's only possible non-admin hook: auto-attach on upload.
		// Media uploads can arrive via wp-admin, admin-ajax or the REST API,
		// so this one is registered in every context — but only while the
		// setting is enabled (autoloaded option: zero extra queries).
		if ( $settings->auto_attach_enabled() ) {
			$this->auto_attach_listener()->register();
		}

		// Background-job callbacks: admin (Action Scheduler's async runner
		// uses admin-ajax), cron fallback runner, or WP-CLI. Never on
		// frontend page loads.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->queue()->register_hooks();
		}

		if ( is_admin() ) {
			$this->admin_page()->register();
		}
	}

	/**
	 * Activation: create/upgrade the snapshots table, seed default settings.
	 * No cron events are scheduled.
	 *
	 * @return void
	 */
	public static function activate() {
		SnapshotRepository::install();

		add_option( Settings::OPTION, Settings::defaults() ); // Small array — autoloaded by design.
		update_option( 'msim_db_version', MSIM_DB_VERSION, false );
	}

	/**
	 * Deactivation: unschedule all pending plugin actions and release the
	 * run lock so nothing is left orphaned. Snapshots and run history are
	 * kept (removed only on uninstall).
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Queue::HOOK_PROCESS, array(), Queue::GROUP );
			as_unschedule_all_actions( Queue::HOOK_ROLLBACK, array(), Queue::GROUP );
		}

		delete_option( RunRepository::OPTION_LOCK );
	}

	/**
	 * Create/upgrade the database schema when the stored version differs.
	 * Checked on the plugin screen and before scans (not on every request:
	 * the version option is deliberately not autoloaded).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'msim_db_version' ) !== MSIM_DB_VERSION ) {
			SnapshotRepository::install();
			update_option( 'msim_db_version', MSIM_DB_VERSION, false );
		}
	}

	/**
	 * Settings service.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->service(
			'settings',
			static function () {
				return new Settings();
			}
		);
	}

	/**
	 * Logger service.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->service(
			'logger',
			static function () {
				return new Logger();
			}
		);
	}

	/**
	 * Filename parser service.
	 *
	 * @return FilenameParser
	 */
	public function parser() {
		return $this->service(
			'parser',
			static function () {
				return new FilenameParser();
			}
		);
	}

	/**
	 * SKU resolver service.
	 *
	 * @return SkuResolver
	 */
	public function resolver() {
		return $this->service(
			'resolver',
			static function () {
				return new SkuResolver();
			}
		);
	}

	/**
	 * Snapshot repository service.
	 *
	 * @return SnapshotRepository
	 */
	public function snapshots() {
		return $this->service(
			'snapshots',
			static function () {
				return new SnapshotRepository();
			}
		);
	}

	/**
	 * Run repository service.
	 *
	 * @return RunRepository
	 */
	public function runs() {
		return $this->service(
			'runs',
			function () {
				return new RunRepository( $this->snapshots() );
			}
		);
	}

	/**
	 * Assigner service.
	 *
	 * @return Assigner
	 */
	public function assigner() {
		return $this->service(
			'assigner',
			function () {
				return new Assigner( $this->snapshots(), $this->logger() );
			}
		);
	}

	/**
	 * Planner service.
	 *
	 * @return MatchPlanner
	 */
	public function planner() {
		return $this->service(
			'planner',
			function () {
				return new MatchPlanner( $this->parser(), $this->resolver(), $this->settings(), $this->logger() );
			}
		);
	}

	/**
	 * Queue service.
	 *
	 * @return Queue
	 */
	public function queue() {
		return $this->service(
			'queue',
			function () {
				return new Queue( $this->runs(), $this->snapshots(), $this->assigner(), $this->logger() );
			}
		);
	}

	/**
	 * Auto-attach listener service.
	 *
	 * @return AutoAttachListener
	 */
	public function auto_attach_listener() {
		return $this->service(
			'auto_attach',
			function () {
				return new AutoAttachListener( $this->parser(), $this->resolver(), $this->settings(), $this->assigner(), $this->logger() );
			}
		);
	}

	/**
	 * Admin page service.
	 *
	 * @return AdminPage
	 */
	public function admin_page() {
		return $this->service(
			'admin_page',
			function () {
				return new AdminPage( $this->settings(), $this->planner(), $this->runs(), $this->snapshots(), $this->queue(), $this->logger() );
			}
		);
	}

	/**
	 * Lazy service container.
	 *
	 * @param string   $key     Service key.
	 * @param callable $factory Factory.
	 * @return object
	 */
	private function service( $key, callable $factory ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = $factory();
		}

		return $this->services[ $key ];
	}
}
