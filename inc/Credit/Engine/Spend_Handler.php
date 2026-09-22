<?php
/**
 * Spend rewards credit at checkout.
 *
 * Credit is applied as a negative, non-taxable cart fee (`woocommerce_cart_calculate_fees`,
 * priority 20) — the one mechanism WooCommerce supports on classic checkout,
 * Cart/Checkout Blocks and the Store API alike. The ledger is only touched once
 * the order exists (`woocommerce_checkout_order_processed` /
 * `woocommerce_store_api_checkout_order_processed`), inside a locked FIFO
 * transaction, and released again when the order is cancelled or fails.
 *
 * @package StoreDash\Credit\Engine
 * @since   1.17.0
 */

namespace StoreDash\Credit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Credit_Webhook;
use StoreDash\Credit\Ledger;
use StoreDash\Credit\Settings;

/**
 * Spend flow.
 *
 * @since 1.17.0
 */
class Spend_Handler {

	/**
	 * Cart fee id (stable across locales; the visible name is translated).
	 */
	const FEE_ID = 'storedash_credit';

	/**
	 * Order fee item meta marking our fee line.
	 */
	const FEE_ITEM_META = '_storedash_credit_fee';

	/**
	 * Order meta keys.
	 */
	const META_APPLIED  = '_storedash_credit_applied';
	const META_SPEND_ID = '_storedash_credit_spend_ledger_id';
	const META_RELEASED = '_storedash_credit_released';

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	protected $ledger;

	/**
	 * Eligibility.
	 *
	 * @var Eligibility
	 */
	protected $eligibility;

	/**
	 * Re-entrancy guard for the fee hook.
	 *
	 * @var bool
	 */
	protected $is_processing = false;

	/**
	 * Constructor.
	 *
	 * @param Ledger|null      $ledger      Ledger.
	 * @param Eligibility|null $eligibility Eligibility.
	 */
	public function __construct( $ledger = null, $eligibility = null ) {
		$this->ledger      = $ledger ? $ledger : new Ledger();
		$this->eligibility = $eligibility ? $eligibility : new Eligibility();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_fee' ), 20, 1 );
		add_filter( 'woocommerce_cart_needs_payment', array( $this, 'cart_needs_payment' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'mark_fee_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic_order_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_order_processed' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Visible fee name.
	 *
	 * @return string
	 */
	public static function fee_name(): string {
		return __( 'Rewards credit', 'storedash' );
	}

	// ── Cart ──────────────────────────────────────────────────────────────

	/**
	 * Add the credit fee to the cart.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function apply_fee( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( $this->is_processing ) {
			return;
		}
		$this->is_processing = true;

		try {
			$request = Credit_Request::get();
			if ( empty( $request['apply'] ) ) {
				return;
			}
			$state = $this->cart_state( $cart, $request );
			if ( $state['applied'] <= 0 ) {
				return;
			}

			$cart->fees_api()->add_fee(
				array(
					'id'        => self::FEE_ID,
					'name'      => self::fee_name(),
					'amount'    => -$state['applied'],
					'taxable'   => false,
					'tax_class' => '',
				)
			);
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Rewards credit fee failed: ' . $e->getMessage(), 'error' );
		} finally {
			$this->is_processing = false;
		}
	}

	/**
	 * Full credit state for a cart (used by the fee hook, Store API and UIs).
	 *
	 * @param \WC_Cart|null $cart    Cart (defaults to WC()->cart).
	 * @param array|null    $request Credit request (defaults to the session).
	 * @return array {
	 *     enabled, logged_in, currency, balance, eligible, max_applicable, requested, applied, expiring_next
	 * }
	 */
	public function cart_state( $cart = null, $request = null ): array {
		$settings = Settings::get();
		$decimals = wc_get_price_decimals();
		$cart     = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$request  = is_array( $request ) ? $request : Credit_Request::get();

		$state = array(
			'enabled'        => ! empty( $settings['enabled'] ),
			'logged_in'      => is_user_logged_in(),
			'currency'       => get_woocommerce_currency(),
			'balance'        => 0.0,
			'eligible'       => 0.0,
			'max_applicable' => 0.0,
			'requested'      => $request['amount'],
			'applied'        => 0.0,
			'expiring_next'  => null,
		);

		if ( ! $state['enabled'] || ! $state['logged_in'] || ! $cart ) {
			return $state;
		}

		$customer_key = Customer_Resolver::current_key();
		if ( '' === $customer_key ) {
			return $state;
		}

		$state['balance'] = $this->ledger->balance( $customer_key );
		if ( $state['balance'] <= 0 ) {
			return $state;
		}
		$state['expiring_next'] = $this->ledger->expiring_next( $customer_key );

		$eligible = 0.0;
		$subtotal = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			$line_total = (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
			$subtotal  += $line_total;

			$product = $item['data'] ?? null;
			if ( ! $product || $this->eligibility->product_excluded( $product, $settings ) ) {
				continue;
			}
			if ( empty( $settings['spend_on_sale_items'] ) && $this->eligibility->is_on_sale( $product ) ) {
				continue;
			}
			$eligible += $line_total;
		}

		if ( ! empty( $settings['spend_covers_shipping'] ) ) {
			$eligible += $this->chosen_shipping_total( $cart );
		}

		$state['eligible']       = round( $eligible, $decimals );
		$state['max_applicable'] = Fee_Calculator::max_applicable( $eligible, $subtotal, $state['balance'], $settings, $decimals );
		$state['applied']        = ! empty( $request['apply'] ) ? Fee_Calculator::applied( $state['max_applicable'], $request['amount'], $decimals ) : 0.0;

		return $state;
	}

	/**
	 * Cost (inc. tax) of the chosen shipping rates.
	 *
	 * Fees are calculated before shipping inside WC_Cart_Totals, so the cart's
	 * own shipping total is still 0 here; read the chosen rates from the last
	 * calculated packages instead (empty on the very first cart render — the
	 * next recalculation picks it up).
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return float
	 */
	protected function chosen_shipping_total( $cart ): float {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() || ! WC()->session ) {
			return 0.0;
		}
		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$packages = WC()->shipping()->get_packages();
		$total    = 0.0;

		foreach ( $packages as $index => $package ) {
			$rate_id = $chosen[ $index ] ?? '';
			if ( '' === $rate_id || empty( $package['rates'][ $rate_id ] ) ) {
				continue;
			}
			$rate   = $package['rates'][ $rate_id ];
			$total += (float) $rate->get_cost() + (float) $rate->get_shipping_tax();
		}

		return $total;
	}

	/**
	 * Zero-total carts paid entirely with credit need no gateway.
	 *
	 * @param bool     $needs_payment WooCommerce's answer.
	 * @param \WC_Cart $cart          Cart.
	 * @return bool
	 */
	public function cart_needs_payment( $needs_payment, $cart ) {
		if ( ! $needs_payment ) {
			return $needs_payment;
		}
		if ( (float) $cart->get_total( 'edit' ) > 0 ) {
			return $needs_payment;
		}
		foreach ( $cart->get_fees() as $fee ) {
			if ( self::FEE_ID === $fee->id ) {
				return false;
			}
		}
		return $needs_payment;
	}

	// ── Order creation ────────────────────────────────────────────────────

	/**
	 * Tag our fee line on the order so it survives translation / renames.
	 *
	 * @param \WC_Order_Item_Fee $item    Fee item.
	 * @param string             $fee_key Cart fee id.
	 * @param object             $fee     Cart fee.
	 * @param \WC_Order          $order   Order.
	 */
	public function mark_fee_item( $item, $fee_key, $fee, $order ): void {
		if ( self::FEE_ID === $fee_key || ( isset( $fee->id ) && self::FEE_ID === $fee->id ) ) {
			$item->add_meta_data( self::FEE_ITEM_META, '1', true );
		}
	}

	/**
	 * Credit amount carried by an order's fee line (positive), 0 when none.
	 *
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	public static function fee_amount_on_order( \WC_Order $order ): float {
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( '1' === (string) $fee->get_meta( self::FEE_ITEM_META, true ) || self::fee_name() === $fee->get_name() ) {
				return abs( (float) $fee->get_total() );
			}
		}
		return 0.0;
	}

	/**
	 * Classic checkout: reserve credit once the order exists.
	 *
	 * @param int       $order_id Order id.
	 * @param array     $posted   Posted data.
	 * @param \WC_Order $order    Order.
	 * @throws \Exception When the balance no longer covers the fee (surfaces as a checkout notice).
	 */
	public function on_classic_order_processed( $order_id, $posted, $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->reserve( $order, 'spend:' . $order->get_id() );
		}
	}

	/**
	 * Store API (blocks + headless): reserve credit once the order exists.
	 *
	 * @param \WC_Order $order Order.
	 * @throws \Exception When the balance no longer covers the fee.
	 */
	public function on_store_api_order_processed( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->reserve( $order, 'spend:' . $order->get_id(), true );
		}
	}

	/**
	 * Re-validate and consume credit for an order's fee line.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $idem_key  Idempotency key.
	 * @param bool      $store_api Throw a Store API RouteException (400) instead of a plain Exception.
	 * @throws \Exception When the balance no longer covers the fee.
	 */
	protected function reserve( \WC_Order $order, string $idem_key, bool $store_api = false ): void {
		$amount = self::fee_amount_on_order( $order );
		if ( $amount <= 0 ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_SPEND_ID, true ) && '1' !== (string) $order->get_meta( self::META_RELEASED, true ) ) {
			return; // Already reserved.
		}

		// Account email for registered customers (never the typed billing email).
		$customer_key = $order->get_customer_id() > 0
			? Customer_Resolver::key_for_user( (int) $order->get_customer_id() )
			: '';

		$result = '' === $customer_key
			? new \WP_Error( 'storedash_credit_insufficient', 'Insufficient rewards credit' )
			: $this->ledger->consume(
				$customer_key,
				$amount,
				array(
					'idem_key' => $idem_key,
					'type'     => Ledger::TYPE_SPEND,
					'user_id'  => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
					'order_id' => $order->get_id(),
				)
			);

		if ( is_wp_error( $result ) ) {
			$message = 'storedash_credit_insufficient' === $result->get_error_code()
				? __( 'Your rewards credit balance no longer covers the amount applied. Please refresh the page and try again.', 'storedash' )
				: __( 'Rewards credit could not be applied. Please try again.', 'storedash' );

			\StoreDash_Helpers::log_message( 'Rewards credit reserve failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			$order->add_order_note( sprintf( '%s (%s)', __( 'Rewards credit could not be reserved', 'storedash' ), $result->get_error_message() ) );

			if ( $store_api && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'storedash_credit_insufficient', esc_html( $message ), 400 );
			}
			throw new \Exception( esc_html( $message ) );
		}

		$row      = $result['row'];
		$decimals = wc_get_price_decimals();
		$order->update_meta_data( self::META_APPLIED, wc_format_decimal( $amount, $decimals ) );
		$order->update_meta_data( self::META_SPEND_ID, (int) $row->id );
		$order->update_meta_data( self::META_RELEASED, '0' );
		if ( ! empty( $result['created'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: credit amount */
					__( 'Paid %s with rewards credit.', 'storedash' ),
					wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) )
				)
			);
		}
		$order->save();

		Credit_Request::clear();

		if ( ! empty( $result['created'] ) ) {
			Credit_Webhook::send( 'spent', $row, $result['affected'] );
		}
	}

	// ── Release / re-reserve ──────────────────────────────────────────────

	/**
	 * Release credit when an order is cancelled or fails; re-reserve when a
	 * failed order is later paid.
	 *
	 * @param int       $order_id Order id.
	 * @param string    $from     Old status.
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( in_array( $to, array( 'cancelled', 'failed' ), true ) ) {
			$this->release( $order );
			return;
		}

		if ( in_array( $from, array( 'cancelled', 'failed' ), true )
			&& in_array( $to, array( 'pending', 'on-hold', 'processing', 'completed' ), true )
			&& '1' === (string) $order->get_meta( self::META_RELEASED, true ) ) {
			$attempt = $this->spend_attempts( $order ) + 1;
			try {
				$this->reserve( $order, 'spend:' . $order->get_id() . ':' . $attempt );
			} catch ( \Exception $e ) {
				// Re-reserve after a payment retry cannot block the transition; the
				// order note + log tell the merchant to sort it out by hand.
				\StoreDash_Helpers::log_message( 'Rewards credit re-reserve failed after payment retry: ' . $e->getMessage(), 'error', array( 'order_id' => $order->get_id() ) );
			}
		}
	}

	/**
	 * Release the credit reserved by an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	protected function release( \WC_Order $order ): void {
		if ( '' === (string) $order->get_meta( self::META_SPEND_ID, true ) ) {
			return;
		}
		if ( '1' === (string) $order->get_meta( self::META_RELEASED, true ) ) {
			return;
		}

		$attempt = $this->spend_attempts( $order );
		$idem    = 'release:' . $order->get_id() . ( $attempt > 1 ? ':' . $attempt : '' );
		$result  = $this->ledger->release_order( $order->get_id(), $idem, array( 'user_id' => $order->get_customer_id() ? (int) $order->get_customer_id() : null ) );

		if ( null === $result ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			\StoreDash_Helpers::log_message( 'Rewards credit release failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$order->update_meta_data( self::META_RELEASED, '1' );
		$order->add_order_note(
			sprintf(
				/* translators: %s: credit amount */
				__( 'Released %s rewards credit back to the customer.', 'storedash' ),
				wp_strip_all_tags( wc_price( (float) $result['row']->amount, array( 'currency' => $order->get_currency() ) ) )
			)
		);
		$order->save();

		if ( ! empty( $result['created'] ) ) {
			Credit_Webhook::send( 'released', $result['row'], $result['affected'] );
		}
	}

	/**
	 * Number of spend attempts recorded for an order (from the idempotency keys).
	 *
	 * @param \WC_Order $order Order.
	 * @return int
	 */
	protected function spend_attempts( \WC_Order $order ): int {
		$n = 0;
		for ( $i = 1; $i <= 20; $i++ ) {
			$key = 'spend:' . $order->get_id() . ( $i > 1 ? ':' . $i : '' );
			if ( ! $this->ledger->find_by_idem( $key ) ) {
				break;
			}
			$n = $i;
		}
		return $n;
	}
}
