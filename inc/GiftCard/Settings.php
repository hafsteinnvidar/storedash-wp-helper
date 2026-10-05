<?php
/**
 * Gift card settings.
 *
 * Pushed from Storedash (`POST /gift-cards/sync`, contract B) and stored as one
 * array option. WordPress never edits them locally. Cards never expire, so
 * there is no expiry setting.
 *
 * @package StoreDash\GiftCard
 * @since   1.24.0
 */

namespace StoreDash\GiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, normalizes and persists the `storedash_gift_card_settings` option.
 *
 * @since 1.24.0
 */
class Settings {

	/**
	 * Option holding the settings array.
	 */
	const OPTION = 'storedash_gift_card_settings';

	/**
	 * Hard ceiling for max_cards_per_order.
	 */
	const MAX_CARDS_CEILING = 20;

	/**
	 * Default settings (contract B).
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'             => false,
			'issue_on_status'     => 'processing',
			'max_cards_per_order' => 5,
		);
	}

	/**
	 * Normalize a raw settings payload.
	 *
	 * Pure function — unit tested.
	 *
	 * @param array $raw Raw settings.
	 * @return array
	 */
	public static function normalize( array $raw ): array {
		$out = self::defaults();

		if ( array_key_exists( 'enabled', $raw ) ) {
			$out['enabled'] = self::to_bool( $raw['enabled'] );
		}
		if ( isset( $raw['issue_on_status'] ) && in_array( $raw['issue_on_status'], array( 'processing', 'completed' ), true ) ) {
			$out['issue_on_status'] = $raw['issue_on_status'];
		}
		if ( isset( $raw['max_cards_per_order'] ) && is_numeric( $raw['max_cards_per_order'] ) ) {
			$out['max_cards_per_order'] = max( 1, min( self::MAX_CARDS_CEILING, (int) $raw['max_cards_per_order'] ) );
		}

		return $out;
	}

	/**
	 * Current settings.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist settings.
	 *
	 * @param array $raw Raw settings payload.
	 * @return array Normalized settings as saved.
	 */
	public static function save( array $raw ): array {
		$settings = self::normalize( $raw );
		update_option( self::OPTION, $settings, false );
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
