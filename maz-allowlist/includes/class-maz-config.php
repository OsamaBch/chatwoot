<?php
/**
 * Versioned configuration store.
 *
 * @package maz-allowlist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single option array holding all rule configuration, with a schema version
 * and an upgrade routine.
 */
class Maz_Allowlist_Config {

	const OPTION         = 'maz_allowlist_config';
	const REV_OPTION     = 'maz_allowlist_list_rev'; // Bumped on every allowlist table change; part of the config fingerprint.
	const SCHEMA_VERSION = 1;

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default configuration.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			// 'and': an order must pass EVERY enabled rule to be visible (any rule can hide it).
			// 'or' : an order is visible if it passes AT LEAST ONE enabled rule (hidden only when every enabled rule hides it).
			'precedence'     => 'and',
			'rules'          => array(
				'allowlist' => array(
					'enabled' => false,
				),
				'sample'    => array(
					'enabled'     => false,
					'visible_pct' => 10,
					'seed'        => '', // Filled on first read; reroll via settings page.
				),
				'amount'    => array(
					'enabled' => false,
					'mode'    => 'above', // above | below | band. 'above' = hide orders with total above X, etc.
					'x'       => 0.0,     // Threshold (above/below) or band lower bound.
					'y'       => 0.0,     // Band upper bound.
					'basis'   => 'net',   // net (excl. shipping + tax) | gross.
				),
			),
		);
	}

	/**
	 * Get full config (merged over defaults, seed guaranteed non-empty).
	 *
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( self::OPTION, array() );
		$config = self::merge_defaults( is_array( $stored ) ? $stored : array() );

		if ( '' === $config['rules']['sample']['seed'] ) {
			$config['rules']['sample']['seed'] = self::generate_seed();
			update_option( self::OPTION, $config, false );
		}

		self::$cache = $config;
		return $config;
	}

	/**
	 * Deep-merge stored values over defaults so new keys appear automatically.
	 *
	 * @param array $stored Stored option value.
	 * @return array
	 */
	private static function merge_defaults( array $stored ) {
		$defaults = self::defaults();
		$config   = $defaults;
		foreach ( array( 'schema_version', 'precedence' ) as $key ) {
			if ( isset( $stored[ $key ] ) ) {
				$config[ $key ] = $stored[ $key ];
			}
		}
		foreach ( $defaults['rules'] as $rule => $fields ) {
			foreach ( $fields as $field => $default_value ) {
				if ( isset( $stored['rules'][ $rule ][ $field ] ) ) {
					$config['rules'][ $rule ][ $field ] = $stored['rules'][ $rule ][ $field ];
				}
			}
		}
		return $config;
	}

	/**
	 * Create the option on activation if missing.
	 */
	public static function ensure_exists() {
		if ( false === get_option( self::OPTION, false ) ) {
			$config                            = self::defaults();
			$config['rules']['sample']['seed'] = self::generate_seed();
			add_option( self::OPTION, $config, '', false );
		}
		if ( false === get_option( self::REV_OPTION, false ) ) {
			add_option( self::REV_OPTION, 1, '', false );
		}
	}

	/**
	 * Schema upgrade routine. Runs on every load; no-op when current.
	 */
	public static function maybe_upgrade() {
		$stored = get_option( self::OPTION, false );
		if ( false === $stored || ! is_array( $stored ) ) {
			return; // Created on activation / first save.
		}
		$version = isset( $stored['schema_version'] ) ? (int) $stored['schema_version'] : 0;
		if ( $version >= self::SCHEMA_VERSION ) {
			return;
		}

		// Future migrations go here, e.g.:
		// if ( $version < 2 ) { ...transform...; $version = 2; }

		$stored['schema_version'] = self::SCHEMA_VERSION;
		update_option( self::OPTION, $stored, false );
		self::$cache = null;
	}

	/**
	 * Sanitize a raw (form-posted) config into a valid config array.
	 *
	 * @param array $raw    Raw values.
	 * @param array $errors Filled with human-readable validation errors.
	 * @return array Sanitized config.
	 */
	public static function sanitize( array $raw, array &$errors = array() ) {
		$current = self::get();
		$config  = self::defaults();

		$config['precedence'] = ( isset( $raw['precedence'] ) && 'or' === $raw['precedence'] ) ? 'or' : 'and';

		$config['rules']['allowlist']['enabled'] = ! empty( $raw['allowlist_enabled'] );

		$config['rules']['sample']['enabled'] = ! empty( $raw['sample_enabled'] );
		$pct                                  = isset( $raw['sample_visible_pct'] ) ? (int) $raw['sample_visible_pct'] : 10;
		if ( $pct < 0 || $pct > 100 ) {
			$errors[] = __( 'Sample visible percentage must be between 0 and 100.', 'maz-allowlist' );
			$pct      = max( 0, min( 100, $pct ) );
		}
		$config['rules']['sample']['visible_pct'] = $pct;
		// Seed is never set from the form; it only changes via the explicit "Reroll seed" action.
		$config['rules']['sample']['seed'] = $current['rules']['sample']['seed'];

		$config['rules']['amount']['enabled'] = ! empty( $raw['amount_enabled'] );
		$mode                                 = isset( $raw['amount_mode'] ) ? $raw['amount_mode'] : 'above';
		$config['rules']['amount']['mode']    = in_array( $mode, array( 'above', 'below', 'band' ), true ) ? $mode : 'above';
		$config['rules']['amount']['basis']   = ( isset( $raw['amount_basis'] ) && 'gross' === $raw['amount_basis'] ) ? 'gross' : 'net';
		$config['rules']['amount']['x']       = isset( $raw['amount_x'] ) ? (float) str_replace( ',', '.', (string) $raw['amount_x'] ) : 0.0;
		$config['rules']['amount']['y']       = isset( $raw['amount_y'] ) ? (float) str_replace( ',', '.', (string) $raw['amount_y'] ) : 0.0;

		if ( 'band' === $config['rules']['amount']['mode'] && $config['rules']['amount']['y'] < $config['rules']['amount']['x'] ) {
			$errors[] = __( 'Amount band: upper bound Y must be greater than or equal to lower bound X.', 'maz-allowlist' );
		}

		// Multi-currency guard: a bare numeric threshold is meaningless across currencies.
		if ( $config['rules']['amount']['enabled'] && count( self::detect_currencies() ) > 1 ) {
			$config['rules']['amount']['enabled'] = false;
			$errors[]                             = __( 'Amount threshold rule was NOT enabled: orders exist in more than one currency, so a single numeric threshold would be meaningless. ', 'maz-allowlist' );
		}

		return $config;
	}

	/**
	 * Persist a new config, record the audit entry, and notify listeners.
	 *
	 * @param array  $new_config Sanitized config.
	 * @param string $action     Audit action label.
	 */
	public static function update( array $new_config, $action = 'config_save' ) {
		$old = self::get();
		update_option( self::OPTION, $new_config, false );
		self::$cache = null;

		Maz_Allowlist_Audit_Log::record( $action, self::describe_diff( $old, $new_config ), $new_config['rules']['sample']['seed'] );

		/**
		 * Fires after any visibility-affecting config change.
		 * The Analytics module uses this to invalidate the report cache.
		 *
		 * @param array $old Old config.
		 * @param array $new New config.
		 */
		do_action( 'maz_allowlist_config_changed', $old, $new_config );
	}

	/**
	 * Reroll the deterministic-sample seed.
	 *
	 * @return string New seed.
	 */
	public static function reroll_seed() {
		$config                            = self::get();
		$config['rules']['sample']['seed'] = self::generate_seed();
		self::update( $config, 'seed_reroll' );
		return $config['rules']['sample']['seed'];
	}

	/**
	 * Generate a random seed. Alphanumeric only, so it is byte-identical in
	 * PHP string context and inside SQL CONCAT().
	 *
	 * @return string
	 */
	public static function generate_seed() {
		return wp_generate_password( 16, false, false );
	}

	/**
	 * Bump the allowlist revision (called on import/clear).
	 */
	public static function bump_list_rev() {
		update_option( self::REV_OPTION, (int) get_option( self::REV_OPTION, 1 ) + 1, false );
	}

	/**
	 * Is at least one rule enabled?
	 *
	 * @return bool
	 */
	public static function any_rule_active() {
		$config = self::get();
		foreach ( $config['rules'] as $rule ) {
			if ( ! empty( $rule['enabled'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fingerprint of everything that affects visibility. Changes whenever the
	 * outcome of the rules could change (config edit, seed reroll, allowlist
	 * import/clear). Used for cache salting and notice re-display.
	 *
	 * @return string
	 */
	public static function fingerprint() {
		$config = self::get();
		return md5(
			wp_json_encode(
				array(
					'rules'      => $config['rules'],
					'precedence' => $config['precedence'],
					'list_rev'   => (int) get_option( self::REV_OPTION, 1 ),
				)
			)
		);
	}

	/**
	 * Distinct currencies present in existing orders (cached 1 hour).
	 *
	 * @return string[]
	 */
	public static function detect_currencies() {
		$cached = get_transient( 'maz_allowlist_currencies' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		if ( maz_allowlist_hpos_enabled() ) {
			$currencies = $wpdb->get_col( "SELECT DISTINCT currency FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND currency IS NOT NULL AND currency <> ''" );
		} else {
			$currencies = $wpdb->get_col(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_order'
				 WHERE pm.meta_key = '_order_currency' AND pm.meta_value <> ''"
			);
		}
		$currencies = is_array( $currencies ) ? array_values( array_filter( $currencies ) ) : array();
		set_transient( 'maz_allowlist_currencies', $currencies, HOUR_IN_SECONDS );
		return $currencies;
	}

	/**
	 * Human-readable diff between two configs, for the audit log.
	 *
	 * @param array $old Old config.
	 * @param array $new New config.
	 * @return string
	 */
	public static function describe_diff( array $old, array $new ) {
		$changes = array();
		if ( $old['precedence'] !== $new['precedence'] ) {
			$changes[] = sprintf( 'precedence: %s → %s', $old['precedence'], $new['precedence'] );
		}
		foreach ( $new['rules'] as $rule => $fields ) {
			foreach ( $fields as $field => $value ) {
				$old_value = isset( $old['rules'][ $rule ][ $field ] ) ? $old['rules'][ $rule ][ $field ] : null;
				if ( $old_value !== $value ) {
					$fmt       = static function ( $v ) {
						if ( is_bool( $v ) ) {
							return $v ? 'on' : 'off';
						}
						return (string) $v;
					};
					$changes[] = sprintf( '%s.%s: %s → %s', $rule, $field, $fmt( $old_value ), $fmt( $value ) );
				}
			}
		}
		return $changes ? implode( '; ', $changes ) : 'no changes';
	}
}

/**
 * Whether HPOS (custom order tables) is the authoritative order store.
 *
 * @return bool
 */
function maz_allowlist_hpos_enabled() {
	return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}
