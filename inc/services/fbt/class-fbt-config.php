<?php
/**
 * FBT (Frequently Bought Together) Configuration Store
 *
 * Single source of the merchant's global FBT display configuration on the WP
 * side. The authoritative copy lives in Supabase (`fbt_settings`); the
 * StoreDash dashboard pushes it here via `POST storedash/v1/fbt/settings`.
 * One autoloaded option, same rationale as `Enquiry_Config`: rendering a
 * product page must cost zero extra queries for config.
 *
 * WHAT products carry a bundle is NOT configured here. The per-product bundle
 * lives in product meta (`storedash_fbt_ids`, with WP Clever's `woobt_ids` as
 * a dual-written compatibility twin), written by the dashboard's product
 * editor. This config is only the GLOBAL display layer: on/off, where the
 * bundle is injected, and how it looks. That is why there is no scope block —
 * "has bundle meta" is the scope.
 *
 * Two independent switches gate rendering (same contract as enquiry, never
 * collapse them):
 *
 *   - `enabled`  — merchant intent. Only the dashboard writes it.
 *   - `entitled` — billing entitlement. Defaults true because FBT ships as
 *                  ungated core commerce; the key exists so gating later is a
 *                  worker-reconcile change, not a plugin release.
 *
 * Differences from the enquiry config, all deliberate:
 *
 *   - `placement_mode` values are `widget` / `hook` rather than
 *     `elementor` / `hook`: the FBT builder widgets (Elementor AND Bricks)
 *     live in the storedash-essentials plugin, so "elementor" would be a lie
 *     on Bricks stores.
 *   - No product tab position. A bundle upsell inside a tab the customer must
 *     open first defeats its purpose; the natural spots are the buy column
 *     (`summary` at a clamped priority) or below it (`after_summary`).
 *   - A `display` block of boolean toggles instead of `fields`/`consent` —
 *     FBT collects nothing, it only shows things.
 *
 * @package StoreDash\Services\FBT
 * @since   1.12.0
 */

namespace StoreDash\Services\FBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FBT Config Class
 */
class FBT_Config {

	/**
	 * Option name. Autoloaded on purpose — read on every product page render.
	 */
	const OPTION = 'storedash_fbt_config';

	/**
	 * Placement modes.
	 *
	 * `widget` — merchant places the storedash-essentials Elementor/Bricks FBT
	 *            widget themselves; that widget carries its own styling and
	 *            this config does not apply to it.
	 * `hook`   — this plugin injects the bundle automatically.
	 *
	 * The `[storedash_fbt]` shortcode is deliberately NOT a mode: it is always
	 * registered and always renders from this config, in either mode.
	 */
	const MODE_WIDGET = 'widget';
	const MODE_HOOK   = 'hook';

	/**
	 * Placement positions.
	 *
	 * `summary`       — inject into `woocommerce_single_product_summary` at
	 *                   `placement_priority`. Default: under Add to Cart.
	 * `after_summary` — `woocommerce_after_single_product_summary` priority 5,
	 *                   below the buy area but above related products
	 *                   (WooCommerce renders related at priority 20).
	 */
	const POSITION_SUMMARY       = 'summary';
	const POSITION_AFTER_SUMMARY = 'after_summary';

	/**
	 * Allowed injection priorities on `woocommerce_single_product_summary`.
	 * Same clamped set as the enquiry service, between WooCommerce's own
	 * callbacks: 5 title | 10 rating+price | 20 excerpt | 30 add-to-cart |
	 * 40 meta | 50 sharing.
	 *
	 * @var int[]
	 */
	const ALLOWED_PRIORITIES = array( 6, 11, 21, 31, 41 );

	/**
	 * Allowed image sizes (rendered pixel box, mirroring the essentials widget).
	 *
	 * @var array<string,int>
	 */
	const IMAGE_SIZES = array(
		'small'  => 40,
		'medium' => 60,
		'large'  => 80,
	);

	/**
	 * Cached config for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default configuration.
	 *
	 * All copy defaults are empty strings on purpose: an empty value means
	 * "use the plugin's own translated string", so a merchant who configures
	 * nothing still gets correctly localised copy. See `FBT_Renderer::copy()`.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'            => false,
			'entitled'           => true,
			'placement_mode'     => self::MODE_WIDGET,
			'placement_position' => self::POSITION_SUMMARY,
			'placement_priority' => 31,
			'preset'             => 'minimal',
			'tokens'             => array(
				'accent'       => '#111111',
				'accent_text'  => '#ffffff',
				'text'         => '#111111',
				'muted'        => '#6b7280',
				'background'   => 'transparent',
				'border'       => '#e5e7eb',
				'radius'       => '8px',
				'field_radius' => '8px',
				'padding'      => '0px',
			),
			'copy'               => array(
				'title'          => '',
				'items_label'    => '',
				'total_label'    => '',
				'button_label'   => '',
				'adding_text'    => '',
				'added_message'  => '',
				'discount_badge' => '',
			),
			'display'            => array(
				'show_images'           => true,
				'image_size'            => 'medium',
				'show_additional_price' => true,
				'show_grand_total'      => true,
				'show_discount_badge'   => true,
				// The standalone "Add selected to cart" button. In hook mode
				// the theme's own Add to Cart knows nothing about the bundle
				// checkboxes, so without this button the widget is decorative.
				'show_add_button'       => true,
			),
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

		self::$cache = self::merge_defaults( $stored );

		return self::$cache;
	}

	/**
	 * Merge stored config over defaults, one level deep for known sub-arrays.
	 *
	 * @param array $stored Stored config.
	 * @return array
	 */
	private static function merge_defaults( array $stored ): array {
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, $stored );

		foreach ( array( 'tokens', 'copy', 'display' ) as $group ) {
			$merged[ $group ] = array_merge(
				$defaults[ $group ],
				is_array( $stored[ $group ] ?? null ) ? $stored[ $group ] : array()
			);
		}

		return $merged;
	}

	/**
	 * Persist a (partial) configuration update.
	 *
	 * Only keys present in `$partial` are touched, so a dashboard push of
	 * appearance settings can never clobber `entitled`, and vice versa.
	 *
	 * @param array $partial Sanitized partial config.
	 * @return array The full config after the update.
	 */
	public static function update( array $partial ): array {
		$current = self::get();
		$next    = $partial;

		foreach ( array( 'tokens', 'copy', 'display' ) as $group ) {
			if ( isset( $partial[ $group ] ) && is_array( $partial[ $group ] ) ) {
				$next[ $group ] = array_merge( $current[ $group ], $partial[ $group ] );
			}
		}

		$merged = array_merge( $current, $next );

		// The hash covers everything the storefront renders. `entitled` is
		// excluded for the same reason as in Enquiry_Config: it is not
		// merchant-authored, and including it would make an entitlement flip
		// look like an unsynced change in the dashboard.
		$hashable = $merged;
		unset( $hashable['config_hash'], $hashable['updated_at'], $hashable['entitled'] );
		$merged['config_hash'] = md5( (string) wp_json_encode( $hashable ) );
		$merged['updated_at']  = current_time( 'mysql', true );

		update_option( self::OPTION, $merged, true );
		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Whether the bundle should render at all.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		$config = self::get();

		return ! empty( $config['enabled'] ) && ! empty( $config['entitled'] );
	}

	/**
	 * Whether automatic (hook) placement is active.
	 *
	 * @return bool
	 */
	public static function is_auto_placement(): bool {
		return self::is_active() && self::MODE_HOOK === self::get()['placement_mode'];
	}

	/**
	 * Resolve the placement position.
	 *
	 * @return string
	 */
	public static function position(): string {
		return self::POSITION_AFTER_SUMMARY === self::get()['placement_position']
			? self::POSITION_AFTER_SUMMARY
			: self::POSITION_SUMMARY;
	}

	/**
	 * Resolve the injection priority, clamped to the allowed set.
	 *
	 * @return int
	 */
	public static function priority(): int {
		$priority = (int) self::get()['placement_priority'];

		return in_array( $priority, self::ALLOWED_PRIORITIES, true ) ? $priority : 31;
	}

	/**
	 * Resolve a display toggle.
	 *
	 * @param string $key Display key.
	 * @return bool
	 */
	public static function display( string $key ): bool {
		return ! empty( self::get()['display'][ $key ] );
	}

	/**
	 * Resolve the image pixel size for the configured `image_size`.
	 *
	 * @return int
	 */
	public static function image_px(): int {
		$size = (string) ( self::get()['display']['image_size'] ?? 'medium' );

		return self::IMAGE_SIZES[ $size ] ?? self::IMAGE_SIZES['medium'];
	}

	/**
	 * Sanitize an inbound configuration payload from the dashboard.
	 *
	 * Unknown keys are dropped; every value is coerced to a known shape so a
	 * malformed push can never produce broken markup on the storefront.
	 *
	 * @param array $raw Raw payload.
	 * @return array
	 */
	public static function sanitize( array $raw ): array {
		$clean = array();

		if ( array_key_exists( 'enabled', $raw ) ) {
			$clean['enabled'] = (bool) $raw['enabled'];
		}

		if ( array_key_exists( 'entitled', $raw ) ) {
			$clean['entitled'] = (bool) $raw['entitled'];
		}

		if ( isset( $raw['placement_mode'] ) ) {
			$clean['placement_mode'] = self::MODE_HOOK === $raw['placement_mode']
				? self::MODE_HOOK
				: self::MODE_WIDGET;
		}

		if ( isset( $raw['placement_position'] ) ) {
			$clean['placement_position'] = self::POSITION_AFTER_SUMMARY === $raw['placement_position']
				? self::POSITION_AFTER_SUMMARY
				: self::POSITION_SUMMARY;
		}

		if ( isset( $raw['placement_priority'] ) ) {
			$priority                    = (int) $raw['placement_priority'];
			$clean['placement_priority'] = in_array( $priority, self::ALLOWED_PRIORITIES, true ) ? $priority : 31;
		}

		if ( isset( $raw['preset'] ) ) {
			$clean['preset'] = sanitize_key( $raw['preset'] );
		}

		if ( isset( $raw['tokens'] ) && is_array( $raw['tokens'] ) ) {
			$clean['tokens'] = array();
			foreach ( self::defaults()['tokens'] as $key => $default ) {
				if ( isset( $raw['tokens'][ $key ] ) ) {
					$clean['tokens'][ $key ] = self::sanitize_css_value( (string) $raw['tokens'][ $key ] );
				}
			}
		}

		if ( isset( $raw['copy'] ) && is_array( $raw['copy'] ) ) {
			$clean['copy'] = array();
			foreach ( self::defaults()['copy'] as $key => $default ) {
				if ( isset( $raw['copy'][ $key ] ) ) {
					$clean['copy'][ $key ] = sanitize_text_field( (string) $raw['copy'][ $key ] );
				}
			}
		}

		if ( isset( $raw['display'] ) && is_array( $raw['display'] ) ) {
			$clean['display'] = array();
			foreach ( self::defaults()['display'] as $key => $default ) {
				if ( ! array_key_exists( $key, $raw['display'] ) ) {
					continue;
				}

				if ( 'image_size' === $key ) {
					$size                           = (string) $raw['display']['image_size'];
					$clean['display']['image_size'] = isset( self::IMAGE_SIZES[ $size ] ) ? $size : 'medium';
					continue;
				}

				$clean['display'][ $key ] = (bool) $raw['display'][ $key ];
			}
		}

		return $clean;
	}

	/**
	 * Sanitize a value destined for a CSS custom property inside an inline
	 * `style` attribute. Same restricted set as Enquiry_Config.
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
	 * Reset the in-request cache. Test seam.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}
}
