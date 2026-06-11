<?php
/**
 * Run registry: run records, plans, the concurrency lock and purging.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Stores run records in the msim_runs option and each run's match plan in
 * its own msim_plan_{id} option — written once, never autoloaded. The
 * msim_active_run_id option is the run lock preventing two simultaneous
 * runs (acquired atomically via add_option). The last 10 runs are kept;
 * older runs and their snapshots are purged automatically.
 */
class RunRepository {

	public const OPTION_RUNS    = 'msim_runs';
	public const OPTION_LAST_ID = 'msim_last_run_id';
	public const OPTION_LOCK    = 'msim_active_run_id';

	public const KEEP_RUNS = 10;

	public const STATUS_PLANNED                    = 'planned';
	public const STATUS_RUNNING                    = 'running';
	public const STATUS_COMPLETED                  = 'completed';
	public const STATUS_COMPLETED_WITH_ERRORS      = 'completed_with_errors';
	public const STATUS_CANCELLED                  = 'cancelled';
	public const STATUS_ROLLING_BACK               = 'rolling_back';
	public const STATUS_ROLLED_BACK                = 'rolled_back';
	public const STATUS_ROLLED_BACK_WITH_CONFLICTS = 'rolled_back_with_conflicts';

	/**
	 * Constructor.
	 *
	 * @param SnapshotRepository $snapshots Snapshot repository (for purging).
	 */
	public function __construct( private SnapshotRepository $snapshots ) {
	}

	/**
	 * All run records, newest first.
	 *
	 * @return array<int, array>
	 */
	public function all() {
		$runs = get_option( self::OPTION_RUNS, array() );
		if ( ! is_array( $runs ) ) {
			$runs = array();
		}

		krsort( $runs, SORT_NUMERIC );

		return $runs;
	}

	/**
	 * Get one run record.
	 *
	 * @param int $run_id Run ID.
	 * @return array|null
	 */
	public function get( $run_id ) {
		$runs = $this->all();

		return isset( $runs[ (int) $run_id ] ) ? $runs[ (int) $run_id ] : null;
	}

	/**
	 * Create a new "planned" run from a freshly scanned plan. Any previous
	 * still-planned (never executed) run is superseded and removed.
	 *
	 * @param array $plan   Full match plan (stored once, autoload = no).
	 * @param array $counts Report counts for the run record.
	 * @return int New run ID.
	 */
	public function create_planned_run( array $plan, array $counts ) {
		foreach ( $this->all() as $id => $run ) {
			if ( self::STATUS_PLANNED === $run['status'] ) {
				$this->delete_run( (int) $id );
			}
		}

		$run_id = (int) get_option( self::OPTION_LAST_ID, 0 ) + 1;
		update_option( self::OPTION_LAST_ID, $run_id, false );

		$plan['run_id'] = $run_id;
		delete_option( self::plan_option( $run_id ) ); // Defensive: clear any stale row.
		add_option( self::plan_option( $run_id ), $plan, '', false );

		$this->save_run(
			$run_id,
			array(
				'id'             => $run_id,
				'created'        => current_time( 'mysql' ),
				'status'         => self::STATUS_PLANNED,
				'mode'           => isset( $plan['mode'] ) ? $plan['mode'] : '',
				'filters'        => isset( $plan['filters'] ) ? $plan['filters'] : array(),
				'total_products' => count( $plan['products'] ),
				'total_batches'  => count( $plan['batches'] ),
				'counts'         => $counts,
				'started'        => null,
				'finished'       => null,
			)
		);

		$this->purge_old_runs();

		return $run_id;
	}

	/**
	 * Persist (insert or replace) a run record.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $record Run record.
	 * @return void
	 */
	public function save_run( $run_id, array $record ) {
		$runs                     = get_option( self::OPTION_RUNS, array() );
		$runs                     = is_array( $runs ) ? $runs : array();
		$runs[ (int) $run_id ]    = $record;

		update_option( self::OPTION_RUNS, $runs, false );
	}

	/**
	 * Merge fields into an existing run record.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $fields Fields to merge.
	 * @return array|null Updated record.
	 */
	public function update_run( $run_id, array $fields ) {
		$record = $this->get( $run_id );
		if ( null === $record ) {
			return null;
		}

		$record = array_merge( $record, $fields );
		$this->save_run( $run_id, $record );

		return $record;
	}

	/**
	 * Load a run's stored match plan.
	 *
	 * @param int $run_id Run ID.
	 * @return array|null
	 */
	public function get_plan( $run_id ) {
		$plan = get_option( self::plan_option( $run_id ), null );

		return is_array( $plan ) ? $plan : null;
	}

	/**
	 * Plan option name for a run.
	 *
	 * @param int $run_id Run ID.
	 * @return string
	 */
	public static function plan_option( $run_id ) {
		return 'msim_plan_' . (int) $run_id;
	}

	/**
	 * Try to acquire the global run lock for a run. add_option is atomic
	 * (INSERT fails if the row exists) which prevents two simultaneous runs.
	 *
	 * @param int $run_id Run ID requesting the lock.
	 * @return bool Whether the lock is held by this run.
	 */
	public function acquire_lock( $run_id ) {
		$run_id = (int) $run_id;

		if ( add_option( self::OPTION_LOCK, $run_id, '', false ) ) {
			return true;
		}

		return $this->active_run_id() === $run_id;
	}

	/**
	 * Release the run lock if held by the given run.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function release_lock( $run_id ) {
		if ( $this->active_run_id() === (int) $run_id ) {
			delete_option( self::OPTION_LOCK );
		}
	}

	/**
	 * Currently locked (active) run ID, or 0.
	 *
	 * @return int
	 */
	public function active_run_id() {
		return (int) get_option( self::OPTION_LOCK, 0 );
	}

	/**
	 * Delete a run record, its plan option and its snapshots.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function delete_run( $run_id ) {
		$run_id = (int) $run_id;

		$runs = get_option( self::OPTION_RUNS, array() );
		if ( is_array( $runs ) && isset( $runs[ $run_id ] ) ) {
			unset( $runs[ $run_id ] );
			update_option( self::OPTION_RUNS, $runs, false );
		}

		delete_option( self::plan_option( $run_id ) );
		$this->snapshots->delete_run( $run_id );
	}

	/**
	 * Keep only the most recent KEEP_RUNS runs; purge older ones (records,
	 * plan options and snapshots). The active run is never purged.
	 *
	 * @return void
	 */
	public function purge_old_runs() {
		$runs   = $this->all(); // Newest first.
		$active = $this->active_run_id();
		$kept   = 0;

		foreach ( $runs as $id => $run ) {
			$id = (int) $id;

			if ( $id === $active ) {
				++$kept;
				continue;
			}

			++$kept;
			if ( $kept > self::KEEP_RUNS ) {
				$this->delete_run( $id );
			}
		}
	}
}
