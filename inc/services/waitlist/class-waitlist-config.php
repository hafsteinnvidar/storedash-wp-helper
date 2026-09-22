<?php
/**
 * Waitlist Configuration Store
 *
 * Single source of the merchant's waitlist form configuration on the WP side.
 *
 * The authoritative copy lives in Supabase (`waitlist_settings`); the StoreDash
 * dashboard pushes it here via `POST storedash/v1/waitlist/settings`. It is kept
 * in ONE autoloaded option so that rendering a product page costs zero extra
 * queries — the config is already in the autoload bundle by the time any
 * template hook fires.
 *
 * Two independent switches gate rendering, and they must never be collapsed
 * into one:
 *
 *   - `enabled`  — merchant intent. Only the dashboard writes it.
 *   - `entitled` — billing entitlement. Only the storedash-worker nightly
 *                  reconcile writes it.
 *
 * Effective rendering requires both. Collapsing them would mean a merchant
 * re-enabling the form in the dashboard silently fights the nightly cron.
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.8.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waitlist Config Class
 */
class Waitlist_Config {

	/**
	 * Option name. Autoloaded on purpose — read on every product page render.
	 */
	const OPTION = 'storedash_waitlist_config';

	/**
	 * Placement modes.
	 *
	 * `elementor` — merchant places the Elementor widget themselves; the widget
	 *               carries its own styling and this config does not apply to it.
	 * `hook`      — plugin injects the form into the single-product summary.
	 *
	 * The shortcode is deliberately NOT a mode: it is always registered and
	 * always renders from this config, in either mode, as an escape hatch for
	 * themes where automatic placement cannot fire (Elementor Theme Builder
	 * product templates, Divi/Bricks, heavily overridden templates).
	 */
	const MODE_ELEMENTOR = 'elementor';
	const MODE_HOOK      = 'hook';

	/**
	 * Allowed injection priorities on `woocommerce_single_product_summary`.
	 *
	 * Chosen to land between WooCommerce's own registered callbacks:
	 *   5 title | 10 rating+price | 20 excerpt | 30 add-to-cart | 40 meta | 50 sharing
	 *
	 * NOTE: the add-to-cart form hooks (`woocommerce_before_add_to_cart_form`
	 * et al) are deliberately NOT offered. For a simple out-of-stock product
	 * they never fire at all — `templates/single-product/add-to-cart/simple.php`
	 * guards them behind `if ( $product->is_in_stock() )`. They DO fire for
	 * variable products, which makes them worse than useless: an option that
	 * works on the test product and silently fails on the merchant's.
	 *
	 * @var int[]
	 */
	const ALLOWED_PRIORITIES = array( 6, 11, 21, 31, 41 );

	/**
	 * Field visibility modes.
	 */
	const FIELD_OFF      = 'off';
	const FIELD_OPTIONAL = 'optional';
	const FIELD_REQUIRED = 'required';

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
	 * nothing still gets correctly localised copy in the site's language.
	 * See `Waitlist_Renderer::copy()`.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'            => false,
			'entitled'           => true,
			'placement_mode'     => self::MODE_ELEMENTOR,
			'placement_priority' => 31,
			'display_mode'       => 'inline',
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
				'heading'               => '',
				'description'           => '',
				'button_label'          => '',
				'submitting_text'       => '',
				'success_message'       => '',
				'trigger_label'         => '',
				'placeholder_name'      => '',
				'placeholder_email'     => '',
				'placeholder_phone'     => '',
				'placeholder_variation' => '',
				'privacy_notice'        => '',
			),
			'fields'             => array(
				'name'  => self::FIELD_OFF,
				'phone' => self::FIELD_OFF,
			),
			'consent'            => array(
				'enabled' => false,
				'label'   => '',
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

		foreach ( array( 'tokens', 'copy', 'fields', 'consent' ) as $group ) {
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
	 * Only keys present in `$partial` are touched, so the dashboard writing
	 * appearance settings can never clobber `entitled` (written solely by the
	 * worker reconcile), and vice versa.
	 *
	 * @param array $partial Sanitized partial config.
	 * @return array The full config after the update.
	 */
	public static function update( array $partial ): array {
		$current = self::get();
		$next    = $partial;

		// Merge sub-arrays rather than replacing them wholesale.
		foreach ( array( 'tokens', 'copy', 'fields', 'consent' ) as $group ) {
			if ( isset( $partial[ $group ] ) && is_array( $partial[ $group ] ) ) {
				$next[ $group ] = array_merge( $current[ $group ], $partial[ $group ] );
			}
		}

		$merged = array_merge( $current, $next );

		// The hash covers everything the storefront actually renders, so the
		// dashboard can tell "your site is running this exact config" from
		// "your last save never landed". `entitled` is excluded: it is not
		// merchant-authored, and including it would make every downgrade look
		// like an unsynced change in the dashboard.
		$hashable = $merged;
		unset( $hashable['config_hash'], $hashable['updated_at'], $hashable['entitled'] );
		$merged['config_hash'] = md5( (string) wp_json_encode( $hashable ) );
		$merged['updated_at']  = current_time( 'mysql', true );

		update_option( self::OPTION, $merged, true );
		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Whether the form should render at all.
	 *
	 * Requires both merchant intent and billing entitlement.
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
	 * Resolve the injection priority, clamped to the allowed set.
	 *
	 * @return int
	 */
	public static function priority(): int {
		$priority = (int) self::get()['placement_priority'];

		return in_array( $priority, self::ALLOWED_PRIORITIES, true ) ? $priority : 31;
	}

	/**
	 * Sanitize an inbound configuration payload from the dashboard.
	 *
	 * Unknown keys are dropped. Every value is coerced to a known shape so a
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
				: self::MODE_ELEMENTOR;
		}

		if ( isset( $raw['placement_priority'] ) ) {
			$priority                    = (int) $raw['placement_priority'];
			$clean['placement_priority'] = in_array( $priority, self::ALLOWED_PRIORITIES, true ) ? $priority : 31;
		}

		if ( isset( $raw['display_mode'] ) ) {
			$clean['display_mode'] = 'modal' === $raw['display_mode'] ? 'modal' : 'inline';
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

		if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			$clean['fields'] = array();
			foreach ( array( 'name', 'phone' ) as $field ) {
				if ( isset( $raw['fields'][ $field ] ) ) {
					$clean['fields'][ $field ] = self::sanitize_field_mode( (string) $raw['fields'][ $field ] );
				}
			}
		}

		if ( isset( $raw['consent'] ) && is_array( $raw['consent'] ) ) {
			$clean['consent'] = array();
			if ( array_key_exists( 'enabled', $raw['consent'] ) ) {
				$clean['consent']['enabled'] = (bool) $raw['consent']['enabled'];
			}
			if ( isset( $raw['consent']['label'] ) ) {
				$clean['consent']['label'] = sanitize_text_field( (string) $raw['consent']['label'] );
			}
		}

		return $clean;
	}

	/**
	 * Sanitize a field visibility mode.
	 *
	 * @param string $mode Raw mode.
	 * @return string
	 */
	private static function sanitize_field_mode( string $mode ): string {
		$allowed = array( self::FIELD_OFF, self::FIELD_OPTIONAL, self::FIELD_REQUIRED );

		return in_array( $mode, $allowed, true ) ? $mode : self::FIELD_OFF;
	}

	/**
	 * Sanitize a value destined for a CSS custom property.
	 *
	 * These land inside a `<style>` block, so anything that could terminate the
	 * declaration or the element must go. Restricted to the character set that
	 * colours, lengths and simple keywords need.
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
