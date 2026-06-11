<?php
/**
 * Action Scheduler wrapper: background batch processing.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the plan as Action Scheduler background jobs (batches of ~25
 * products, each action bounded to ~15s of work — a partially processed
 * batch re-enqueues itself and, thanks to per-product snapshot statuses,
 * resumes exactly where it stopped). Rollbacks use the same machinery.
 *
 * No wp-cron events are ever registered: the plugin relies entirely on
 * Action Scheduler's own runner (which ships with WooCommerce).
 */
class Queue {

	public const HOOK_PROCESS  = 'msim_process_batch';
	public const HOOK_ROLLBACK = 'msim_rollback_batch';
	public const GROUP         = 'msim';

	/**
	 * Per-action wall-clock budget (seconds): stay well under the spec's
	 * ~20s ceiling.
	 */
	private const TIME_BUDGET = 15;

	/**
	 * Snapshot rows per rollback batch.
	 */
	private const ROLLBACK_BATCH = 25;

	/**
	 * Constructor.
	 *
	 * @param RunRepository      $runs      Run repository.
	 * @param SnapshotRepository $snapshots Snapshot repository.
	 * @param Assigner           $assigner  Assigner.
	 * @param Logger             $logger    Logger.
	 */
	public function __construct(
		private RunRepository $runs,
		private SnapshotRepository $snapshots,
		private Assigner $assigner,
		private Logger $logger
	) {
	}

	/**
	 * Register the Action Scheduler callbacks. Called from admin, cron and
	 * WP-CLI contexts only — never on frontend page loads.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::HOOK_PROCESS, array( $this, 'process_batch' ), 10, 2 );
		add_action( self::HOOK_ROLLBACK, array( $this, 'process_rollback_batch' ), 10, 3 );
	}

	/**
	 * Whether Action Scheduler's API is available.
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Start a planned run: acquire the lock, mark it running and enqueue one
	 * async action per batch.
	 *
	 * @param int $run_id Run ID (must be in "planned" status).
	 * @return true|\WP_Error
	 */
	public function start_run( $run_id ) {
		$run_id = (int) $run_id;

		if ( ! $this->available() ) {
			return new \WP_Error( 'msim_no_as', __( 'Action Scheduler is not available. Is WooCommerce active?', 'mazyoud-sku-image-matcher' ) );
		}

		$run = $this->runs->get( $run_id );
		if ( ! $run || RunRepository::STATUS_PLANNED !== $run['status'] ) {
			return new \WP_Error( 'msim_bad_run', __( 'This scan is no longer available. Please scan again.', 'mazyoud-sku-image-matcher' ) );
		}

		$plan = $this->runs->get_plan( $run_id );
		if ( ! $plan || empty( $plan['batches'] ) ) {
			return new \WP_Error( 'msim_empty_plan', __( 'The plan is empty — nothing to assign.', 'mazyoud-sku-image-matcher' ) );
		}

		if ( ! $this->runs->acquire_lock( $run_id ) ) {
			return new \WP_Error( 'msim_locked', __( 'Another run is already in progress.', 'mazyoud-sku-image-matcher' ) );
		}

		$this->runs->update_run(
			$run_id,
			array(
				'status'  => RunRepository::STATUS_RUNNING,
				'started' => current_time( 'mysql' ),
			)
		);

		$batch_count = count( $plan['batches'] );
		for ( $i = 0; $i < $batch_count; $i++ ) {
			as_enqueue_async_action( self::HOOK_PROCESS, array( 'run_id' => $run_id, 'batch' => $i ), self::GROUP );
		}

		$this->logger->info(
			'Run started.',
			array(
				'run_id'   => $run_id,
				'products' => (int) $run['total_products'],
				'batches'  => $batch_count,
			)
		);

		return true;
	}

	/**
	 * Action Scheduler callback: process one batch of products.
	 *
	 * Idempotent: products with a terminal snapshot status are skipped, so a
	 * retried or re-enqueued action resumes safely. When the time budget is
	 * exhausted mid-batch the action re-enqueues itself and returns.
	 *
	 * @param int $run_id Run ID.
	 * @param int $batch  Batch index.
	 * @return void
	 */
	public function process_batch( $run_id = 0, $batch = 0 ) {
		$run_id = (int) $run_id;
		$batch  = (int) $batch;

		$run = $this->runs->get( $run_id );
		if ( ! $run || RunRepository::STATUS_RUNNING !== $run['status'] ) {
			return; // Cancelled or stale.
		}

		$plan = $this->runs->get_plan( $run_id );
		if ( ! $plan || ! isset( $plan['batches'][ $batch ] ) ) {
			return;
		}

		$deadline = time() + self::TIME_BUDGET;
		$mode     = (string) $plan['mode'];

		// Prime post + meta caches for the whole batch in two queries.
		$batch_products = array_map( 'intval', $plan['batches'][ $batch ] );
		$prime          = $batch_products;
		foreach ( $batch_products as $pid ) {
			if ( isset( $plan['products'][ $pid ] ) ) {
				$prime[] = (int) $plan['products'][ $pid ]['featured'];
				$prime   = array_merge( $prime, array_map( 'intval', (array) $plan['products'][ $pid ]['gallery'] ) );
			}
		}
		_prime_post_caches( array_unique( $prime ), false, true );

		foreach ( $batch_products as $i => $product_id ) {
			if ( ! isset( $plan['products'][ $product_id ] ) ) {
				continue;
			}

			$this->assigner->apply_product_plan( $run_id, $plan['products'][ $product_id ], $mode );

			$remaining = array_slice( $batch_products, $i + 1 );
			if ( ! empty( $remaining ) && time() >= $deadline ) {
				// Out of budget: hand the rest back to Action Scheduler.
				as_enqueue_async_action( self::HOOK_PROCESS, array( 'run_id' => $run_id, 'batch' => $batch ), self::GROUP );
				$this->logger->info( 'Batch re-enqueued (time budget reached).', array( 'run_id' => $run_id, 'batch' => $batch ) );

				return;
			}
		}

		$this->maybe_finalize_run( $run_id );
	}

	/**
	 * Finalize the run once every planned product has a terminal snapshot
	 * status: set the final run status, release the lock, log the summary.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function maybe_finalize_run( $run_id ) {
		$run = $this->runs->get( $run_id );
		if ( ! $run || RunRepository::STATUS_RUNNING !== $run['status'] ) {
			return;
		}

		$counts   = $this->snapshots->count_by_status( $run_id );
		$terminal = 0;
		foreach ( SnapshotRepository::TERMINAL as $status ) {
			$terminal += isset( $counts[ $status ] ) ? $counts[ $status ] : 0;
		}

		if ( $terminal < (int) $run['total_products'] ) {
			return;
		}

		$failed = isset( $counts[ SnapshotRepository::STATUS_FAILED ] ) ? $counts[ SnapshotRepository::STATUS_FAILED ] : 0;
		$status = $failed > 0 ? RunRepository::STATUS_COMPLETED_WITH_ERRORS : RunRepository::STATUS_COMPLETED;

		$this->runs->update_run(
			$run_id,
			array(
				'status'   => $status,
				'finished' => current_time( 'mysql' ),
				'results'  => $counts,
			)
		);
		$this->runs->release_lock( $run_id );
		$this->runs->purge_old_runs();

		$this->logger->info( 'Run finished.', array_merge( array( 'run_id' => $run_id, 'status' => $status ), $counts ) );
	}

	/**
	 * Start a rollback for a completed run: mark it rolling back, reuse the
	 * run lock and enqueue rollback batches.
	 *
	 * @param int  $run_id Run ID.
	 * @param bool $force  Restore even manually-edited products (second pass
	 *                     after explicit confirmation).
	 * @return true|\WP_Error
	 */
	public function start_rollback( $run_id, $force = false ) {
		$run_id = (int) $run_id;

		if ( ! $this->available() ) {
			return new \WP_Error( 'msim_no_as', __( 'Action Scheduler is not available. Is WooCommerce active?', 'mazyoud-sku-image-matcher' ) );
		}

		$run = $this->runs->get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'msim_bad_run', __( 'Unknown run.', 'mazyoud-sku-image-matcher' ) );
		}

		$rollbackable = array(
			RunRepository::STATUS_COMPLETED,
			RunRepository::STATUS_COMPLETED_WITH_ERRORS,
			RunRepository::STATUS_CANCELLED,
			RunRepository::STATUS_ROLLED_BACK_WITH_CONFLICTS,
		);
		if ( ! in_array( $run['status'], $rollbackable, true ) ) {
			return new \WP_Error( 'msim_bad_state', __( 'This run cannot be rolled back in its current state.', 'mazyoud-sku-image-matcher' ) );
		}

		if ( ! $this->runs->acquire_lock( $run_id ) ) {
			return new \WP_Error( 'msim_locked', __( 'Another run is already in progress.', 'mazyoud-sku-image-matcher' ) );
		}

		$statuses = $force
			? array( SnapshotRepository::STATUS_ROLLBACK_CONFLICT )
			: array( SnapshotRepository::STATUS_DONE, SnapshotRepository::STATUS_FAILED, SnapshotRepository::STATUS_PENDING );

		$this->runs->update_run( $run_id, array( 'status' => RunRepository::STATUS_ROLLING_BACK ) );

		as_enqueue_async_action(
			self::HOOK_ROLLBACK,
			array(
				'run_id'   => $run_id,
				'force'    => (bool) $force,
				'statuses' => $statuses,
			),
			self::GROUP
		);

		$this->logger->info( 'Rollback started.', array( 'run_id' => $run_id, 'force' => (bool) $force ) );

		return true;
	}

	/**
	 * Action Scheduler callback: roll back up to ROLLBACK_BATCH snapshots,
	 * then re-enqueue itself until none remain in the requested statuses.
	 *
	 * @param int   $run_id   Run ID.
	 * @param bool  $force    Overwrite manually-edited products.
	 * @param array $statuses Snapshot statuses to restore.
	 * @return void
	 */
	public function process_rollback_batch( $run_id = 0, $force = false, $statuses = array() ) {
		$run_id   = (int) $run_id;
		$force    = (bool) $force;
		$statuses = array_values( array_filter( array_map( 'strval', (array) $statuses ) ) );

		$run = $this->runs->get( $run_id );
		if ( ! $run || RunRepository::STATUS_ROLLING_BACK !== $run['status'] ) {
			return;
		}

		if ( empty( $statuses ) ) {
			$statuses = array( SnapshotRepository::STATUS_DONE, SnapshotRepository::STATUS_FAILED, SnapshotRepository::STATUS_PENDING );
		}

		// Always page from offset 0: restored rows leave the status filter.
		// Conflicts would be re-served forever, so they are excluded from
		// the query through their own status transition (done → conflict).
		$rows = $this->snapshots->get_rows( $run_id, $statuses, self::ROLLBACK_BATCH, 0 );

		if ( empty( $rows ) ) {
			$this->finalize_rollback( $run_id );

			return;
		}

		$deadline = time() + self::TIME_BUDGET;
		$restored = 0;

		foreach ( $rows as $snapshot ) {
			$status = $this->assigner->rollback_snapshot( $snapshot, $force );
			if ( SnapshotRepository::STATUS_ROLLED_BACK === $status ) {
				++$restored;
			}

			if ( time() >= $deadline ) {
				break;
			}
		}

		// In force mode rows that fail again keep the conflict status and
		// would be re-served forever — finalize when a pass makes no progress.
		if ( $force && 0 === $restored ) {
			$this->logger->error( 'Forced rollback made no progress; finalizing with remaining conflicts.', array( 'run_id' => $run_id ) );
			$this->finalize_rollback( $run_id );

			return;
		}

		as_enqueue_async_action(
			self::HOOK_ROLLBACK,
			array(
				'run_id'   => $run_id,
				'force'    => $force,
				'statuses' => $statuses,
			),
			self::GROUP
		);
	}

	/**
	 * Finalize a rollback: final status (with or without conflicts), release
	 * the lock, log.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private function finalize_rollback( $run_id ) {
		$counts    = $this->snapshots->count_by_status( $run_id );
		$conflicts = isset( $counts[ SnapshotRepository::STATUS_ROLLBACK_CONFLICT ] ) ? $counts[ SnapshotRepository::STATUS_ROLLBACK_CONFLICT ] : 0;

		$this->runs->update_run(
			$run_id,
			array(
				'status'   => $conflicts > 0 ? RunRepository::STATUS_ROLLED_BACK_WITH_CONFLICTS : RunRepository::STATUS_ROLLED_BACK,
				'finished' => current_time( 'mysql' ),
				'results'  => $counts,
			)
		);
		$this->runs->release_lock( $run_id );

		$this->logger->info( 'Rollback finished.', array_merge( array( 'run_id' => $run_id, 'conflicts' => $conflicts ), $counts ) );
	}

	/**
	 * Cancel the active run: unschedule pending batches, mark cancelled,
	 * release the lock. Already-processed products keep their snapshots and
	 * can be rolled back.
	 *
	 * @param int $run_id Run ID.
	 * @return bool Whether a cancellation happened.
	 */
	public function cancel_run( $run_id ) {
		$run_id = (int) $run_id;
		$run    = $this->runs->get( $run_id );

		if ( ! $run || ! in_array( $run['status'], array( RunRepository::STATUS_RUNNING, RunRepository::STATUS_ROLLING_BACK ), true ) ) {
			return false;
		}

		$this->unschedule_all();

		$this->runs->update_run(
			$run_id,
			array(
				'status'   => RunRepository::STATUS_CANCELLED,
				'finished' => current_time( 'mysql' ),
				'results'  => $this->snapshots->count_by_status( $run_id ),
			)
		);
		$this->runs->release_lock( $run_id );

		$this->logger->warning( 'Run cancelled by user.', array( 'run_id' => $run_id ) );

		return true;
	}

	/**
	 * Recover a stalled run (e.g. a batch died from OOM/kill and Action
	 * Scheduler has nothing pending): re-enqueue every batch. Idempotent —
	 * terminal products are skipped instantly.
	 *
	 * @param int $run_id Run ID.
	 * @return bool
	 */
	public function recover_run( $run_id ) {
		$run_id = (int) $run_id;
		$run    = $this->runs->get( $run_id );

		if ( ! $run || ! $this->available() ) {
			return false;
		}

		if ( RunRepository::STATUS_RUNNING === $run['status'] ) {
			$plan = $this->runs->get_plan( $run_id );
			if ( ! $plan ) {
				return false;
			}

			$batch_count = count( $plan['batches'] );
			for ( $i = 0; $i < $batch_count; $i++ ) {
				as_enqueue_async_action( self::HOOK_PROCESS, array( 'run_id' => $run_id, 'batch' => $i ), self::GROUP );
			}

			$this->logger->warning( 'Stalled run re-enqueued.', array( 'run_id' => $run_id, 'batches' => $batch_count ) );

			return true;
		}

		if ( RunRepository::STATUS_ROLLING_BACK === $run['status'] ) {
			as_enqueue_async_action( self::HOOK_ROLLBACK, array( 'run_id' => $run_id, 'force' => false, 'statuses' => array() ), self::GROUP );
			$this->logger->warning( 'Stalled rollback re-enqueued.', array( 'run_id' => $run_id ) );

			return true;
		}

		return false;
	}

	/**
	 * Whether Action Scheduler still has pending/in-progress actions for
	 * this plugin. Only one run is ever active, so a global check suffices.
	 *
	 * @return bool
	 */
	public function has_pending_actions() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		foreach ( array( self::HOOK_PROCESS, self::HOOK_ROLLBACK ) as $hook ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
					'group'    => self::GROUP,
				),
				'ids'
			);
			if ( ! empty( $pending ) ) {
				return true;
			}

			$running = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => \ActionScheduler_Store::STATUS_RUNNING,
					'per_page' => 1,
					'group'    => self::GROUP,
				),
				'ids'
			);
			if ( ! empty( $running ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Unschedule every pending action belonging to this plugin (used on
	 * cancel and on plugin deactivation).
	 *
	 * @return void
	 */
	public function unschedule_all() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK_PROCESS, array(), self::GROUP );
		as_unschedule_all_actions( self::HOOK_ROLLBACK, array(), self::GROUP );
	}
}
