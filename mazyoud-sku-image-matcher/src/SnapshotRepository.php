<?php
/**
 * Snapshot storage for undo/rollback.
 *
 * @package Mazyoud\SkuImageMatcher
 */

namespace Mazyoud\SkuImageMatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Persists per-product snapshots in {$wpdb->prefix}msim_snapshots.
 *
 * A snapshot row is written BEFORE any modification and doubles as the
 * per-product job status (pending / done / failed / skipped / rolled_back /
 * rollback_conflict), which makes batch jobs idempotent and safely
 * resumable: a retried Action Scheduler job skips already-terminal rows.
 */
class SnapshotRepository {

	public const STATUS_PENDING           = 'pending';
	public const STATUS_DONE              = 'done';
	public const STATUS_FAILED            = 'failed';
	public const STATUS_SKIPPED           = 'skipped';
	public const STATUS_ROLLED_BACK       = 'rolled_back';
	public const STATUS_ROLLBACK_CONFLICT = 'rollback_conflict';

	/**
	 * Statuses that mean "this product's forward job is finished".
	 *
	 * @var string[]
	 */
	public const TERMINAL = array(
		self::STATUS_DONE,
		self::STATUS_FAILED,
		self::STATUS_SKIPPED,
		self::STATUS_ROLLED_BACK,
		self::STATUS_ROLLBACK_CONFLICT,
	);

	/**
	 * Table name (with prefix).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'msim_snapshots';
	}

	/**
	 * Create / upgrade the table via dbDelta. Called on activation.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			prev_thumbnail_id bigint(20) unsigned DEFAULT NULL,
			prev_gallery text DEFAULT NULL,
			prev_parents longtext DEFAULT NULL,
			prev_managed tinyint(1) NOT NULL DEFAULT 0,
			new_thumbnail_id bigint(20) unsigned DEFAULT NULL,
			new_gallery text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			error text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY run_product (run_id,product_id),
			KEY product_id (product_id),
			KEY run_status (run_id,status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Fetch the snapshot row for a (run, product) pair.
	 *
	 * @param int $run_id     Run ID.
	 * @param int $product_id Product ID.
	 * @return object|null
	 */
	public function get( $run_id, $product_id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE run_id = %d AND product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $run_id,
				(int) $product_id
			)
		);
	}

	/**
	 * Insert a snapshot of the product's prior state BEFORE modifying it.
	 * INSERT IGNORE + unique key keep concurrent retries from duplicating
	 * rows; the row that exists afterwards is returned.
	 *
	 * @param int      $run_id       Run ID.
	 * @param int      $product_id   Product ID.
	 * @param int|null $thumbnail_id Previous _thumbnail_id (null when meta absent).
	 * @param string|null $gallery   Previous _product_image_gallery (null when meta absent).
	 * @param array    $parents      attachment_id => previous post_parent.
	 * @param bool     $managed      Whether the _msim_managed marker existed.
	 * @return object The snapshot row.
	 */
	public function create( $run_id, $product_id, $thumbnail_id, $gallery, array $parents, $managed ) {
		global $wpdb;

		$existing = $this->get( $run_id, $product_id );
		if ( $existing ) {
			return $existing;
		}

		$now = current_time( 'mysql' );

		// $wpdb->insert() writes real NULLs (prepare() would coerce them to
		// ''), preserving the absent-vs-empty meta distinction needed for an
		// exact rollback. A concurrent duplicate insert loses against the
		// unique (run_id, product_id) key; both callers get the same row.
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'run_id'            => (int) $run_id,
				'product_id'        => (int) $product_id,
				'prev_thumbnail_id' => null === $thumbnail_id ? null : (int) $thumbnail_id,
				'prev_gallery'      => null === $gallery ? null : (string) $gallery,
				'prev_parents'      => wp_json_encode( array_map( 'intval', $parents ) ),
				'prev_managed'      => $managed ? 1 : 0,
				'status'            => self::STATUS_PENDING,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$wpdb->suppress_errors( $suppress );

		return $this->get( $run_id, $product_id );
	}

	/**
	 * Update a snapshot row's status (and optionally the produced state).
	 *
	 * @param int         $id            Snapshot row ID.
	 * @param string      $status        New status.
	 * @param string|null $error         Failure reason (null clears).
	 * @param int|null    $new_thumbnail New thumbnail the run produced.
	 * @param string|null $new_gallery   New gallery string the run produced.
	 * @return void
	 */
	public function set_status( $id, $status, $error = null, $new_thumbnail = null, $new_gallery = null ) {
		global $wpdb;

		$data = array(
			'status'     => (string) $status,
			'error'      => $error,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( null !== $new_thumbnail ) {
			$data['new_thumbnail_id'] = (int) $new_thumbnail;
		}
		if ( null !== $new_gallery ) {
			$data['new_gallery'] = (string) $new_gallery;
		}

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Status → count map for a run (single indexed GROUP BY; powers the
	 * lightweight progress endpoint).
	 *
	 * @param int $run_id Run ID.
	 * @return array<string,int>
	 */
	public function count_by_status( $run_id ) {
		global $wpdb;

		$table = self::table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt FROM {$table} WHERE run_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $run_id
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->cnt;
		}

		return $counts;
	}

	/**
	 * Fetch snapshot rows of a run filtered by status, paginated.
	 *
	 * @param int      $run_id   Run ID.
	 * @param string[] $statuses Statuses to include (empty = all).
	 * @param int      $limit    Page size.
	 * @param int      $offset   Offset.
	 * @return object[]
	 */
	public function get_rows( $run_id, array $statuses = array(), $limit = 100, $offset = 0 ) {
		global $wpdb;

		$table  = self::table();
		$params = array( (int) $run_id );
		$where  = 'run_id = %d';

		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where       .= " AND status IN ( {$placeholders} )";
			$params       = array_merge( $params, array_map( 'strval', $statuses ) );
		}

		$params[] = (int) $limit;
		$params[] = (int) $offset;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$params
			)
		);
	}

	/**
	 * Delete all snapshot rows of a run (run purge).
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function delete_run( $run_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'run_id' => (int) $run_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
