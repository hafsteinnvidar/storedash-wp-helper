<?php
/**
 * Block checkout "use my rewards credit" field.
 *
 * Registers an Additional Checkout Field (`storedash/use-credit`, checkbox,
 * `order` location) so Cart/Checkout Blocks get the toggle without a JS
 * bundle. WooCommerce only delivers `order`-location values with the checkout
 * request itself (POST /wc/store/v1/checkout, and PUT /checkout on stores
 * that sync fields early), so in P1 the fee is applied when the order is
 * placed: the session flag is set in
 * `woocommerce_store_api_checkout_update_order_from_request`, the cart is
 * recalculated, and the draft order's fee lines + totals are re-synced from
 * the cart before `…order_processed` reserves the credit. Headless storefronts
 * get live totals through the Store API update callback instead.
 *
 * @package StoreDash\Credit\Checkout
 * @since   1.17.0
 */

namespace StoreDash\Credit\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Engine\Credit_Request;
use StoreDash\Credit\Engine\Customer_Resolver;
use StoreDash\Credit\Engine\Spend_Handler;
use StoreDash\Credit\Ledger;
use StoreDash\Credit\Settings;

/**
 * Additional Checkout Field bridge.
 *
 * @since 1.17.0
 */
class Blocks_Checkout_Field {

	/**
	 * Field id (WooCommerce requires `^[a-z0-9-]+/[a-z0-9-]+$`).
	 */
	const FIELD_ID = 'storedash/use-credit';

	/**
	 * Spend handler.
	 *
	 * @var Spend_Handler
	 */
	protected $spend;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	protected $ledger;

	/**
	 * Constructor.
	 *
	 * @param Spend_Handler $spend  Spend handler.
	 * @param Ledger|null   $ledger Ledger.
	 */
	public function __construct( Spend_Handler $spend, $ledger = null ) {
		$this->spend  = $spend;
		$this->ledger = $ledger ? $ledger : new Ledger();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_init', array( $this, 'register_field' ) );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( $this, 'on_cart_update_customer' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'on_checkout_update_order' ), 5, 2 );
	}

	/**
	 * Register the checkbox (only when the shopper can actually use credit).
	 */
	public function register_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		if ( ! Settings::is_enabled() || ! is_user_logged_in() ) {
			return;
		}

		// woocommerce_init runs before the cart is loaded from the session, so
		// read the balance straight from the ledger rather than via cart_state().
		$customer_key = Customer_Resolver::current_key();
		$balance      = '' === $customer_key ? 0.0 : $this->ledger->balance( $customer_key );
		if ( $balance <= 0 ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::FIELD_ID,
					'label'    => sprintf(
						/* translators: %s: formatted balance */
						__( 'Use my rewards credit (available: %s)', 'storedash' ),
						wp_strip_all_tags( wc_price( $balance ) )
					),
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Exception $e ) {
			\StoreDash_Helpers::debug_log( 'Rewards credit block field registration failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Read the field from a Store API request, if it is there.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|null True/false when present, null when absent.
	 */
	protected function field_value( $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return null;
		}
		$fields = $request->get_param( 'additional_fields' );
		if ( ! is_array( $fields ) || ! array_key_exists( self::FIELD_ID, $fields ) ) {
			return null;
		}
		return function_exists( 'wc_string_to_bool' ) ? wc_string_to_bool( $fields[ self::FIELD_ID ] ) : (bool) $fields[ self::FIELD_ID ];
	}

	/**
	 * Cart update-customer: honour the field when a store sends it early.
	 *
	 * @param \WC_Customer     $customer Customer.
	 * @param \WP_REST_Request $request  Request.
	 */
	public function on_cart_update_customer( $customer, $request ): void {
		$value = $this->field_value( $request );
		if ( null === $value || ! Settings::is_enabled() ) {
			return;
		}
		Credit_Request::set( $value, null );
	}

	/**
	 * Checkout POST/PUT: set the flag and re-sync the draft order from the cart.
	 *
	 * @param \WC_Order        $order   Draft order.
	 * @param \WP_REST_Request $request Request.
	 */
	public function on_checkout_update_order( $order, $request ): void {
		$value = $this->field_value( $request );
		if ( null === $value || ! Settings::is_enabled() || ! $order instanceof \WC_Order ) {
			return;
		}

		$current = Credit_Request::get();
		if ( (bool) $current['apply'] === $value && ( ! $value || null === $current['amount'] ) ) {
			return; // Nothing changed — the draft order already matches the cart.
		}

		Credit_Request::set( $value, null );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		WC()->cart->calculate_totals();
		$this->resync_fees_from_cart( $order );
	}

	/**
	 * Replace the draft order's fee lines with the cart's and recompute totals.
	 *
	 * The draft order was built from the cart before the field was read, so
	 * without this the fee would be missing from the order (and the ledger
	 * never touched) even though the shopper ticked the box.
	 *
	 * @param \WC_Order $order Draft order.
	 */
	protected function resync_fees_from_cart( \WC_Order $order ): void {
		try {
			$order->remove_order_items( 'fee' );
			WC()->checkout()->create_order_fee_lines( $order, WC()->cart );
			$order->calculate_totals( false );
			$order->save();
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Rewards credit block checkout fee resync failed: ' . $e->getMessage(), 'error', array( 'order_id' => $order->get_id() ) );
		}
	}
}
