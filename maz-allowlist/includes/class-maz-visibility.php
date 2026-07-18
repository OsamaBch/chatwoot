<?php
/**
 * Visibility engine — the single source of truth for all rule logic.
 *
 * Every integration point (orders list, count badges, Analytics clauses,
 * exports, legacy reports, dry-run preview) calls maz_visibility_sql_clause()
 * or maz_is_order_visible(). Rule semantics live ONLY here, so the list and
 * Analytics can never drift apart.
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds SQL JOIN/WHERE fragments and evaluates single orders in PHP with
 * exactly matching semantics.
 */
class Maz_Allowlist_Visibility {

	/**
	 * Contexts and how each one addresses the order and its totals.
	 *
	 * 'id_expr'    — SQL expression yielding the (effective) order ID. In
	 *                stats/lookup contexts, refund rows resolve to their parent
	 *                order so a refund is always shown/hidden with its order.
	 * 'joins'      — joins always needed by id_expr.
	 * 'gross'      — SQL expression for the gross total (incl. shipping + tax).
	 * 'net'        — SQL expression for the net total (excl. shipping + tax).
	 * 'gross_joins'/'net_joins' — joins needed only by the amount rule.
	 *
	 * @param string $context Context key.
	 * @return array|null
	 */
	private static function context_descriptor( $context ) {
		global $wpdb;
		$p = $wpdb->prefix;

		switch ( $context ) {
			// HPOS admin orders list: query runs on {prefix}wc_orders.
			case 'hpos_list':
				return array(
					'id_expr'     => "{$p}wc_orders.id",
					'joins'       => array(),
					'gross'       => "COALESCE({$p}wc_orders.total_amount, 0)",
					'gross_joins' => array(),
					// wc_orders.tax_amount is the full tax (incl. shipping tax);
					// shipping ex-tax lives in the operational data table.
					'net'         => "(COALESCE({$p}wc_orders.total_amount, 0) - COALESCE({$p}wc_orders.tax_amount, 0) - COALESCE(maz_opd.shipping_total_amount, 0))",
					'net_joins'   => array(
						"LEFT JOIN {$p}wc_order_operational_data AS maz_opd ON maz_opd.order_id = {$p}wc_orders.id",
					),
				);

			// Legacy (post-based) admin orders list: query runs on wp_posts.
			case 'legacy_list':
			case 'legacy_reports':
				// Legacy WC_Admin_Report queries use "FROM wp_posts AS posts".
				$posts = ( 'legacy_reports' === $context ) ? 'posts' : $wpdb->posts;
				$cast  = static fn( $alias ) => "CAST(COALESCE({$alias}.meta_value, '0') AS DECIMAL(20,6))";
				$join  = static fn( $alias, $key ) => "LEFT JOIN {$wpdb->postmeta} AS {$alias} ON {$alias}.post_id = {$posts}.ID AND {$alias}.meta_key = '{$key}'";
				return array(
					'id_expr'     => "{$posts}.ID",
					'joins'       => array(),
					// '_order_total' is stored as a STRING — must CAST, never string-compare.
					'gross'       => $cast( 'maz_pm_total' ),
					'gross_joins' => array( $join( 'maz_pm_total', '_order_total' ) ),
					// net = total - cart tax - shipping tax - shipping (matches wc_order_stats.net_total).
					'net'         => '(' . $cast( 'maz_pm_total' ) . ' - ' . $cast( 'maz_pm_tax' ) . ' - ' . $cast( 'maz_pm_shiptax' ) . ' - ' . $cast( 'maz_pm_ship' ) . ')',
					'net_joins'   => array(
						$join( 'maz_pm_total', '_order_total' ),
						$join( 'maz_pm_tax', '_order_tax' ),
						$join( 'maz_pm_shiptax', '_order_shipping_tax' ),
						$join( 'maz_pm_ship', '_order_shipping' ),
					),
				);

			// Analytics: query runs on {prefix}wc_order_stats (revenue/orders reports).
			// Refund rows (parent_id != 0) resolve to their parent order.
			case 'order_stats':
				$id = "COALESCE(NULLIF({$p}wc_order_stats.parent_id, 0), {$p}wc_order_stats.order_id)";
				return self::stats_based_descriptor( $id, array() );

			// Analytics: query runs on a lookup table that carries order_id.
			case 'product_lookup':
				return self::lookup_descriptor( "{$p}wc_order_product_lookup" );
			case 'coupon_lookup':
				return self::lookup_descriptor( "{$p}wc_order_coupon_lookup" );
			case 'tax_lookup':
				return self::lookup_descriptor( "{$p}wc_order_tax_lookup" );
		}

		return null;
	}

	/**
	 * Descriptor for a lookup table with an order_id column: route through
	 * wc_order_stats to resolve refunds to their parent order.
	 *
	 * @param string $table Fully-prefixed lookup table name.
	 * @return array
	 */
	private static function lookup_descriptor( $table ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$joins = array( "LEFT JOIN {$p}wc_order_stats AS maz_os ON maz_os.order_id = {$table}.order_id" );
		$id    = "COALESCE(NULLIF(maz_os.parent_id, 0), {$table}.order_id)";
		return self::stats_based_descriptor( $id, $joins );
	}

	/**
	 * Shared tail for stats-based contexts: amounts come from the (parent)
	 * order's wc_order_stats row.
	 *
	 * @param string   $id_expr Effective order-ID SQL expression.
	 * @param string[] $joins   Joins required by $id_expr.
	 * @return array
	 */
	private static function stats_based_descriptor( $id_expr, array $joins ) {
		global $wpdb;
		$p           = $wpdb->prefix;
		$amount_join = array_merge( $joins, array( "LEFT JOIN {$p}wc_order_stats AS maz_ps ON maz_ps.order_id = {$id_expr}" ) );
		return array(
			'id_expr'     => $id_expr,
			'joins'       => $joins,
			'gross'       => 'COALESCE(maz_ps.total_sales, 0)',
			'gross_joins' => $amount_join,
			'net'         => 'COALESCE(maz_ps.net_total, 0)',
			'net_joins'   => $amount_join,
		);
	}

	/**
	 * Build the JOIN/WHERE fragments implementing the enabled rules for a context.
	 *
	 * The returned 'where' is a self-contained boolean expression that is TRUE
	 * for VISIBLE orders (no leading AND). 'join' is a list of unique JOIN
	 * strings. Empty array/string when no rule is enabled.
	 *
	 * Bypass (capability) and the kill switch are the callers' concern — this
	 * function is pure rule logic.
	 *
	 * @param string     $context One of: hpos_list, legacy_list, order_stats,
	 *                            product_lookup, coupon_lookup, tax_lookup, legacy_reports.
	 * @param array|null $config  Config override (used by the dry-run preview); null = saved config.
	 * @return array{join: string[], where: string}
	 */
	public static function clauses( $context, $config = null ) {
		$empty  = array(
			'join'  => array(),
			'where' => '',
		);
		$config = is_array( $config ) ? $config : Maz_Allowlist_Config::get();
		$desc   = self::context_descriptor( $context );
		if ( null === $desc ) {
			return $empty;
		}

		$joins = $desc['joins'];
		$conds = array();

		// (a) Allowlist: visible when the order ID is in the allowlist table.
		if ( ! empty( $config['rules']['allowlist']['enabled'] ) ) {
			$joins[] = 'LEFT JOIN ' . Maz_Allowlist_Table::table_name() . " AS maz_al ON maz_al.order_id = {$desc['id_expr']}";
			$conds[] = 'maz_al.order_id IS NOT NULL';
		}

		// (b) Deterministic random sample: hidden when (CRC32(id.seed) % 100) >= visible_pct.
		// Never rand() at query time — pagination and totals must be stable.
		if ( ! empty( $config['rules']['sample']['enabled'] ) ) {
			$seed    = esc_sql( (string) $config['rules']['sample']['seed'] );
			$pct     = (int) $config['rules']['sample']['visible_pct'];
			$conds[] = "(CRC32(CONCAT({$desc['id_expr']}, '{$seed}')) % 100) < {$pct}";
		}

		// (c) Amount threshold on net (excl. shipping + tax) or gross total.
		if ( ! empty( $config['rules']['amount']['enabled'] ) ) {
			$basis  = ( 'gross' === $config['rules']['amount']['basis'] ) ? 'gross' : 'net';
			$amount = $desc[ $basis ];
			$joins  = array_merge( $joins, $desc[ $basis . '_joins' ] );
			$x      = sprintf( '%.6F', (float) $config['rules']['amount']['x'] );
			$y      = sprintf( '%.6F', (float) $config['rules']['amount']['y'] );
			switch ( $config['rules']['amount']['mode'] ) {
				case 'below': // Hide below X → visible when amount >= X.
					$conds[] = "{$amount} >= {$x}";
					break;
				case 'band': // Hide within [X, Y] → visible outside the band.
					$conds[] = "({$amount} < {$x} OR {$amount} > {$y})";
					break;
				case 'above': // Hide above X → visible when amount <= X.
				default:
					$conds[] = "{$amount} <= {$x}";
					break;
			}
		}

		if ( ! $conds ) {
			return $empty;
		}

		// Precedence:
		//   'and' — pass EVERY enabled rule to be visible (any rule can hide).
		//   'or'  — pass AT LEAST ONE enabled rule to be visible.
		$glue = ( 'or' === $config['precedence'] ) ? ' OR ' : ' AND ';

		return array(
			'join'  => array_values( array_unique( $joins ) ),
			'where' => '( ' . implode( $glue, $conds ) . ' )',
		);
	}

	/**
	 * PHP-side evaluation for a single order. Mirrors clauses() exactly.
	 *
	 * @param int        $order_id Order ID.
	 * @param array|null $config   Config override; null = saved config.
	 * @return bool TRUE when visible under the enabled rules.
	 */
	public static function is_order_visible( $order_id, $config = null ) {
		global $wpdb;
		$config   = is_array( $config ) ? $config : Maz_Allowlist_Config::get();
		$order_id = (int) $order_id;
		$results  = array();

		if ( ! empty( $config['rules']['allowlist']['enabled'] ) ) {
			$table     = Maz_Allowlist_Table::table_name();
			$results[] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! empty( $config['rules']['sample']['enabled'] ) ) {
			// PHP crc32() and MySQL CRC32() implement the same CRC-32 (zlib);
			// both operate here on the identical byte string "{id}{seed}".
			$hash      = crc32( $order_id . $config['rules']['sample']['seed'] );
			$results[] = ( $hash % 100 ) < (int) $config['rules']['sample']['visible_pct'];
		}

		if ( ! empty( $config['rules']['amount']['enabled'] ) ) {
			$order  = wc_get_order( $order_id );
			$gross  = $order ? (float) $order->get_total() : 0.0;
			$net    = $order ? $gross - (float) $order->get_total_tax() - (float) $order->get_shipping_total() : 0.0;
			$amount = ( 'gross' === $config['rules']['amount']['basis'] ) ? $gross : $net;
			$x      = (float) $config['rules']['amount']['x'];
			$y      = (float) $config['rules']['amount']['y'];
			switch ( $config['rules']['amount']['mode'] ) {
				case 'below':
					$results[] = $amount >= $x;
					break;
				case 'band':
					$results[] = ( $amount < $x || $amount > $y );
					break;
				case 'above':
				default:
					$results[] = $amount <= $x;
					break;
			}
		}

		if ( ! $results ) {
			return true; // No rules enabled.
		}

		return ( 'or' === $config['precedence'] )
			? in_array( true, $results, true )
			: ! in_array( false, $results, true );
	}
}

/**
 * Single source of truth: SQL fragments implementing the enabled visibility
 * rules for a given query context.
 *
 * @param string     $context Context key (see Maz_Allowlist_Visibility::clauses()).
 * @param array|null $config  Optional config override (dry-run preview).
 * @return array{join: string[], where: string} 'where' is TRUE for visible orders; both empty when no rule is enabled.
 */
function maz_visibility_sql_clause( $context, $config = null ) {
	return Maz_Allowlist_Visibility::clauses( $context, $config );
}

/**
 * Single-order visibility check with semantics identical to the SQL path.
 * Pure rule evaluation: does NOT consider the bypass capability.
 *
 * @param int        $order_id Order ID.
 * @param array|null $config   Optional config override.
 * @return bool
 */
function maz_is_order_visible( $order_id, $config = null ) {
	return Maz_Allowlist_Visibility::is_order_visible( $order_id, $config );
}

/**
 * Can the current user bypass all rules?
 *
 * @return bool
 */
function maz_allowlist_user_can_bypass() {
	return is_user_logged_in() && current_user_can( 'maz_view_all_orders' );
}

/**
 * Should filtering apply to the current user right now?
 * True when at least one rule is enabled AND the user either lacks the bypass
 * capability or the "apply to bypass users too" testing mode is on.
 *
 * @return bool
 */
function maz_allowlist_filtering_active() {
	if ( ! Maz_Allowlist_Config::any_rule_active() ) {
		return false;
	}
	if ( Maz_Allowlist_Config::apply_to_bypass() ) {
		return true;
	}
	return ! maz_allowlist_user_can_bypass();
}
