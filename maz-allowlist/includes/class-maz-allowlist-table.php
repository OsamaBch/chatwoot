<?php
/**
 * Allowlist storage: custom table {prefix}maz_order_allowlist.
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order-ID allowlist backed by a real table so visibility queries can JOIN
 * against it instead of building huge IN() clauses.
 */
class Maz_Allowlist_Table {

	const IMPORT_CHUNK = 1000;

	/**
	 * Fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'maz_order_allowlist';
	}

	/**
	 * Create the table (activation).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				order_id BIGINT UNSIGNED NOT NULL,
				added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (order_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Number of IDs currently in the allowlist.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove every ID.
	 *
	 * @return int Rows removed.
	 */
	public static function clear() {
		global $wpdb;
		$table   = self::table_name();
		$removed = self::count();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Maz_Allowlist_Config::bump_list_rev();
		return $removed;
	}

	/**
	 * Tokenize pasted text / CSV content into order IDs.
	 * Accepts newline-, comma-, semicolon- and space-separated values.
	 *
	 * @param string $text        Raw input.
	 * @param array  $bad_tokens  Filled with tokens that are not positive integers.
	 * @return int[] Unique valid IDs.
	 */
	public static function parse_ids( $text, array &$bad_tokens = array() ) {
		$tokens = preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$ids    = array();
		foreach ( $tokens as $token ) {
			$token = trim( $token, "\"' \t\r\n" );
			if ( '' === $token ) {
				continue;
			}
			// Tolerate a leading '#' (as shown in the orders list UI).
			$candidate = ltrim( $token, '#' );
			if ( preg_match( '/^[0-9]+$/', $candidate ) && (int) $candidate > 0 ) {
				$ids[ (int) $candidate ] = true;
			} else {
				$bad_tokens[] = $token;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Insert IDs (chunked INSERT IGNORE), then report which of them do not
	 * correspond to an existing order. Nothing is silently dropped: invalid
	 * tokens and nonexistent IDs are returned for display.
	 *
	 * @param int[] $ids Valid positive integers.
	 * @return array{added:int, duplicates:int, nonexistent:int[]}
	 */
	public static function add_ids( array $ids ) {
		global $wpdb;
		$table = self::table_name();
		$ids   = array_values( array_unique( array_map( 'intval', $ids ) ) );

		$before = self::count();
		foreach ( array_chunk( $ids, self::IMPORT_CHUNK ) as $chunk ) {
			$values = implode( ',', array_map( static fn( $id ) => '(' . (int) $id . ')', $chunk ) );
			$wpdb->query( "INSERT IGNORE INTO {$table} (order_id) VALUES {$values}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$added = self::count() - $before;

		Maz_Allowlist_Config::bump_list_rev();

		return array(
			'added'       => max( 0, $added ),
			'duplicates'  => count( $ids ) - max( 0, $added ),
			'nonexistent' => self::find_nonexistent(),
		);
	}

	/**
	 * IDs in the allowlist with no matching order — computed with a single
	 * anti-join against the order store (HPOS or legacy), never IN().
	 *
	 * @param int $limit Cap on how many IDs to return (a count of the rest is cheap to derive).
	 * @return int[]
	 */
	public static function find_nonexistent( $limit = 500 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = (int) $limit;

		if ( maz_allowlist_hpos_enabled() ) {
			$sql = "SELECT al.order_id FROM {$table} al
					LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id = al.order_id AND o.type = 'shop_order'
					WHERE o.id IS NULL ORDER BY al.order_id LIMIT {$limit}";
		} else {
			$sql = "SELECT al.order_id FROM {$table} al
					LEFT JOIN {$wpdb->posts} p ON p.ID = al.order_id AND p.post_type = 'shop_order'
					WHERE p.ID IS NULL ORDER BY al.order_id LIMIT {$limit}";
		}

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
