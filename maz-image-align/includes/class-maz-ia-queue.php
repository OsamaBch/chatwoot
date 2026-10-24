<?php
/**
 * Mazyoud Image Align — background bulk queue.
 *
 * Jobs are stored in options (`maz_ia_job_<id>`), processed a few images at a time by
 * Action Scheduler when WooCommerce provides it, otherwise by a WP-Cron single event.
 * Each image is saved to the job as soon as it finishes, so a run that dies mid-batch
 * resumes from the next image; a stalled job (no progress for 5 min) is re-kicked when
 * its progress is polled.
 */

if ( ! class_exists( 'Maz_IA_Queue' ) ) :

final class Maz_IA_Queue {

	const HOOK      = 'maz_ia_process_batch';
	const LIST_OPT  = 'maz_ia_jobs';
	const STALL_SEC = 300;
	const JOB_TTL   = 2 * DAY_IN_SECONDS;

	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'run' ], 10, 1 );
	}

	public static function backend() {
		return function_exists( 'as_schedule_single_action' ) ? 'action_scheduler' : 'wp_cron';
	}

	public static function backend_label() {
		return self::backend() === 'action_scheduler' ? 'Action Scheduler (WooCommerce)' : 'WP-Cron (Action Scheduler not found)';
	}

	/** Create a job for $ids (already capability-filtered by the caller). Returns the job id. */
	public static function enqueue( array $ids, $action, $type = '' ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$job = [
			'id' => substr( md5( uniqid( 'maz', true ) ), 0, 12 ), 'action' => $action, 'type' => $type, 'ids' => $ids,
			'total' => count( $ids ), 'done' => 0, 'ok' => 0, 'fail' => 0, 'flagged' => 0, 'errors' => [],
			'status' => 'queued', 'user' => get_current_user_id(), 'created' => time(), 'updated' => time(),
		];
		update_option( 'maz_ia_job_' . $job['id'], $job, false );
		$list = get_option( self::LIST_OPT, [] ); $list = is_array( $list ) ? $list : [];
		$list[] = $job['id'];
		update_option( self::LIST_OPT, array_values( array_unique( $list ) ), false );
		self::kick( $job['id'] );
		return $job['id'];
	}

	public static function get( $id ) {
		$id = preg_replace( '/[^a-f0-9]/', '', (string) $id );
		return $id ? get_option( 'maz_ia_job_' . $id, null ) : null;
	}

	private static function save( $job ) { $job['updated'] = time(); update_option( 'maz_ia_job_' . $job['id'], $job, false ); return $job; }

	/** Schedule the next run (now). */
	private static function kick( $id ) {
		if ( self::backend() === 'action_scheduler' ) {
			if ( ! as_next_scheduled_action( self::HOOK, [ $id ], 'maz-image-align' ) ) as_schedule_single_action( time(), self::HOOK, [ $id ], 'maz-image-align' );
		} else {
			if ( ! wp_next_scheduled( self::HOOK, [ $id ] ) ) wp_schedule_single_event( time(), self::HOOK, [ $id ] );
			if ( function_exists( 'spawn_cron' ) ) spawn_cron();
		}
	}

	/** One batch: up to batch_size images or ~60 % of max_execution_time, then reschedule. */
	public static function run( $id ) {
		$job = self::get( $id );
		if ( ! $job || in_array( $job['status'], [ 'done', 'cancelled' ], true ) ) return;
		$job['status'] = 'running'; $job = self::save( $job );

		$o = Maz_IA_Settings::opts();
		$max = (int) ini_get( 'max_execution_time' );
		$budget = $max > 0 ? max( 10, (int) ( $max * 0.6 ) ) : 50;
		$t0 = microtime( true ); $n = 0;
		if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'image' );

		while ( $job['done'] < $job['total'] && $n < $o['batch_size'] && ( microtime( true ) - $t0 ) < $budget ) {
			$aid = (int) $job['ids'][ $job['done'] ];
			$r = self::do_one( $aid, $job );
			if ( is_wp_error( $r ) ) { $job['fail']++; if ( count( $job['errors'] ) < 100 ) $job['errors'][] = [ 'id' => $aid, 'msg' => $r->get_error_message() ]; }
			else { $job['ok']++; if ( ! empty( $r['flags'] ) ) $job['flagged']++; }
			$job['done']++; $n++;
			$job = self::save( $job ); // resumable: progress survives a dying request
		}
		if ( $job['done'] >= $job['total'] ) { $job['status'] = 'done'; self::save( $job ); }
		else { $job['status'] = 'queued'; self::save( $job ); self::kick( $id ); }
	}

	private static function do_one( $aid, $job ) {
		// Cron has no user: run as the person who queued the job so capability checks are real.
		$prev = get_current_user_id();
		if ( $job['user'] && $prev !== (int) $job['user'] ) wp_set_current_user( (int) $job['user'] );
		try {
			if ( ! Maz_IA_Files::user_can( $aid ) ) return new WP_Error( 'maz_cap', 'Permission denied' );
			switch ( $job['action'] ) {
				case 'align':   return Maz_IA_Files::align( $aid, $job['type'] );
				case 'revert':  return Maz_IA_Files::revert( $aid );
				case 'exclude': Maz_IA_Files::set_excluded( $aid, true ); return [ 'id' => $aid ];
			}
			return new WP_Error( 'maz_action', 'Unknown action' );
		} finally {
			if ( get_current_user_id() !== $prev ) wp_set_current_user( $prev );
		}
	}

	public static function cancel( $id ) {
		$job = self::get( $id );
		if ( ! $job ) return false;
		if ( $job['status'] !== 'done' ) { $job['status'] = 'cancelled'; self::save( $job ); }
		return true;
	}

	/** Jobs for the progress UI; also re-kicks stalled jobs and prunes old ones. */
	public static function active_jobs() {
		$list = get_option( self::LIST_OPT, [] ); $list = is_array( $list ) ? $list : [];
		$out = []; $keep = [];
		foreach ( $list as $id ) {
			$job = self::get( $id );
			if ( ! $job ) continue;
			if ( time() - $job['updated'] > self::JOB_TTL ) { delete_option( 'maz_ia_job_' . $id ); continue; }
			if ( in_array( $job['status'], [ 'queued', 'running' ], true ) && time() - $job['updated'] > self::STALL_SEC ) { $job['status'] = 'queued'; $job = self::save( $job ); self::kick( $id ); }
			$keep[] = $id;
			if ( ! current_user_can( 'manage_options' ) && (int) $job['user'] !== get_current_user_id() ) continue;
			$out[] = self::public_view( $job );
		}
		if ( $keep !== $list ) update_option( self::LIST_OPT, $keep, false );
		return $out;
	}

	public static function dismiss( $id ) {
		$job = self::get( $id );
		if ( ! $job || ! in_array( $job['status'], [ 'done', 'cancelled' ], true ) ) return false;
		delete_option( 'maz_ia_job_' . $job['id'] );
		$list = get_option( self::LIST_OPT, [] );
		update_option( self::LIST_OPT, array_values( array_diff( is_array( $list ) ? $list : [], [ $job['id'] ] ) ), false );
		return true;
	}

	public static function public_view( $job ) {
		$v = $job; unset( $v['ids'], $v['user'] );
		$v['errors'] = array_slice( $job['errors'], 0, 20 );
		$v['action_label'] = [ 'align' => 'Align', 'revert' => 'Revert', 'exclude' => 'Mark as not hanger' ][ $job['action'] ] ?? $job['action'];
		if ( $job['action'] === 'align' ) { $t = Maz_IA_Settings::types(); $v['type_label'] = $t[ $job['type'] ]['label'] ?? $job['type']; }
		return $v;
	}
}

endif;
