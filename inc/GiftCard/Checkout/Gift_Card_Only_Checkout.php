<?php
/**
 * Gift-card-only carts: no billing address.
 *
 * When every cart line is a gift card product nothing ships and no address is
 * needed, so the billing address fields (address_1, address_2, city, state,
 * postcode) become optional + hidden. Name and email stay required, phone
 * keeps the store setting, and country (WooCommerce always requires it) falls
 * back to the store's base country when a Store API checkout omits it.
 *
 * WooCommerce caches country locales per request (WC_Countries::$locale), and
 * that cache may be built before the cart is loaded, so the cache is reset
 * whenever the cart's gift-card-only state is (re)evaluated:
 * - cart loaded from session (frontend render of classic / block checkout),
 * - classic `woocommerce_checkout_process` (before WC_Checkout validation),
 * - Store API `woocommerce_store_api_checkout_update_order_from_request`
 *   (before OrderController::validate_order_before_payment()).
 *
 * @package StoreDash\GiftCard\Checkout
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\GiftCard\Product\Gift_Card_Product;

/**
 * Address relaxation for gift-card-only carts.
 *
 * @since 1.24.0
 */
class Gift_Card_Only_Checkout {

	/**
	 * Address fields that become optional + hidden.
	 */
	const RELAXED_FIELDS = array( 'address_1', 'address_2', 'city', 'state', 'postcode' );

	/**
	 * Whether the current cart is gift-card-only (null = not evaluated yet).
	 *
	 * @var bool|null
	 */
	protected $active = null;

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'refresh' ), 20 );
		add_action( 'woocommerce_checkout_process', array( $this, 'refresh' ), 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'on_store_api_checkout' ), 1, 2 );

		add_filter( 'woocommerce_get_country_locale', array( $this, 'filter_locales' ), 999 );
		add_filter( 'woocommerce_get_country_locale_default', array( $this, 'filter_locale_entry' ), 999 );
		add_filter( 'woocommerce_get_country_locale_base', array( $this, 'filter_locale_entry' ), 999 );
		add_filter( 'woocommerce_billing_fields', array( $this, 'filter_billing_fields' ), 999 );
	}

	// ── Pure helpers ──────────────────────────────────────────────────────

	/**
	 * Whether every cart line is a gift card (false for an empty cart).
	 *
	 * @param array $cart_contents WC_Cart::get_cart().
	 * @return bool
	 */
	public static function is_gift_card_only( array $cart_contents ): bool {
		if ( empty( $cart_contents ) ) {
			return false;
		}
		foreach ( $cart_contents as $item ) {
			if ( empty( $item['data'] ) || ! Gift_Card_Product::is_gift_card( $item['data'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Make the relaxed fields optional + hidden in one locale entry.
	 *
	 * Pure function — unit tested.
	 *
	 * @param array $entry Locale entry (field => props).
	 * @return array
	 */
	public static function relax_entry( array $entry ): array {
		foreach ( self::RELAXED_FIELDS as $field ) {
			$props             = isset( $entry[ $field ] ) && is_array( $entry[ $field ] ) ? $entry[ $field ] : array();
			$props['required'] = false;
			$props['hidden']   = true;
			$entry[ $field ]   = $props;
		}
		return $entry;
	}

	// ── Hooks ─────────────────────────────────────────────────────────────

	/**
	 * Re-evaluate the cart; reset WooCommerce's locale cache when the state changed.
	 */
	public function refresh(): void {
		$cart   = function_exists( 'WC' ) ? WC()->cart : null;
		$active = $cart ? self::is_gift_card_only( $cart->get_cart() ) : false;
		if ( $active !== $this->active ) {
			$this->active = $active;
			if ( function_exists( 'WC' ) && WC()->countries ) {
				WC()->countries->locale = array();
			}
		}
	}

	/**
	 * Store API checkout: re-evaluate and default the billing country.
	 *
	 * @param \WC_Order        $order   Draft order.
	 * @param \WP_REST_Request $request Request.
	 */
	public function on_store_api_checkout( $order, $request ): void {
		$this->refresh();
		if ( $this->active && $order instanceof \WC_Order && '' === (string) $order->get_billing_country() && function_exists( 'WC' ) && WC()->countries ) {
			$order->set_billing_country( WC()->countries->get_base_country() );
		}
	}

	/**
	 * Per-country locales.
	 *
	 * @param array $locales country => entry.
	 * @return array
	 */
	public function filter_locales( $locales ) {
		if ( ! $this->is_active() || ! is_array( $locales ) ) {
			return $locales;
		}
		foreach ( $locales as $country => $entry ) {
			$locales[ $country ] = self::relax_entry( is_array( $entry ) ? $entry : array() );
		}
		return $locales;
	}

	/**
	 * Default / base locale entry.
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	public function filter_locale_entry( $entry ) {
		return ( $this->is_active() && is_array( $entry ) ) ? self::relax_entry( $entry ) : $entry;
	}

	/**
	 * Classic checkout billing fields.
	 *
	 * @param array $fields billing_* => props.
	 * @return array
	 */
	public function filter_billing_fields( $fields ) {
		if ( ! $this->is_active() || ! is_array( $fields ) ) {
			return $fields;
		}
		foreach ( self::RELAXED_FIELDS as $field ) {
			if ( isset( $fields[ 'billing_' . $field ] ) ) {
				// Hidden on screen by wc-address-i18n from the (relaxed) country locale.
				$fields[ 'billing_' . $field ]['required'] = false;
				$fields[ 'billing_' . $field ]['hidden']   = true;
			}
		}
		return $fields;
	}

	/**
	 * Whether the current cart is gift-card-only (evaluates lazily).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( null === $this->active ) {
			$cart = function_exists( 'WC' ) ? WC()->cart : null;
			if ( ! $cart || ! did_action( 'woocommerce_cart_loaded_from_session' ) ) {
				return false;
			}
			$this->active = self::is_gift_card_only( $cart->get_cart() );
		}
		return $this->active;
	}
}
