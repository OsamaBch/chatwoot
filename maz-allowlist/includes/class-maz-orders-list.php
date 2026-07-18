<?php
/**
 * Admin orders list filtering (HPOS + legacy) with search exception.
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integration point 4.1/4.2: the orders list table and its status count
 * badges, for both HPOS ({prefix}wc_orders via OrdersTableQuery) and the
 * legacy post-based store (WP_Query on wp_posts).
 *
 * SEARCH EXCEPTION: when the list search box is used ($_GET['s'] non-empty)
 * every rule is bypassed for that request so any order stays findable.
 */
class Maz_Allowlist_Orders_List {

	/**
	 * Wire hooks.
	 */
	public static function init() {
		// --- HPOS ---------------------------------------------------------
		// Low-level SQL clauses of OrdersTableQuery: covers the main list
		// query AND any count queries the list table issues in this request.
		add_filter( 'woocommerce_orders_table_query_clauses', array( __CLASS__, 'filter_hpos_clauses' ), 10, 3 );
		// Status badges ("All (1,234) | Processing (56)"): recomputed from
		// scratch, so no internal count path can leak true totals.
		add_filter( 'views_woocommerce_page_wc-orders', array( __CLASS__, 'filter_hpos_views' ) );

		// --- Legacy (post-based) ------------------------------------------
		add_action( 'pre_get_posts', array( __CLASS__, 'mark_legacy_query' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'filter_legacy_clauses' ), 10, 2 );
		// Legacy badges come from wp_count_posts(), a separate query.
		add_filter( 'wp_count_posts', array( __CLASS__, 'filter_legacy_counts' ), 10, 2 );
	}

	/**
	 * Is a non-empty list search active in this request?
	 *
	 * @return bool
	 */
	public static function is_search_request() {
		return isset( $_REQUEST['s'] ) && '' !== trim( (string) wp_unslash( $_REQUEST['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/* ---------------------------------------------------------------------
	 * HPOS
	 * ------------------------------------------------------------------- */

	/**
	 * Are we rendering the HPOS admin orders LIST screen (not edit/new)?
	 *
	 * @return bool
	 */
	private static function is_hpos_list_request() {
		if ( ! is_admin() ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? (string) wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'wc-orders' !== $page ) {
			return false;
		}
		$action = isset( $_GET['action'] ) ? (string) wp_unslash( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ! in_array( $action, array( 'edit', 'new' ), true );
	}

	/**
	 * Whether the current HPOS list request must be filtered.
	 *
	 * @return bool
	 */
	private static function should_filter_hpos() {
		return self::is_hpos_list_request()
			&& ! self::is_search_request()      // Search exception overrides every rule.
			&& maz_allowlist_filtering_active(); // Rules enabled + no bypass capability.
	}

	/**
	 * Inject visibility JOIN/WHERE into OrdersTableQuery clauses.
	 *
	 * @param array $clauses Associative clause map (join/where/...).
	 * @param mixed $query   OrdersTableQuery instance (unused).
	 * @param mixed $args    Parsed query args (unused).
	 * @return array
	 */
	public static function filter_hpos_clauses( $clauses, $query = null, $args = null ) {
		if ( ! is_array( $clauses ) || ! self::should_filter_hpos() ) {
			return $clauses;
		}

		$fragments = maz_visibility_sql_clause( 'hpos_list' );
		if ( '' === $fragments['where'] ) {
			return $clauses;
		}

		// Idempotency: never inject twice into the same clause set.
		$existing_join = isset( $clauses['join'] ) ? ( is_array( $clauses['join'] ) ? implode( ' ', $clauses['join'] ) : (string) $clauses['join'] ) : '';
		$existing_where = isset( $clauses['where'] ) ? ( is_array( $clauses['where'] ) ? implode( ' ', $clauses['where'] ) : (string) $clauses['where'] ) : '';
		if ( false !== strpos( $existing_join . $existing_where, 'maz_' ) ) {
			return $clauses;
		}

		foreach ( $fragments['join'] as $join ) {
			$clauses = self::append_clause( $clauses, 'join', $join, ' ' );
		}
		return self::append_clause( $clauses, 'where', $fragments['where'], ' AND ' );
	}

	/**
	 * Append SQL to a clause entry whether WC hands us strings or arrays.
	 *
	 * @param array  $clauses Clause map.
	 * @param string $key     Clause key.
	 * @param string $sql     Fragment to append.
	 * @param string $sep     Separator when concatenating strings.
	 * @return array
	 */
	private static function append_clause( array $clauses, $key, $sql, $sep ) {
		if ( isset( $clauses[ $key ] ) && is_array( $clauses[ $key ] ) ) {
			$clauses[ $key ][] = $sql;
			return $clauses;
		}
		$existing        = isset( $clauses[ $key ] ) ? trim( (string) $clauses[ $key ] ) : '';
		$clauses[ $key ] = ( '' === $existing ) ? $sql : $existing . $sep . $sql;
		return $clauses;
	}

	/**
	 * Rewrite the HPOS status badge counts with filtered numbers.
	 *
	 * @param array $views Status view links keyed by status slug.
	 * @return array
	 */
	public static function filter_hpos_views( $views ) {
		if ( ! is_array( $views ) || ! self::should_filter_hpos() ) {
			return $views;
		}

		$counts = self::hpos_status_counts();
		// "All" excludes trash and drafts, matching the WC list behaviour.
		$all = 0;
		foreach ( $counts as $status => $n ) {
			if ( ! in_array( $status, array( 'trash', 'auto-draft', 'wc-checkout-draft' ), true ) ) {
				$all += $n;
			}
		}

		foreach ( $views as $key => $html ) {
			if ( 'all' === $key ) {
				$n = $all;
			} elseif ( isset( $counts[ $key ] ) ) {
				$n = $counts[ $key ];
			} elseif ( preg_match( '/[?&]status(?:%5B%5D|\[\])?=([a-z0-9_-]+)/i', (string) $html, $m ) ) {
				$n = isset( $counts[ $m[1] ] ) ? $counts[ $m[1] ] : 0;
			} else {
				continue; // Unknown view (e.g. no count markup) — leave untouched.
			}
			$views[ $key ] = preg_replace( '/\([\d\s,.\x{00A0}\x{202F}]+\)/u', '(' . number_format_i18n( $n ) . ')', (string) $html );
		}

		return $views;
	}

	/**
	 * Filtered per-status counts on the HPOS orders table (cached 60 s per
	 * config fingerprint — the badge query scans the orders table).
	 *
	 * @return array<string,int> status slug => count.
	 */
	private static function hpos_status_counts() {
		global $wpdb;

		$cache_key = 'maz_allowlist_vc_' . md5( Maz_Allowlist_Config::fingerprint() );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$fragments = maz_visibility_sql_clause( 'hpos_list' );
		$joins     = implode( ' ', $fragments['join'] );
		$where     = '' !== $fragments['where'] ? ' AND ' . $fragments['where'] : '';
		$orders    = $wpdb->prefix . 'wc_orders';

		$rows = $wpdb->get_results(
			"SELECT {$orders}.status AS status, COUNT(*) AS n
			 FROM {$orders} {$joins}
			 WHERE {$orders}.type = 'shop_order'{$where}
			 GROUP BY {$orders}.status",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}

		set_transient( $cache_key, $counts, MINUTE_IN_SECONDS );
		return $counts;
	}

	/* ---------------------------------------------------------------------
	 * Legacy (post-based)
	 * ------------------------------------------------------------------- */

	/**
	 * Flag the admin shop_order main query for filtering (pre_get_posts).
	 *
	 * @param WP_Query $query Query.
	 */
	public static function mark_legacy_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( self::is_search_request() || '' !== trim( (string) $query->get( 's' ) ) ) {
			return; // Search exception.
		}
		if ( ! maz_allowlist_filtering_active() ) {
			return;
		}
		$query->set( 'maz_allowlist_filter', true );
	}

	/**
	 * Inject visibility JOIN/WHERE into the flagged legacy list query.
	 *
	 * @param array    $clauses join/where/... SQL strings.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public static function filter_legacy_clauses( $clauses, $query ) {
		if ( ! $query instanceof WP_Query || true !== $query->get( 'maz_allowlist_filter' ) ) {
			return $clauses;
		}
		if ( false !== strpos( (string) $clauses['join'] . (string) $clauses['where'], 'maz_' ) ) {
			return $clauses;
		}

		$fragments = maz_visibility_sql_clause( 'legacy_list' );
		if ( '' === $fragments['where'] ) {
			return $clauses;
		}

		$clauses['join']  .= ' ' . implode( ' ', $fragments['join'] );
		$clauses['where'] .= ' AND ' . $fragments['where'];
		return $clauses;
	}

	/**
	 * Filtered wp_count_posts() for shop_order — feeds the legacy list's
	 * status badges (a separate query from the list itself).
	 *
	 * @param object $counts Counts keyed by post status.
	 * @param string $type   Post type.
	 * @return object
	 */
	public static function filter_legacy_counts( $counts, $type ) {
		global $wpdb;

		if ( 'shop_order' !== $type || ! is_admin() || maz_allowlist_hpos_enabled() ) {
			return $counts;
		}
		if ( self::is_search_request() || ! maz_allowlist_filtering_active() ) {
			return $counts;
		}

		$fragments = maz_visibility_sql_clause( 'legacy_list' );
		if ( '' === $fragments['where'] ) {
			return $counts;
		}

		$cache_key = 'maz_allowlist_lc_' . md5( Maz_Allowlist_Config::fingerprint() );
		$cached    = get_transient( $cache_key );
		if ( ! is_array( $cached ) ) {
			$joins = implode( ' ', $fragments['join'] );
			$rows  = $wpdb->get_results(
				"SELECT {$wpdb->posts}.post_status AS status, COUNT(*) AS n
				 FROM {$wpdb->posts} {$joins}
				 WHERE {$wpdb->posts}.post_type = 'shop_order' AND {$fragments['where']}
				 GROUP BY {$wpdb->posts}.post_status",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$cached = array();
			foreach ( (array) $rows as $row ) {
				$cached[ (string) $row['status'] ] = (int) $row['n'];
			}
			set_transient( $cache_key, $cached, MINUTE_IN_SECONDS );
		}

		$filtered = new stdClass();
		foreach ( get_object_vars( $counts ) as $status => $unused ) {
			$filtered->{$status} = isset( $cached[ $status ] ) ? $cached[ $status ] : 0;
		}
		foreach ( $cached as $status => $n ) {
			if ( ! isset( $filtered->{$status} ) ) {
				$filtered->{$status} = $n;
			}
		}
		return $filtered;
	}
}
