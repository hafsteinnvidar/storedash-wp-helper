<?php
/**
 * Block Checkout SMS Consent Field
 *
 * Second Additional Checkout Field (`storedash/sms-marketing-optin`) for the
 * WooCommerce Blocks checkout. Separate consent per channel: it never shares
 * the e-mail opt-in field or option. The saved value is bridged into the
 * `_woodash_sms_optin` order meta BEFORE Opt_In_Handler::handle_order_sms_optin
 * runs (priority 20), exactly as Blocks_Optin_Field does for e-mail.
 *
 * @package StoreDash\Services\Optin
 * @since   1.29.0
 */

namespace StoreDash\Services\Optin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block Checkout SMS Opt-in Field
 */
class Blocks_Sms_Optin_Field {

	/**
	 * Additional Checkout Field id (lowercase letters, digits and hyphens only).
	 */
	const FIELD_ID = 'storedash/sms-marketing-optin';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_init', array( $this, 'register_field' ) );
		// Priority 15: before Opt_In_Handler::handle_order_sms_optin (20).
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'bridge_optin_to_order_meta' ), 15 );
	}

	/**
	 * Register the block-checkout SMS consent checkbox (WC 8.9+ only).
	 */
	public function register_field() {
		if ( ! get_option( Opt_In_Handler::SMS_ENABLED_OPTION, false ) ) {
			return;
		}

		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::FIELD_ID,
					'label'    => \StoreDash_Helpers::checkout_sms_optin_label(),
					'location' => 'contact',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Exception $e ) {
			if ( class_exists( 'StoreDash_Helpers' ) ) {
				\StoreDash_Helpers::debug_log( 'Blocks SMS opt-in field registration failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Copy the Additional Checkout Field value into `_woodash_sms_optin`.
	 *
	 * @param int|\WC_Order $order Order ID or object.
	 */
	public function bridge_optin_to_order_meta( $order ) {
		if ( ! get_option( Opt_In_Handler::SMS_ENABLED_OPTION, false ) ) {
			return;
		}

		$order = wc_get_order( $order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$meta_key = Blocks_Optin_Field::OTHER_FIELDS_PREFIX . self::FIELD_ID;
		$raw      = $order->get_meta( $meta_key );

		// Absent entirely = the block checkout did not submit it; nothing to bridge.
		if ( '' === $raw && ! $order->meta_exists( $meta_key ) ) {
			return;
		}

		$is_opted_in = function_exists( 'wc_string_to_bool' )
			? wc_string_to_bool( $raw )
			: (bool) $raw;

		$order->update_meta_data( Opt_In_Handler::SMS_META, $is_opted_in ? 1 : 0 );
		$order->save();
	}
}

// Initialize (mirrors Blocks_Optin_Field).
new Blocks_Sms_Optin_Field();
