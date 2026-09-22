<?php
/**
 * Block Checkout Marketing Opt-in Field
 *
 * Renders a marketing opt-in checkbox on the WooCommerce Blocks (Store API)
 * checkout using the Additional Checkout Fields API and bridges the chosen
 * value into the `_woodash_marketing_optin` order meta key BEFORE the shared
 * Opt_In_Handler runs (priority 20), so the existing `subscription.optin`
 * webhook fires for block checkout with no change to the envelope.
 *
 * The classic checkout keeps using Cart_Tracking::display_checkout_optin()
 * (which posts `woodash_marketing_optin`); this class is the block-checkout
 * counterpart. Both are gated on the same `woodash_checkout_optin_enabled`
 * option so the dashboard toggle controls both at once.
 *
 * Requires WooCommerce 8.9+ (Additional Checkout Fields API). Everything is
 * guarded with function_exists checks so older WC / no-Blocks installs are
 * inert rather than fatal.
 *
 * @package StoreDash\Services\Optin
 * @since   1.2.5
 */

namespace StoreDash\Services\Optin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block Checkout Opt-in Field
 */
class Blocks_Optin_Field {

	/**
	 * Additional Checkout Field id (namespace/key).
	 *
	 * NOTE: WooCommerce validates field ids against `^[a-z0-9-]+/[a-z0-9-]+$`
	 * — only lowercase letters, digits and hyphens (no underscores). Hence the
	 * hyphenated `marketing-optin` rather than `marketing_optin`.
	 */
	const FIELD_ID = 'storedash/marketing-optin';

	/**
	 * Prefix WooCommerce uses to persist "contact"/"order" location additional
	 * fields to order + customer meta. Documented as a stable value
	 * (CheckoutFields::OTHER_FIELDS_PREFIX). Used to read the saved value back
	 * without coupling to the (relocated-across-versions) CheckoutFields class.
	 */
	const OTHER_FIELDS_PREFIX = '_wc_other/';

	/**
	 * Order meta key the shared Opt_In_Handler reads to decide whether to fire
	 * the opt-in webhook. Mirrors the classic-checkout contract.
	 */
	const BRIDGE_META = '_woodash_marketing_optin';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register the field on woocommerce_init (the documented hook for the
		// Additional Checkout Fields API). Fires on `init:0`, after this object
		// is constructed during plugins_loaded:20.
		add_action( 'woocommerce_init', array( $this, 'register_field' ) );

		// Bridge the saved field value into `_woodash_marketing_optin`.
		// Priority 15: AFTER Cart_Tracking::checkout_order_processed (10), which
		// writes the meta to 0 on block checkout (no $_POST), and BEFORE
		// Opt_In_Handler::handle_order_optin (20), which reads it.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'bridge_optin_to_order_meta' ), 15 );
	}

	/**
	 * Register the block-checkout opt-in checkbox.
	 *
	 * Only registers when the feature is enabled AND the Additional Checkout
	 * Fields API is present (WC 8.9+ with Blocks).
	 */
	public function register_field() {
		if ( ! get_option( 'woodash_checkout_optin_enabled', false ) ) {
			return;
		}

		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::FIELD_ID,
					'label'    => \StoreDash_Helpers::checkout_optin_label(),
					'location' => 'contact',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Exception $e ) {
			// Re-registration / validation issues must never break checkout.
			if ( class_exists( 'StoreDash_Helpers' ) ) {
				\StoreDash_Helpers::debug_log( 'Blocks opt-in field registration failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Copy the Additional Checkout Field value into the bridge order meta.
	 *
	 * WooCommerce persists "contact" location fields to the order under the
	 * `_wc_other/{id}` meta key during Store API order creation, before this
	 * hook fires. We normalise that to a strict 1/0 and write it to
	 * `_woodash_marketing_optin` so the shared Opt_In_Handler (priority 20)
	 * fires the webhook for block checkout exactly as it does for classic.
	 *
	 * @param int|\WC_Order $order Order ID or object.
	 */
	public function bridge_optin_to_order_meta( $order ) {
		if ( ! get_option( 'woodash_checkout_optin_enabled', false ) ) {
			return;
		}

		$order = wc_get_order( $order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$meta_key = self::OTHER_FIELDS_PREFIX . self::FIELD_ID;
		$raw      = $order->get_meta( $meta_key );

		// The field is only persisted when the block checkout submitted it. If
		// it's absent entirely there's nothing to bridge (leave any classic /
		// cart-tracking value untouched).
		if ( '' === $raw && ! $order->meta_exists( $meta_key ) ) {
			return;
		}

		$is_opted_in = function_exists( 'wc_string_to_bool' )
			? wc_string_to_bool( $raw )
			: (bool) $raw;

		$order->update_meta_data( self::BRIDGE_META, $is_opted_in ? 1 : 0 );
		$order->save();
	}
}

// Initialize (mirrors Opt_In_Handler).
new Blocks_Optin_Field();
