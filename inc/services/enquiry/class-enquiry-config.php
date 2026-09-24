<?php
/**
 * Enquiry Configuration Store
 *
 * Single source of the merchant's enquiry form configuration on the WP side.
 *
 * The authoritative copy lives in Supabase (`enquiry_settings`); the StoreDash
 * dashboard pushes it here via `POST storedash/v1/enquiry/settings`. It is kept
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
 * Differences from the waitlist config, all deliberate:
 *
 *   - Default placement is the WooCommerce product tab, not a summary hook.
 *     The enquiry form renders on EVERY product page, so it must not compete
 *     with the buy area by default. `woocommerce_product_tabs` is a filter
 *     rather than a template hook, which is why it survives Elementor Theme
 *     Builder, Woodmart, Divi and Bricks where the summary chain often does
 *     not run at all.
 *   - There is no `display_mode`. It is DERIVED from the placement: a tab
 *     always renders the form inline (a trigger button inside a tab the
 *     customer just opened is a pointless second click), and a summary
 *     position always renders a trigger button plus modal (a full form wedged
 *     under the price on every product page pushes the buy button down).
 *   - There is a `scope`, because enquiry has no natural eligibility gate the
 *     way "out of stock" gated the waitlist.
 *
 * @package StoreDash\Services\Enquiry
 * @since   1.9.0
 */

namespace StoreDash\Services\Enquiry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enquiry Config Class
 */
class Enquiry_Config {

	/**
	 * Option name. Autoloaded on purpose — read on every product page render.
	 */
	const OPTION = 'storedash_enquiry_config';

	/**
	 * Placement modes.
	 *
	 * `elementor` — merchant places the Elementor widget themselves; the widget
	 *               carries its own styling and this config does not apply to it.
	 * `hook`      — plugin injects the form automatically.
	 *
	 * The shortcode is deliberately NOT a mode: it is always registered and
	 * always renders from this config, in either mode.
	 */
	const MODE_ELEMENTOR = 'elementor';
	const MODE_HOOK      = 'hook';

	/**
	 * Placement positions.
	 *
	 * `tab`     — an "Ask a question" tab via the `woocommerce_product_tabs`
	 *             filter. Default.
	 * `summary` — a trigger button injected into `woocommerce_single_product_summary`
	 *             at `placement_priority`.
	 */
	const POSITION_TAB     = 'tab';
	const POSITION_SUMMARY = 'summary';

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
	 * Scope modes.
	 */
	const SCOPE_ALL    = 'all';
	const SCOPE_ONLY   = 'only';
	const SCOPE_EXCEPT = 'except';

	/**
	 * Taxonomies a merchant may scope by.
	 *
	 * Deliberately limited to these three. Product attributes (`pa_*`) are not
	 * offered — they multiply the picker surface for a use case nobody asked
	 * for. Individual product IDs are not offered either: the picker would need
	 * search and pagination over the whole catalogue, and an unbounded ID list
	 * would bloat an autoloaded option.
	 *
	 * @var string[]
	 */
	const SCOPE_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * Hard cap on stored scope terms.
	 *
	 * This config is autoloaded, i.e. read into memory on EVERY request site
	 * wide, not just product pages. A hundred term IDs is well under a
	 * kilobyte of JSON and keeps that footprint irrelevant. A merchant who
	 * needs more than a hundred terms is describing a category, not a list.
	 */
	const MAX_SCOPE_TERMS = 100;

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
	 * See `Enquiry_Renderer::copy()`.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'            => false,
			'entitled'           => true,
			'placement_mode'     => self::MODE_ELEMENTOR,
			'placement_position' => self::POSITION_TAB,
			'placement_priority' => 31,
			'preset'             => 'minimal',
			'scope'              => array(
				'mode'  => self::SCOPE_ALL,
				'terms' => array(),
			),
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
				'tab_label'           => '',
				'heading'             => '',
				'description'         => '',
				'button_label'        => '',
				'submitting_text'     => '',
				'success_message'     => '',
				'trigger_label'       => '',
				'placeholder_name'    => '',
				'placeholder_email'   => '',
				'placeholder_phone'   => '',
				'placeholder_message' => '',
				'privacy_notice'      => '',
			),
			'fields'             => array(
				'name'  => self::FIELD_OPTIONAL,
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

		foreach ( array( 'tokens', 'copy', 'fields', 'consent', 'scope' ) as $group ) {
			$merged[ $group ] = array_merge(
				$defaults[ $group ],
				is_array( $stored[ $group ] ?? null ) ? $stored[ $group ] : array()
			);
		}

		// `scope.terms` is a list, not a keyed map: array_merge above would
		// concatenate a stored list onto the (empty) default rather than
		// replace it. Normalise it back to a plain list.
		$merged['scope']['terms'] = is_array( $merged['scope']['terms'] ?? null )
			? array_values( $merged['scope']['terms'] )
			: array();

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

		// Merge keyed sub-arrays rather than replacing them wholesale.
		foreach ( array( 'tokens', 'copy', 'fields', 'consent' ) as $group ) {
			if ( isset( $partial[ $group ] ) && is_array( $partial[ $group ] ) ) {
				$next[ $group ] = array_merge( $current[ $group ], $partial[ $group ] );
			}
		}

		// `scope` merges its scalar keys but REPLACES the term list — a save
		// that removes a category must actually remove it, and merging a list
		// could only ever grow it.
		if ( isset( $partial['scope'] ) && is_array( $partial['scope'] ) ) {
			$next['scope'] = array_merge( $current['scope'], $partial['scope'] );
			if ( isset( $partial['scope']['terms'] ) && is_array( $partial['scope']['terms'] ) ) {
				$next['scope']['terms'] = array_values( $partial['scope']['terms'] );
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
	 * Whether the store's plan includes enquiries (billing only).
	 *
	 * The Elementor widget and the submit handler gate on this alone, never on
	 * `enabled`: widget stores have never set `enabled` (it defaults to false),
	 * so requiring it would blank every existing widget form. Written only by
	 * the storedash-worker nightly reconcile; defaults to true.
	 *
	 * @return bool
	 */
	public static function is_entitled(): bool {
		$config = self::get();

		return ! empty( $config['entitled'] );
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
		return self::POSITION_SUMMARY === self::get()['placement_position']
			? self::POSITION_SUMMARY
			: self::POSITION_TAB;
	}

	/**
	 * Whether the current placement renders a trigger button plus modal.
	 *
	 * Derived, never configured: tab placement is always inline, summary
	 * placement is always modal. See the class docblock.
	 *
	 * @return bool
	 */
	public static function is_modal(): bool {
		return self::POSITION_SUMMARY === self::position();
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

		if ( isset( $raw['placement_position'] ) ) {
			$clean['placement_position'] = self::POSITION_SUMMARY === $raw['placement_position']
				? self::POSITION_SUMMARY
				: self::POSITION_TAB;
		}

		if ( isset( $raw['placement_priority'] ) ) {
			$priority                    = (int) $raw['placement_priority'];
			$clean['placement_priority'] = in_array( $priority, self::ALLOWED_PRIORITIES, true ) ? $priority : 31;
		}

		if ( isset( $raw['preset'] ) ) {
			$clean['preset'] = sanitize_key( $raw['preset'] );
		}

		if ( isset( $raw['scope'] ) && is_array( $raw['scope'] ) ) {
			$clean['scope'] = self::sanitize_scope( $raw['scope'] );
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
	 * Sanitize the scope block.
	 *
	 * Term IDs arriving here are WooCommerce term IDs, NOT StoreDash internal
	 * IDs. The dashboard picker reads categories/tags/brands from Supabase,
	 * where `id` is an internal surrogate key and the WooCommerce term ID lives
	 * in `external_id` — sending the wrong one produces a scope that silently
	 * matches nothing.
	 *
	 * @param array $raw Raw scope payload.
	 * @return array
	 */
	private static function sanitize_scope( array $raw ): array {
		$mode    = isset( $raw['mode'] ) ? (string) $raw['mode'] : self::SCOPE_ALL;
		$allowed = array( self::SCOPE_ALL, self::SCOPE_ONLY, self::SCOPE_EXCEPT );

		$clean = array(
			'mode'  => in_array( $mode, $allowed, true ) ? $mode : self::SCOPE_ALL,
			'terms' => array(),
		);

		if ( ! isset( $raw['terms'] ) || ! is_array( $raw['terms'] ) ) {
			return $clean;
		}

		$seen = array();
		foreach ( $raw['terms'] as $term ) {
			if ( count( $clean['terms'] ) >= self::MAX_SCOPE_TERMS ) {
				break;
			}

			if ( ! is_array( $term ) ) {
				continue;
			}

			$taxonomy = isset( $term['taxonomy'] ) ? sanitize_key( (string) $term['taxonomy'] ) : '';
			$term_id  = isset( $term['id'] ) ? absint( $term['id'] ) : 0;

			if ( ! in_array( $taxonomy, self::SCOPE_TAXONOMIES, true ) || $term_id <= 0 ) {
				continue;
			}

			$key = $taxonomy . ':' . $term_id;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$clean['terms'][] = array(
				'taxonomy' => $taxonomy,
				'id'       => $term_id,
			);
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
	 * These land inside an inline `style` attribute, so anything that could
	 * terminate the declaration or the attribute must go. Restricted to the
	 * character set that colours, lengths and simple keywords need.
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
