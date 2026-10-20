<?php
/**
 * DK Configuration Store
 *
 * The WP-side copy of the merchant's DK Plus checkout settings. The authoritative
 * copy lives in Supabase (`store_integrations.config`, provider `dkplus`); the
 * Storedash DK settings page pushes it here via `POST storedash/v1/dk/config`
 * (plan §4.8). One autoloaded option, so the checkout render costs zero extra
 * queries. Mirrors `Waitlist_Config`.
 *
 * Only the kennitala field lives here today. DK API calls never run in WP —
 * invoices, prices and stock are the storedash-worker's / storedash-sync's job.
 *
 * @package StoreDash\Services\DK
 * @since   1.27.0
 */

namespace StoreDash\Services\DK;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DK Config Class
 */
class DK_Config {

	/**
	 * Option name. Autoloaded on purpose — read on every checkout render.
	 */
	const OPTION = 'storedash_dk_config';

	/**
	 * Kennitala field modes.
	 */
	const MODE_OFF      = 'off';
	const MODE_OPTIONAL = 'optional';
	const MODE_REQUIRED = 'required';

	/**
	 * Classic-checkout field positions → `priority` among the billing fields.
	 *
	 * WooCommerce's own billing priorities: first_name 10 | last_name 20 |
	 * company 30 | country 40 | address_1 50 | address_2 60 | city 70 |
	 * state 80 | postcode 90 | phone 100 | email 110. Each position lands
	 * between two of them, so a theme that re-prioritises its own fields keeps
	 * working as long as it stays near the defaults.
	 *
	 * @var array<string,int>
	 */
	const POSITIONS = array(
		'top'           => 5,
		'after_name'    => 25,
		'after_address' => 95,
		'after_phone'   => 105,
		'bottom'        => 120,
	);

	const DEFAULT_POSITION = 'top';

	/**
	 * Cached config for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default configuration. Field OFF until the dashboard pushes a mode —
	 * installing the plugin must never change a merchant's checkout.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'kennitala_mode'     => self::MODE_OFF,
			'kennitala_position' => self::DEFAULT_POSITION,
			'config_hash'        => '',
			'updated_at'         => '',
		);
	}

	/**
	 * Get the current configuration, merged over defaults.
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
		self::$cache = array_merge( self::defaults(), $stored );
		return self::$cache;
	}

	/**
	 * Merge a sanitized partial into the stored config and persist it.
	 *
	 * @param array $partial Output of `sanitize()`.
	 * @return array The full stored config.
	 */
	public static function update( array $partial ): array {
		$merged = array_merge( self::get(), $partial );

		$hashable = $merged;
		unset( $hashable['config_hash'], $hashable['updated_at'] );
		$merged['config_hash'] = self::hash( $hashable );
		$merged['updated_at']  = current_time( 'mysql', true );

		update_option( self::OPTION, $merged, true );
		self::$cache = $merged;
		return $merged;
	}

	/**
	 * Hash of the merchant-authored keys, so the dashboard can tell "this exact
	 * config is live" from "the last push never landed".
	 *
	 * @param array $hashable Config without hash/timestamp.
	 * @return string
	 */
	public static function hash( array $hashable ): string {
		ksort( $hashable );
		return md5( (string) wp_json_encode( $hashable ) );
	}

	/**
	 * Whitelist + normalise a raw payload. Unknown keys are dropped; a bad
	 * value falls back to the default so one typo never disables the field.
	 *
	 * @param array $raw Request payload.
	 * @return array Only the recognised keys that were present.
	 */
	public static function sanitize( array $raw ): array {
		$clean = array();

		if ( isset( $raw['kennitala_mode'] ) ) {
			$clean['kennitala_mode'] = self::sanitize_mode( (string) $raw['kennitala_mode'] );
		}
		if ( isset( $raw['kennitala_position'] ) ) {
			$clean['kennitala_position'] = self::sanitize_position( (string) $raw['kennitala_position'] );
		}

		return $clean;
	}

	/**
	 * @param string $mode Raw mode.
	 * @return string One of the MODE_* constants.
	 */
	public static function sanitize_mode( string $mode ): string {
		$mode = strtolower( trim( $mode ) );
		return in_array( $mode, array( self::MODE_OFF, self::MODE_OPTIONAL, self::MODE_REQUIRED ), true )
			? $mode
			: self::MODE_OFF;
	}

	/**
	 * @param string $position Raw position.
	 * @return string A key of POSITIONS.
	 */
	public static function sanitize_position( string $position ): string {
		$position = strtolower( trim( $position ) );
		return isset( self::POSITIONS[ $position ] ) ? $position : self::DEFAULT_POSITION;
	}

	/**
	 * Is the kennitala field rendered at all?
	 *
	 * @return bool
	 */
	public static function kennitala_enabled(): bool {
		return self::MODE_OFF !== self::kennitala_mode();
	}

	/**
	 * @return string One of the MODE_* constants.
	 */
	public static function kennitala_mode(): string {
		return self::sanitize_mode( (string) self::get()['kennitala_mode'] );
	}

	/**
	 * @return bool Required for Icelandic billing addresses?
	 */
	public static function kennitala_required(): bool {
		return self::MODE_REQUIRED === self::kennitala_mode();
	}

	/**
	 * Classic-checkout `priority` for the field.
	 *
	 * @return int
	 */
	public static function kennitala_priority(): int {
		$position = self::sanitize_position( (string) self::get()['kennitala_position'] );
		return self::POSITIONS[ $position ];
	}
}
