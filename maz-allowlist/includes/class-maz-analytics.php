<?php
/**
 * WooCommerce Analytics integration (4.3–4.5).
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Injects the visibility fragments into every Analytics report query via the
 * woocommerce_analytics_clauses_{join|where}[_{context}] filters, and keeps
 * the report cache honest.
 *
 * Analytics reads {prefix}wc_order_stats and the order_* lookup tables — the
 * orders-list filters never touch those queries, hence this separate module.
 *
 * This module is registered on EVERY request type on purpose: the Analytics
 * UI is a React client talking to /wc-analytics/* REST endpoints (is_admin()
 * is false there) and the Analytics CSV export runs inside Action Scheduler.
 * The filters below only ever fire on Analytics report queries; no
 * storefront/customer request executes those.
 */
class Maz_Allowlist_Analytics {

	/**
	 * Analytics clause context => visibility-engine context.
	 *
	 * Enumerated coverage:
	 *   orders   → orders_subquery, orders_stats_total, orders_stats_interval (also Revenue + overview)
	 *   products → products(+_subquery/_stats_*), variations(+_subquery/_stats_*), categories
	 *   coupons  → coupons(+_stats_*)
	 *   taxes    → taxes(+_stats_*)
	 * Leaderboards and performance-indicators read through these same data
	 * stores / REST reports, so they are covered by the same clauses.
	 * NOT covered (documented in README): customers and downloads reports,
	 * whose base queries do not carry a per-order row we can safely join.
	 *
	 * @var array<string,string>
	 */
	const CONTEXT_MAP = array(
		'orders'                   => 'order_stats',
		'orders_subquery'          => 'order_stats',
		'orders_stats_total'       => 'order_stats',
		'orders_stats_interval'    => 'order_stats',
		'products'                 => 'product_lookup',
		'products_subquery'        => 'product_lookup',
		'products_stats_total'     => 'product_lookup',
		'products_stats_interval'  => 'product_lookup',
		'variations'               => 'product_lookup',
		'variations_subquery'      => 'product_lookup',
		'variations_stats_total'   => 'product_lookup',
		'variations_stats_interval' => 'product_lookup',
		'categories'               => 'product_lookup',
		'coupons'                  => 'coupon_lookup',
		'coupons_stats_total'      => 'coupon_lookup',
		'coupons_stats_interval'   => 'coupon_lookup',
		'taxes'                    => 'tax_lookup',
		'taxes_stats_total'        => 'tax_lookup',
		'taxes_stats_interval'     => 'tax_lookup',
	);

	/**
	 * Wire hooks.
	 */
	public static function init() {
		// Per-context filters (the documented Analytics extension surface).
		foreach ( array_keys( self::CONTEXT_MAP ) as $context ) {
			add_filter(
				"woocommerce_analytics_clauses_join_{$context}",
				static function ( $clauses ) use ( $context ) {
					return Maz_Allowlist_Analytics::add_join( $clauses, $context );
				}
			);
			add_filter(
				"woocommerce_analytics_clauses_where_{$context}",
				static function ( $clauses ) use ( $context ) {
					return Maz_Allowlist_Analytics::add_where( $clauses, $context );
				}
			);
		}

		// Generic variants (fire with the context as 2nd arg on WC versions
		// that support them). The idempotency marker below makes it safe if
		// BOTH the generic and the per-context filter run on one query.
		add_filter( 'woocommerce_analytics_clauses_join', array( __CLASS__, 'add_join' ), 10, 2 );
		add_filter( 'woocommerce_analytics_clauses_where', array( __CLASS__, 'add_where' ), 10, 2 );

		// 4.5 Report caching: cached numbers cannot express "who asked"
		// (bypass capability) or the current rule config, so while any rule
		// is active the report cache is disabled outright…
		add_filter( 'woocommerce_analytics_report_should_use_cache', array( __CLASS__, 'maybe_disable_cache' ), 10, 2 );
		add_filter( 'experimental_woocommerce_analytics_report_should_use_cache', array( __CLASS__, 'maybe_disable_cache' ), 10, 2 );

		// …and every config change additionally flushes whatever was cached
		// before, so switching rules off never serves stale filtered numbers.
		add_action( 'maz_allowlist_config_changed', array( __CLASS__, 'invalidate_report_cache' ) );
	}

	/**
	 * Append visibility JOINs for an Analytics context.
	 *
	 * @param mixed  $clauses Array of JOIN SQL strings.
	 * @param string $context Analytics clause context.
	 * @return mixed
	 */
	public static function add_join( $clauses, $context = '' ) {
		$fragments = self::fragments_for( $clauses, $context );
		if ( null === $fragments ) {
			return $clauses;
		}
		foreach ( $fragments['join'] as $join ) {
			$clauses[] = $join;
		}
		return $clauses;
	}

	/**
	 * Append the visibility WHERE condition for an Analytics context.
	 *
	 * @param mixed  $clauses Array of WHERE SQL strings (each starting with AND/OR).
	 * @param string $context Analytics clause context.
	 * @return mixed
	 */
	public static function add_where( $clauses, $context = '' ) {
		$fragments = self::fragments_for( $clauses, $context );
		if ( null === $fragments ) {
			return $clauses;
		}
		$clauses[] = ' AND /* maz_allowlist */ ' . $fragments['where'];
		return $clauses;
	}

	/**
	 * Shared guard: returns the visibility fragments, or null when nothing
	 * must be injected (unknown context, rules off, bypass, already injected).
	 *
	 * @param mixed  $clauses Clause list as passed by the filter.
	 * @param string $context Analytics clause context.
	 * @return array|null
	 */
	private static function fragments_for( $clauses, $context ) {
		if ( ! is_array( $clauses ) || ! isset( self::CONTEXT_MAP[ $context ] ) ) {
			return null;
		}
		if ( ! maz_allowlist_filtering_active() ) {
			return null;
		}
		// Idempotency: both the generic and the per-context filter may fire
		// on the same clause set — inject only once.
		foreach ( $clauses as $clause ) {
			if ( is_string( $clause ) && false !== strpos( $clause, 'maz_' ) ) {
				return null;
			}
		}
		$fragments = maz_visibility_sql_clause( self::CONTEXT_MAP[ $context ] );
		return ( '' === $fragments['where'] ) ? null : $fragments;
	}

	/**
	 * Disable the Analytics report cache while any rule is active.
	 *
	 * The cache key does not include the rule config nor the requesting
	 * user's bypass state; a shared key would leak unfiltered numbers to
	 * filtered users (or vice versa). Correctness beats cache hits here.
	 *
	 * @param bool  $should_use_cache Original decision.
	 * @param mixed $cache_key        Cache key (unused).
	 * @return bool
	 */
	public static function maybe_disable_cache( $should_use_cache, $cache_key = null ) {
		if ( Maz_Allowlist_Config::any_rule_active() ) {
			return false;
		}
		return $should_use_cache;
	}

	/**
	 * Flush the whole Analytics report cache (config changed).
	 */
	public static function invalidate_report_cache() {
		if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Cache' ) ) {
			\Automattic\WooCommerce\Admin\API\Reports\Cache::invalidate();
		}
	}
}
