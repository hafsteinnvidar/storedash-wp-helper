<?php
/**
 * Reserve / release gift card money for orders (contract E).
 *
 * - Order processed (classic `woocommerce_checkout_order_processed`, Store API
 *   `woocommerce_store_api_checkout_order_processed`): the order's gift card
 *   fee lines are spent from the cards in ONE locked transaction. If any card
 *   no longer covers its fee, nothing is spent, the card fee lines are removed
 *   from the order (so the unpaid order cannot be paid at the discounted
 *   total later) and checkout fails with a notice.
 * - A retried checkout on the same order re-syncs: what the order holds per
 *   card is compared with its fee lines; differences are released and re-spent
 *   (`spend:{order}:{card}:{n}` / `release:{order}:{card}:{n}`).
 * - Cancelled / failed → `release` rows restore exactly what the order still
 *   holds. Failed/cancelled → paid again → re-spend (best effort, never blocks).
 *
 * @package StoreDash\GiftCard\Engine
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Money;
use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Gift_Card_Webhook;

/**
 * Order-side money movements.
 *
 * @since 1.24.0
 */
class Reservation {

	/**
	 * Order meta: [{card_id, last4, amount}] (contract E).
	 */
	const META_APPLIED = '_storedash_gift_cards_applied';

	/**
	 * Card store.
	 *
	 * @var Card_Ledger
	 */
	protected $ledger;

	/**
	 * Constructor.
	 *
	 * @param Card_Ledger|null $ledger Card store.
	 */
	public function __construct( $ledger = null ) {
		$this->ledger = $ledger ? $ledger : new Card_Ledger();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic_order_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_order_processed' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Card id => fee amount (positive) for an order's gift card fee lines.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function fees_on_order( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$card_id = (int) $fee->get_meta( Redemption::FEE_ITEM_META, true );
			if ( $card_id > 0 ) {
				$out[ $card_id ] = round( ( $out[ $card_id ] ?? 0.0 ) + abs( (float) $fee->get_total() ), 4 );
			}
		}
		return $out;
	}

	/**
	 * What to release and what to spend so an order holds exactly its fees.
	 *
	 * Pure function — unit tested.
	 *
	 * @param array $fees    card_id => fee amount on the order.
	 * @param array $summary Card_Ledger::summarize_order_rows() result.
	 * @return array { release: card_id => amount, spend: card_id => amount }
	 */
	public static function plan( array $fees, array $summary ): array {
		$plan = array(
			'release' => array(),
			'spend'   => array(),
		);
		$ids  = array_unique( array_merge( array_keys( $fees ), array_keys( $summary ) ) );
		foreach ( $ids as $card_id ) {
			$target = (float) ( $fees[ $card_id ] ?? 0.0 );
			$held   = (float) ( $summary[ $card_id ]['outstanding'] ?? 0.0 );
			if ( abs( $target - $held ) <= Money::EPSILON ) {
				continue;
			}
			if ( $held > Money::EPSILON ) {
				$plan['release'][ $card_id ] = $held;
			}
			if ( $target > Money::EPSILON ) {
				$plan['spend'][ $card_id ] = $target;
			}
		}
		return $plan;
	}

	/**
	 * Classic checkout.
	 *
	 * @param int       $order_id Order id.
	 * @param array     $posted   Posted data.
	 * @param \WC_Order $order    Order.
	 * @throws \Exception When a card no longer covers its fee.
	 */
	public function on_classic_order_processed( $order_id, $posted, $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->sync( $order, true, false );
		}
	}

	/**
	 * Store API (blocks + headless).
	 *
	 * @param \WC_Order $order Order.
	 * @throws \Exception When a card no longer covers its fee.
	 */
	public function on_store_api_order_processed( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->sync( $order, true, true );
		}
	}

	/**
	 * Release on cancelled / failed; re-spend when such an order is paid later.
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
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) {
			return;
		}

		if ( in_array( $to, array( 'cancelled', 'failed' ), true ) ) {
			$this->release_all( $order );
			return;
		}

		if ( in_array( $from, array( 'cancelled', 'failed' ), true )
			&& in_array( $to, array( 'pending', 'on-hold', 'processing', 'completed' ), true )
			&& ! empty( self::fees_on_order( $order ) ) ) {
			try {
				$this->sync( $order, false, false );
			} catch ( \Exception $e ) {
				// Never block the transition; the note tells the merchant.
				\StoreDash_Helpers::log_message( 'Gift card re-reserve failed after payment retry: ' . $e->getMessage(), 'error', array( 'order_id' => $order->get_id() ) );
			}
		}
	}

	/**
	 * Make the order hold exactly its gift card fee amounts.
	 *
	 * @param \WC_Order $order     Order.
	 * @param bool      $checkout  At checkout: on failure strip the card fees and throw.
	 * @param bool      $store_api Throw a Store API RouteException.
	 * @throws \Exception When a card no longer covers its fee (checkout only).
	 */
	public function sync( \WC_Order $order, bool $checkout, bool $store_api ): void {
		$fees = self::fees_on_order( $order );
		$plan = self::plan( $fees, $this->ledger->order_summary( $order->get_id() ) );
		if ( empty( $plan['release'] ) && empty( $plan['spend'] ) ) {
			return;
		}

		foreach ( $plan['release'] as $card_id => $amount ) {
			$this->release_card( $order, (int) $card_id, (float) $amount );
		}

		$result = $this->ledger->spend_order( $order->get_id(), $plan['spend'] );

		if ( is_wp_error( $result ) && 'storedash_gift_card_conflict' === $result->get_error_code() ) {
			// A concurrent request for the same order may have done the work.
			$again = self::plan( $fees, $this->ledger->order_summary( $order->get_id() ) );
			if ( empty( $again['spend'] ) && empty( $again['release'] ) ) {
				$result = array();
			}
		}

		if ( is_wp_error( $result ) ) {
			\StoreDash_Helpers::log_message( 'Gift card reserve failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			$order->add_order_note( __( 'Gift card payment could not be reserved: the card balance changed. The gift card was removed from this order.', 'storedash' ) );

			// Only the cards that could not be (re)spent lose their fee lines;
			// cards still held by the order keep theirs.
			$this->strip_card_fees( $order, array_keys( $plan['spend'] ) );

			if ( $checkout ) {
				$message = __( 'Your gift card balance has changed. Please re-enter your gift card and try again.', 'storedash' );
				if ( $store_api && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'storedash_gift_card_insufficient', esc_html( $message ), 400 );
				}
				throw new \Exception( esc_html( $message ) );
			}

			// Reactivated order (e.g. cancelled → processing): the total now includes
			// the part the card no longer covers, which nobody has paid yet.
			$this->write_applied_meta( $order, self::fees_on_order( $order ) );
			if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: %s: new order total */
						__( 'Gift card could not be charged again, so the order total is now %s and has not been paid. Collect the payment before shipping.', 'storedash' ),
						wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
					)
				);
			} else {
				$order->save();
			}
			return;
		}

		$this->write_applied_meta( $order, $fees );

		foreach ( $result as $move ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: last 4 characters of the gift card code */
					__( 'Paid %1$s with gift card ····%2$s.', 'storedash' ),
					wp_strip_all_tags( wc_price( abs( (float) $move['row']->amount ), array( 'currency' => $order->get_currency() ) ) ),
					$move['card']->code_last4
				)
			);
		}
		$order->save();

		foreach ( $result as $move ) {
			Gift_Card_Webhook::send( 'spent', $move['card'], $move['row'] );
		}
	}

	/**
	 * Release everything the order still holds.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function release_all( \WC_Order $order ): void {
		$summary = $this->ledger->order_summary( $order->get_id() );
		$changed = false;
		foreach ( $summary as $card_id => $totals ) {
			if ( $totals['outstanding'] > Money::EPSILON ) {
				$changed = $this->release_card( $order, (int) $card_id, (float) $totals['outstanding'] ) || $changed;
			}
		}
		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Release one card's hold on an order.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $card_id Card id.
	 * @param float     $amount  Amount.
	 * @return bool Whether a row was written.
	 */
	protected function release_card( \WC_Order $order, int $card_id, float $amount ): bool {
		$summary = $this->ledger->order_summary( $order->get_id() );
		$n       = max( 1, (int) ( $summary[ $card_id ]['spends'] ?? 1 ) );
		$result  = $this->ledger->move(
			$card_id,
			$amount,
			Card_Ledger::TYPE_RELEASE,
			'release:' . $order->get_id() . ':' . $card_id . ':' . $n,
			array( 'order_id' => $order->get_id() )
		);

		if ( null === $result ) {
			return false;
		}
		if ( is_wp_error( $result ) ) {
			\StoreDash_Helpers::log_message( 'Gift card release failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			return false;
		}
		if ( empty( $result['created'] ) ) {
			return false;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: last 4 characters of the gift card code */
				__( 'Released %1$s back to gift card ····%2$s.', 'storedash' ),
				wp_strip_all_tags( wc_price( abs( (float) $result['row']->amount ), array( 'currency' => $order->get_currency() ) ) ),
				$result['card']->code_last4
			)
		);
		Gift_Card_Webhook::send( 'released', $result['card'], $result['row'] );
		return true;
	}

	/**
	 * Order meta `_storedash_gift_cards_applied`.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $fees  card_id => amount.
	 */
	protected function write_applied_meta( \WC_Order $order, array $fees ): void {
		$decimals = wc_get_price_decimals();
		$applied  = array();
		foreach ( $fees as $card_id => $amount ) {
			$card      = $this->ledger->get_card( (int) $card_id );
			$applied[] = array(
				'card_id' => (int) $card_id,
				'last4'   => $card ? (string) $card->code_last4 : '',
				'amount'  => Money::to_decimal_string( $amount, $decimals ),
			);
		}
		$order->update_meta_data( self::META_APPLIED, $applied );
	}

	/**
	 * Remove gift card fee lines and recompute totals (no tax recalculation).
	 *
	 * @param \WC_Order $order    Order.
	 * @param int[]     $card_ids Only these cards' lines (all when empty).
	 */
	protected function strip_card_fees( \WC_Order $order, array $card_ids = array() ): void {
		$card_ids = array_map( 'intval', $card_ids );
		try {
			foreach ( $order->get_items( 'fee' ) as $item_id => $fee ) {
				$card_id = (int) $fee->get_meta( Redemption::FEE_ITEM_META, true );
				if ( $card_id > 0 && ( empty( $card_ids ) || in_array( $card_id, $card_ids, true ) ) ) {
					$order->remove_item( $item_id );
				}
			}
			$order->calculate_totals( false );
			$order->save();
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Gift card fee strip failed: ' . $e->getMessage(), 'error', array( 'order_id' => $order->get_id() ) );
		}
	}
}
