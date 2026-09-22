<?php
/**
 * Claw back earned credit and return spent credit on refunds.
 *
 * `woocommerce_order_refunded` fires for full and partial refunds from every
 * surface (admin, wc/v3, Storedash). Two independent, idempotent effects:
 *
 * 1. Earned credit is reversed pro-rata (refund ÷ order total × earned), capped
 *    at what is still unspent on the earn row.   idem `reverse:{refund_id}`
 * 2. If the order was (partly) paid with credit and `refund_credit_as_credit`
 *    is on, the credit-covered share of the refund comes back as a new
 *    credit-holding row (type `reverse`, positive, original expiry).
 *                                                idem `refund_credit:{refund_id}`
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
use StoreDash\Credit\Money;
use StoreDash\Credit\Settings;

/**
 * Refund flow.
 *
 * @since 1.17.0
 */
class Refund_Handler {

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	protected $ledger;

	/**
	 * Constructor.
	 *
	 * @param Ledger|null $ledger Ledger.
	 */
	public function __construct( $ledger = null ) {
		$this->ledger = $ledger ? $ledger : new Ledger();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund' ), 20, 2 );
	}

	/**
	 * Refund handler.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	public function on_refund( $order_id, $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$settings    = Settings::get();
		$decimals    = wc_get_price_decimals();
		$refund_amt  = abs( (float) $refund->get_amount() );
		$order_total = (float) $order->get_total();
		if ( $refund_amt <= 0 ) {
			return;
		}

		$share = self::share( $refund_amt, $order_total );

		$this->reverse_earned( $order, $refund, $share, $decimals );

		if ( ! empty( $settings['refund_credit_as_credit'] ) ) {
			$this->refund_as_credit( $order, $refund, $share, $decimals );
		}
	}

	/**
	 * Proportion of the order a refund represents (0..1).
	 *
	 * Pure function — unit tested.
	 *
	 * @param float $refund_amount Refund amount.
	 * @param float $order_total   Order total (cash paid).
	 * @return float
	 */
	public static function share( float $refund_amount, float $order_total ): float {
		if ( $order_total <= 0 ) {
			return 1.0;
		}
		return max( 0.0, min( 1.0, $refund_amount / $order_total ) );
	}

	/**
	 * Claw back the pro-rata part of the credit earned on the order.
	 *
	 * @param \WC_Order        $order    Order.
	 * @param \WC_Order_Refund $refund   Refund.
	 * @param float            $share    Refund share (0..1).
	 * @param int              $decimals Price decimals.
	 */
	protected function reverse_earned( \WC_Order $order, \WC_Order_Refund $refund, float $share, int $decimals ): void {
		$earned  = (float) $order->get_meta( Earn_Handler::META_EARNED, true );
		$earn_id = (int) $order->get_meta( Earn_Handler::META_EARNED_ID, true );
		if ( $earned <= 0 || $earn_id <= 0 ) {
			return;
		}

		$amount = Money::round( $earned * $share, 'nearest', $decimals );
		if ( $amount <= 0 ) {
			return;
		}

		$result = $this->ledger->reverse_earn(
			$earn_id,
			$amount,
			'reverse:' . $refund->get_id(),
			array(
				'refund_id' => $refund->get_id(),
				'order_id'  => $order->get_id(),
				'user_id'   => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
			)
		);

		if ( null === $result ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			\StoreDash_Helpers::log_message( 'Rewards credit reverse failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			return;
		}

		if ( ! empty( $result['created'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: credit amount */
					__( 'Reversed %s rewards credit after refund.', 'storedash' ),
					wp_strip_all_tags( wc_price( abs( (float) $result['row']->amount ), array( 'currency' => $order->get_currency() ) ) )
				)
			);
			Credit_Webhook::send( 'reversed', $result['row'], $result['affected'] );
		}
	}

	/**
	 * Return the credit-covered share of the refund as fresh credit.
	 *
	 * @param \WC_Order        $order    Order.
	 * @param \WC_Order_Refund $refund   Refund.
	 * @param float            $share    Refund share (0..1).
	 * @param int              $decimals Price decimals.
	 */
	protected function refund_as_credit( \WC_Order $order, \WC_Order_Refund $refund, float $share, int $decimals ): void {
		$applied  = (float) $order->get_meta( Spend_Handler::META_APPLIED, true );
		$spend_id = (int) $order->get_meta( Spend_Handler::META_SPEND_ID, true );
		if ( $applied <= 0 || $spend_id <= 0 ) {
			return;
		}
		if ( '1' === (string) $order->get_meta( Spend_Handler::META_RELEASED, true ) ) {
			return; // Credit already went back via release.
		}

		$already = $this->ledger->refunded_as_credit_total( $order->get_id() );
		$amount  = Money::round( $applied * $share, 'nearest', $decimals );
		$amount  = min( $amount, max( 0.0, $applied - $already ) );
		if ( $amount <= 0 ) {
			return;
		}

		$customer_key = Customer_Resolver::key_for_order( $order );
		if ( '' === $customer_key ) {
			return;
		}

		$result = $this->ledger->add_credit(
			array(
				'customer_key' => $customer_key,
				'user_id'      => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
				'order_id'     => $order->get_id(),
				'refund_id'    => $refund->get_id(),
				'amount'       => $amount,
				'expires_at'   => $this->original_expiry( $spend_id ),
				'idem_key'     => 'refund_credit:' . $refund->get_id(),
				'type'         => Ledger::TYPE_REVERSE,
				'meta'         => array( 'refund_of_spend_id' => $spend_id ),
			)
		);

		if ( is_wp_error( $result ) ) {
			\StoreDash_Helpers::log_message( 'Rewards credit refund-as-credit failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			return;
		}

		if ( ! empty( $result['created'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: credit amount */
					__( 'Refunded %s as rewards credit.', 'storedash' ),
					wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) )
				)
			);
			Credit_Webhook::send( 'reversed', $result['row'] );
		}
	}

	/**
	 * Expiry to give returned credit: the latest expiry among the earn rows the
	 * spend consumed, or null (never) when any of them never expired.
	 *
	 * @param int $spend_id Spend row id.
	 * @return string|null
	 */
	protected function original_expiry( int $spend_id ) {
		$spend = $this->ledger->get( $spend_id );
		if ( ! $spend || empty( $spend->meta ) ) {
			return null;
		}
		$meta = json_decode( (string) $spend->meta, true );
		if ( empty( $meta['allocated'] ) || ! is_array( $meta['allocated'] ) ) {
			return null;
		}

		$latest = null;
		foreach ( array_keys( $meta['allocated'] ) as $earn_id ) {
			$earn = $this->ledger->get( (int) $earn_id );
			if ( ! $earn || empty( $earn->expires_at ) ) {
				return null;
			}
			if ( null === $latest || $earn->expires_at > $latest ) {
				$latest = (string) $earn->expires_at;
			}
		}

		// Never hand back already-expired credit: fall back to "expires now + 0"
		// would be pointless, so give it the store default instead.
		if ( null !== $latest && $latest <= gmdate( 'Y-m-d H:i:s' ) ) {
			$settings = Settings::get();
			return Earn_Calculator::expires_at( array(), $settings, time() );
		}

		return $latest;
	}
}
