<?php
/**
 * Checkout optimization configuration store.
 *
 * Single autoloaded option (`storedash_checkout_config`) holding the
 * merchant's checkout-optimization settings on the WP side. The
 * authoritative copy lives in Supabase (`checkout_settings`); the Storedash
 * dashboard pushes it here via `POST storedash/v1/checkout/settings`.
 * Same contract as `FBT_Config`: `defaults()`, static per-request cache,
 * partial `update()` with a `config_hash` for drift detection, and a strict
 * `sanitize()` whitelist so a malformed push can never break the storefront.
 *
 * Three feature groups, every one OFF by default:
 *
 *   - `funnel`            — count-only checkout funnel (cart → checkout → order).
 *   - `free_shipping_bar` — "spend X more for free shipping" bar (follow-up task).
 *   - `delivery_estimate` — estimated delivery date at checkout (follow-up task).
 *
 * The key shape is a cross-repo contract: woo-dash POSTs exactly these keys
 * (partial updates allowed). Do not rename keys without a woo-dash change.
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout config.
 *
 * @since 1.29.0
 */
class Config {

	/**
	 * Option name. Autoloaded: read on every front-end request to decide
	 * whether any component registers hooks.
	 */
	const OPTION = 'storedash_checkout_config';

	/**
	 * Feature group keys.
	 */
	const FEATURE_FUNNEL            = 'funnel';
	const FEATURE_FREE_SHIPPING_BAR = 'free_shipping_bar';
	const FEATURE_DELIVERY_ESTIMATE = 'delivery_estimate';

	/**
	 * All feature groups.
	 *
	 * @var string[]
	 */
	const FEATURES = array(
		self::FEATURE_FUNNEL,
		self::FEATURE_FREE_SHIPPING_BAR,
		self::FEATURE_DELIVERY_ESTIMATE,
	);

	/**
	 * Enumerations.
	 */
	const THRESHOLD_MODES = array( 'manual' );
	const SHOW_ON_VALUES  = array( 'cart', 'checkout' );
	const TAX_MODES       = array( 'woo', 'incl', 'excl' );
	const RULE_KINDS      = array( 'delivery', 'pickup', 'hide' );
	const DATE_FORMATS    = array( 'short', 'long' );

	/**
	 * Limits.
	 */
	const MAX_RULES    = 50;
	const MAX_HOLIDAYS = 100;
	const MAX_DAYS     = 60;
	const MAX_COPY_LEN = 200;

	/**
	 * Cached config for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default configuration.
	 *
	 * Copy defaults are empty strings on purpose: empty means "use the
	 * plugin's own translated string".
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'funnel'            => array(
				'enabled' => false,
			),
			'free_shipping_bar' => array(
				'enabled'            => false,
				'threshold'          => 0.0,
				'threshold_mode'     => 'manual',
				'show_on'            => array( 'cart', 'checkout' ),
				'include_tax'        => 'woo',
				'ignore_discounts'   => false,
				'hide_when_unlocked' => false,
				'copy'               => array(
					'remaining' => '',
					'unlocked'  => '',
				),
				'tokens'             => array(
					'bg'     => '',
					'fg'     => '',
					'accent' => '',
					'radius' => '',
				),
			),
			'delivery_estimate' => array(
				'enabled'     => false,
				'cutoff_time' => '14:00',
				'ship_days'   => array( 1, 2, 3, 4, 5 ),
				'default'     => array(
					'min_days' => 1,
					'max_days' => 3,
				),
				'rules'       => array(),
				'holidays'    => array(),
				'copy'        => array(
					'single' => '',
					'range'  => '',
					'pickup' => '',
				),
				'date_format' => 'short',
			),
			'config_hash'       => '',
			'updated_at'        => '',
		);
	}

	/**
	 * Current configuration, merged over defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = self::merge_defaults( $stored );

		return self::$cache;
	}

	/**
	 * Whether a feature group is switched on.
	 *
	 * @param string $feature One of FEATURES.
	 * @return bool
	 */
	public static function is_feature_active( string $feature ): bool {
		if ( ! in_array( $feature, self::FEATURES, true ) ) {
			return false;
		}
		$config = self::get();

		return ! empty( $config[ $feature ]['enabled'] );
	}

	/**
	 * Persist a (partial) configuration update.
	 *
	 * Only groups/keys present in `$partial` are touched; nested `copy`,
	 * `tokens` and `default` maps merge key-by-key. Lists (`show_on`,
	 * `ship_days`, `rules`, `holidays`) replace wholesale.
	 *
	 * @param array $partial Sanitized partial config (see sanitize()).
	 * @return array The full config after the update.
	 */
	public static function update( array $partial ): array {
		$current = self::get();
		$merged  = self::merge_partial( $current, $partial );

		$hashable = $merged;
		unset( $hashable['config_hash'], $hashable['updated_at'] );
		$merged['config_hash'] = md5( (string) wp_json_encode( $hashable ) );
		$merged['updated_at']  = current_time( 'mysql', true );

		update_option( self::OPTION, $merged, true );
		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Sanitize an inbound payload from the dashboard. Unknown keys are
	 * dropped; invalid values are dropped (not coerced to defaults) so a bad
	 * field never silently resets a merchant's previous value.
	 *
	 * @param array $raw Raw payload.
	 * @return array Partial config safe to pass to update().
	 */
	public static function sanitize( array $raw ): array {
		$clean = array();

		if ( isset( $raw['funnel'] ) && is_array( $raw['funnel'] ) ) {
			$group = array();
			if ( array_key_exists( 'enabled', $raw['funnel'] ) ) {
				$group['enabled'] = self::to_bool( $raw['funnel']['enabled'] );
			}
			if ( $group ) {
				$clean['funnel'] = $group;
			}
		}

		if ( isset( $raw['free_shipping_bar'] ) && is_array( $raw['free_shipping_bar'] ) ) {
			$group = self::sanitize_free_shipping_bar( $raw['free_shipping_bar'] );
			if ( $group ) {
				$clean['free_shipping_bar'] = $group;
			}
		}

		if ( isset( $raw['delivery_estimate'] ) && is_array( $raw['delivery_estimate'] ) ) {
			$group = self::sanitize_delivery_estimate( $raw['delivery_estimate'] );
			if ( $group ) {
				$clean['delivery_estimate'] = $group;
			}
		}

		return $clean;
	}

	/**
	 * Reset the in-request cache. Test seam.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Merge stored config over defaults (group level + nested maps).
	 *
	 * @param array $stored Stored config.
	 * @return array
	 */
	private static function merge_defaults( array $stored ): array {
		return self::merge_partial( self::defaults(), $stored );
	}

	/**
	 * Merge `$partial` into `$base`: top-level groups key-by-key, nested
	 * `copy` / `tokens` / `default` maps key-by-key, everything else replaced.
	 *
	 * @param array $base    Base config.
	 * @param array $partial Partial config.
	 * @return array
	 */
	private static function merge_partial( array $base, array $partial ): array {
		$merged = $base;

		foreach ( self::FEATURES as $feature ) {
			if ( ! isset( $partial[ $feature ] ) || ! is_array( $partial[ $feature ] ) ) {
				continue;
			}
			$group = isset( $merged[ $feature ] ) && is_array( $merged[ $feature ] ) ? $merged[ $feature ] : array();
			foreach ( $partial[ $feature ] as $key => $value ) {
				if ( in_array( $key, array( 'copy', 'tokens', 'default' ), true ) && is_array( $value ) ) {
					$existing      = isset( $group[ $key ] ) && is_array( $group[ $key ] ) ? $group[ $key ] : array();
					$group[ $key ] = array_merge( $existing, $value );
					continue;
				}
				$group[ $key ] = $value;
			}
			$merged[ $feature ] = $group;
		}

		foreach ( array( 'config_hash', 'updated_at' ) as $meta ) {
			if ( array_key_exists( $meta, $partial ) && is_string( $partial[ $meta ] ) ) {
				$merged[ $meta ] = $partial[ $meta ];
			}
		}

		return $merged;
	}

	/**
	 * Sanitize the free-shipping-bar group.
	 *
	 * @param array $raw Raw group.
	 * @return array
	 */
	private static function sanitize_free_shipping_bar( array $raw ): array {
		$group = array();

		if ( array_key_exists( 'enabled', $raw ) ) {
			$group['enabled'] = self::to_bool( $raw['enabled'] );
		}
		if ( isset( $raw['threshold'] ) && is_numeric( $raw['threshold'] ) && (float) $raw['threshold'] >= 0 ) {
			$group['threshold'] = (float) $raw['threshold'];
		}
		if ( isset( $raw['threshold_mode'] ) && in_array( $raw['threshold_mode'], self::THRESHOLD_MODES, true ) ) {
			$group['threshold_mode'] = $raw['threshold_mode'];
		}
		if ( isset( $raw['show_on'] ) && is_array( $raw['show_on'] ) ) {
			$show_on = array();
			foreach ( self::SHOW_ON_VALUES as $value ) {
				if ( in_array( $value, $raw['show_on'], true ) ) {
					$show_on[] = $value;
				}
			}
			$group['show_on'] = $show_on;
		}
		if ( isset( $raw['include_tax'] ) && in_array( $raw['include_tax'], self::TAX_MODES, true ) ) {
			$group['include_tax'] = $raw['include_tax'];
		}
		foreach ( array( 'ignore_discounts', 'hide_when_unlocked' ) as $flag ) {
			if ( array_key_exists( $flag, $raw ) ) {
				$group[ $flag ] = self::to_bool( $raw[ $flag ] );
			}
		}
		$copy = self::sanitize_copy( $raw['copy'] ?? null, self::defaults()['free_shipping_bar']['copy'] );
		if ( null !== $copy ) {
			$group['copy'] = $copy;
		}
		if ( isset( $raw['tokens'] ) && is_array( $raw['tokens'] ) ) {
			$tokens = array();
			foreach ( self::defaults()['free_shipping_bar']['tokens'] as $key => $default ) {
				if ( isset( $raw['tokens'][ $key ] ) && is_scalar( $raw['tokens'][ $key ] ) ) {
					$tokens[ $key ] = self::sanitize_css_value( (string) $raw['tokens'][ $key ] );
				}
			}
			if ( $tokens ) {
				$group['tokens'] = $tokens;
			}
		}

		return $group;
	}

	/**
	 * Sanitize the delivery-estimate group.
	 *
	 * @param array $raw Raw group.
	 * @return array
	 */
	private static function sanitize_delivery_estimate( array $raw ): array {
		$group = array();

		if ( array_key_exists( 'enabled', $raw ) ) {
			$group['enabled'] = self::to_bool( $raw['enabled'] );
		}
		if ( isset( $raw['cutoff_time'] ) && is_string( $raw['cutoff_time'] )
			&& preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $raw['cutoff_time'] ) ) {
			$group['cutoff_time'] = $raw['cutoff_time'];
		}
		if ( isset( $raw['ship_days'] ) && is_array( $raw['ship_days'] ) ) {
			$days = array();
			foreach ( $raw['ship_days'] as $day ) {
				if ( is_numeric( $day ) && (int) $day >= 1 && (int) $day <= 7 ) {
					$days[] = (int) $day;
				}
			}
			$days = array_values( array_unique( $days ) );
			sort( $days );
			$group['ship_days'] = $days;
		}
		if ( isset( $raw['default'] ) && is_array( $raw['default'] ) ) {
			$range = self::sanitize_day_range( $raw['default'] );
			if ( null !== $range ) {
				$group['default'] = $range;
			}
		}
		if ( isset( $raw['rules'] ) && is_array( $raw['rules'] ) ) {
			$rules = array();
			foreach ( $raw['rules'] as $rule ) {
				if ( count( $rules ) >= self::MAX_RULES ) {
					break;
				}
				$clean_rule = self::sanitize_rule( $rule );
				if ( null !== $clean_rule ) {
					$rules[] = $clean_rule;
				}
			}
			$group['rules'] = $rules;
		}
		if ( isset( $raw['holidays'] ) && is_array( $raw['holidays'] ) ) {
			$holidays = array();
			foreach ( $raw['holidays'] as $date ) {
				if ( count( $holidays ) >= self::MAX_HOLIDAYS ) {
					break;
				}
				if ( is_string( $date ) && self::is_valid_date( $date ) ) {
					$holidays[] = $date;
				}
			}
			$group['holidays'] = array_values( array_unique( $holidays ) );
		}
		$copy = self::sanitize_copy( $raw['copy'] ?? null, self::defaults()['delivery_estimate']['copy'] );
		if ( null !== $copy ) {
			$group['copy'] = $copy;
		}
		if ( isset( $raw['date_format'] ) && in_array( $raw['date_format'], self::DATE_FORMATS, true ) ) {
			$group['date_format'] = $raw['date_format'];
		}

		return $group;
	}

	/**
	 * One delivery rule, or null when invalid.
	 *
	 * @param mixed $rule Raw rule.
	 * @return array|null
	 */
	private static function sanitize_rule( $rule ) {
		if ( ! is_array( $rule ) || ! isset( $rule['rate_id'] ) || ! is_string( $rule['rate_id'] ) ) {
			return null;
		}
		$rate_id = $rule['rate_id'];
		if ( ! preg_match( '/^[a-z0-9_\-]+(:\d+)?$/', $rate_id ) ) {
			return null;
		}
		$kind = isset( $rule['kind'] ) && in_array( $rule['kind'], self::RULE_KINDS, true ) ? $rule['kind'] : 'delivery';
		if ( 'hide' === $kind ) {
			return array(
				'rate_id'  => $rate_id,
				'min_days' => 0,
				'max_days' => 0,
				'kind'     => $kind,
			);
		}
		$range = self::sanitize_day_range( $rule );
		if ( null === $range ) {
			return null;
		}

		return array(
			'rate_id'  => $rate_id,
			'min_days' => $range['min_days'],
			'max_days' => $range['max_days'],
			'kind'     => $kind,
		);
	}

	/**
	 * `{ min_days, max_days }` with 0 ≤ min ≤ max ≤ MAX_DAYS, or null.
	 *
	 * @param array $raw Raw map.
	 * @return array|null
	 */
	private static function sanitize_day_range( array $raw ) {
		if ( ! isset( $raw['min_days'], $raw['max_days'] ) || ! is_numeric( $raw['min_days'] ) || ! is_numeric( $raw['max_days'] ) ) {
			return null;
		}
		$min = (int) $raw['min_days'];
		$max = (int) $raw['max_days'];
		if ( $min < 0 || $max < 0 || $min > $max || $max > self::MAX_DAYS ) {
			return null;
		}

		return array(
			'min_days' => $min,
			'max_days' => $max,
		);
	}

	/**
	 * Copy map limited to known keys, plain text, ≤ MAX_COPY_LEN chars.
	 *
	 * @param mixed $raw      Raw copy map.
	 * @param array $defaults Known keys.
	 * @return array|null Null when nothing usable was given.
	 */
	private static function sanitize_copy( $raw, array $defaults ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$copy = array();
		foreach ( $defaults as $key => $default ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$value        = sanitize_text_field( (string) $raw[ $key ] );
				$copy[ $key ] = mb_substr( $value, 0, self::MAX_COPY_LEN );
			}
		}

		return $copy ? $copy : null;
	}

	/**
	 * Value destined for a CSS custom property inside an inline `style`
	 * attribute. Same restricted set as FBT_Config.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_css_value( string $value ): string {
		$value = trim( $value );
		$value = preg_replace( '/[^a-zA-Z0-9#%,.()\/ _-]/', '', $value );

		return substr( (string) $value, 0, 64 );
	}

	/**
	 * Strict `YYYY-MM-DD` that is a real calendar date.
	 *
	 * @param string $date Candidate.
	 * @return bool
	 */
	private static function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Boolean coercion that treats "false" / "0" as false (a bare cast does not).
	 *
	 * @param mixed $value Raw.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return (bool) $value;
	}
}
