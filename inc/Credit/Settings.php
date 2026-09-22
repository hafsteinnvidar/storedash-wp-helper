<?php
/**
 * Rewards credit settings.
 *
 * Settings are pushed from Storedash (`POST /credit/sync`, contract A) and
 * stored as one array option. WordPress never edits them locally.
 *
 * @package StoreDash\Credit
 * @since   1.17.0
 */

namespace StoreDash\Credit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, normalizes and persists the `storedash_credit_settings` option.
 *
 * @since 1.17.0
 */
class Settings {

	/**
	 * Option holding the settings array.
	 */
	const OPTION = 'storedash_credit_settings';

	/**
	 * Option stamped (UTC MySQL datetime) the first time the feature is enabled.
	 * Orders created before this moment never earn credit.
	 */
	const ENABLED_AT_OPTION = 'storedash_credit_enabled_at';

	/**
	 * Default settings (contract A).
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'                  => false,
			'earn_basis'               => 'subtotal_ex_tax',
			'earn_on_status'           => 'completed',
			'earn_on_sale_items'       => false,
			'earn_on_credit_paid_part' => false,
			'spend_on_sale_items'      => false,
			'spend_covers_shipping'    => false,
			'max_spend_pct'            => null,
			'min_order_to_spend'       => null,
			'expiry_days'              => null,
			'refund_credit_as_credit'  => true,
			'rounding'                 => 'down',
			'exclude_product_ids'      => array(),
			'exclude_category_ids'     => array(),
			'exclude_tag_ids'          => array(),
			'exclude_brand_ids'        => array(),
		);
	}

	/**
	 * Normalize a raw settings payload into the canonical shape.
	 *
	 * Pure function — unit tested. Unknown keys are dropped, enums fall back to
	 * their defaults, numeric caps become floats or null, id lists become int[].
	 *
	 * @param array $raw Raw settings (from the sync payload or the option).
	 * @return array
	 */
	public static function normalize( array $raw ): array {
		$defaults = self::defaults();
		$out      = $defaults;

		foreach ( array( 'enabled', 'earn_on_sale_items', 'earn_on_credit_paid_part', 'spend_on_sale_items', 'spend_covers_shipping', 'refund_credit_as_credit' ) as $flag ) {
			if ( array_key_exists( $flag, $raw ) ) {
				$out[ $flag ] = self::to_bool( $raw[ $flag ] );
			}
		}

		if ( isset( $raw['earn_basis'] ) && in_array( $raw['earn_basis'], array( 'subtotal_ex_tax', 'subtotal_inc_tax', 'total' ), true ) ) {
			$out['earn_basis'] = $raw['earn_basis'];
		}
		if ( isset( $raw['earn_on_status'] ) && in_array( $raw['earn_on_status'], array( 'completed', 'processing' ), true ) ) {
			$out['earn_on_status'] = $raw['earn_on_status'];
		}
		if ( isset( $raw['rounding'] ) && in_array( $raw['rounding'], array( 'down', 'nearest' ), true ) ) {
			$out['rounding'] = $raw['rounding'];
		}

		foreach ( array( 'max_spend_pct', 'min_order_to_spend' ) as $num ) {
			if ( array_key_exists( $num, $raw ) ) {
				$out[ $num ] = ( null === $raw[ $num ] || '' === $raw[ $num ] ) ? null : (float) $raw[ $num ];
			}
		}
		if ( null !== $out['max_spend_pct'] ) {
			$out['max_spend_pct'] = max( 0.0, min( 100.0, $out['max_spend_pct'] ) );
		}
		if ( null !== $out['min_order_to_spend'] && $out['min_order_to_spend'] < 0 ) {
			$out['min_order_to_spend'] = 0.0;
		}

		if ( array_key_exists( 'expiry_days', $raw ) ) {
			$days               = ( null === $raw['expiry_days'] || '' === $raw['expiry_days'] ) ? null : (int) $raw['expiry_days'];
			$out['expiry_days'] = ( null === $days || $days <= 0 ) ? null : $days;
		}

		foreach ( array( 'exclude_product_ids', 'exclude_category_ids', 'exclude_tag_ids', 'exclude_brand_ids' ) as $list ) {
			if ( isset( $raw[ $list ] ) && is_array( $raw[ $list ] ) ) {
				$out[ $list ] = array_values( array_unique( array_filter( array_map( 'intval', $raw[ $list ] ) ) ) );
			}
		}

		return $out;
	}

	/**
	 * Current settings (normalized).
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist settings and stamp `enabled_at` on the first enable.
	 *
	 * @param array $raw Raw settings payload.
	 * @return array Normalized settings as saved.
	 */
	public static function save( array $raw ): array {
		$settings = self::normalize( $raw );
		update_option( self::OPTION, $settings, false );

		if ( $settings['enabled'] && '' === (string) get_option( self::ENABLED_AT_OPTION, '' ) ) {
			update_option( self::ENABLED_AT_OPTION, gmdate( 'Y-m-d H:i:s' ), false );
		}

		return $settings;
	}

	/**
	 * Whether the feature is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$settings = self::get();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * UTC datetime the feature was first enabled, or '' when never enabled.
	 *
	 * @return string
	 */
	public static function enabled_at(): string {
		return (string) get_option( self::ENABLED_AT_OPTION, '' );
	}

	/**
	 * Loose boolean coercion for JSON / form values.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}
}
