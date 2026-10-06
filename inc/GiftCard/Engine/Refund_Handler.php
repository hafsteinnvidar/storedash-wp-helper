<?php
/**
 * Refunds and gift cards (contract E).
 *
 * `woocommerce_order_refunded` (every surface) has two independent,
 * idempotent effects:
 *
 * 1. Order PAID with gift cards: the card part goes back to the card(s),
 *    pro-rata. The refund amount is the gateway (cash) part, so the cards get
 *    refund × card_paid ÷ cash_total back (a full cash refund returns the
 *    cards in full). Orders paid entirely by card (cash total 0) use the value
 *    of the refunded lines instead. `refund:{refund_id}:{card_id}`.
 * 2. Refund of a gift card PURCHASE line: the cards minted for that line lose
 *    the refunded share of their FACE value (coupons may have discounted the line) (never below 0; what was already spent is noted on the
 *    order for the merchant). `adjust` row, `refund_purchase:{refund_id}:{card_id}`.
 *
 * Marking an order Refunded also returns whatever the cards still hold on it
 * (covers card-only orders, where WooCommerce creates no refund).
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
use StoreDash\GiftCard\Product\Recipient_Fields;

/**
 * Refund flow.
 *
 * @since 1.24.0
 */
class Refund_Handler {

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
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund' ), 20, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_status_refunded' ), 20, 1 );
	}

	// ── Pure math ─────────────────────────────────────────────────────────

	/**
	 * Share of the card payment a refund returns (0..1).
	 *
	 * Pure function — unit tested.
	 *
	 * @param float $refund_amount Refund amount (cash).
	 * @param float $cash_total    Order total (cash paid).
	 * @param float $lines_value   Gross value of the refunded lines (card-only orders).
	 * @param float $order_gross   Gross order value before gift cards (card-only orders).
	 * @return float
	 */
	public static function share( float $refund_amount, float $cash_total, float $lines_value, float $order_gross ): float {
		if ( $cash_total > Money::EPSILON ) {
			return max( 0.0, min( 1.0, $refund_amount / $cash_total ) );
		}
		if ( $order_gross > Money::EPSILON ) {
			return max( 0.0, min( 1.0, $lines_value / $order_gross ) );
		}
		return 0.0;
	}

	/**
	 * Split an amount across cards pro-rata to their weights, capped per card.
	 *
	 * Pure function — unit tested. The last card with room absorbs rounding.
	 *
	 * @param float $amount   Amount to split.
	 * @param array $weights  card_id => weight (what the card paid).
	 * @param array $caps     card_id => max returnable.
	 * @param int   $decimals Price decimals.
	 * @return array card_id => amount (> 0 only)
	 */
	public static function distribute( float $amount, array $weights, array $caps, int $decimals ): array {
		$total_weight = array_sum( array_map( 'floatval', $weights ) );
		$amount       = round( min( $amount, array_sum( array_map( 'floatval', $caps ) ) ), $decimals );
		if ( $amount <= 0 || $total_weight <= 0 ) {
			return array();
		}

		$out  = array();
		$left = $amount;
		$ids  = array_keys( $weights );
		$last = end( $ids );
		foreach ( $weights as $card_id => $weight ) {
			$cap   = (float) ( $caps[ $card_id ] ?? 0.0 );
			$share = $card_id === $last ? $left : round( $amount * (float) $weight / $total_weight, $decimals );
			$take  = round( min( $share, $cap, $left ), $decimals );
			if ( $take > 0 ) {
				$out[ $card_id ] = $take;
				$left            = round( $left - $take, $decimals );
			}
		}

		// Rounding / caps left something over: give it to any card with room.
		foreach ( $weights as $card_id => $weight ) {
			if ( $left <= 0 ) {
				break;
			}
			$room = round( (float) ( $caps[ $card_id ] ?? 0.0 ) - ( $out[ $card_id ] ?? 0.0 ), $decimals );
			if ( $room > 0 ) {
				$take            = min( $room, $left );
				$out[ $card_id ] = round( ( $out[ $card_id ] ?? 0.0 ) + $take, $decimals );
				$left            = round( $left - $take, $decimals );
			}
		}

		return $out;
	}

	/**
	 * Card value to take back for a refunded gift card purchase line.
	 *
	 * Pure function — unit tested. Coupons may discount gift card lines, but a
	 * card is always worth its pre-coupon face value, so the refunded share of
	 * what was PAID maps to the same share of the face value (a 10k card bought
	 * for 8k and refunded 8k loses 10k). A line paid 0 (100% coupon) uses the
	 * refunded quantity.
	 *
	 * @param float $refunded_paid Refunded amount on the line (incl. tax).
	 * @param int   $refunded_qty  Refunded quantity.
	 * @param float $line_paid     Line total paid (incl. tax, after coupons).
	 * @param float $line_face     Line subtotal (pre-coupon) = total face value.
	 * @param int   $line_qty      Ordered quantity.
	 * @param int   $decimals      Price decimals.
	 * @return float
	 */
	public static function face_value_refunded( float $refunded_paid, int $refunded_qty, float $line_paid, float $line_face, int $line_qty, int $decimals ): float {
		if ( $line_face <= 0 ) {
			return 0.0;
		}
		if ( $line_paid > Money::EPSILON ) {
			$share = max( 0.0, min( 1.0, $refunded_paid / $line_paid ) );
			return round( $line_face * $share, $decimals );
		}
		if ( $line_qty > 0 && $refunded_qty > 0 ) {
			return round( $line_face * min( 1.0, $refunded_qty / $line_qty ), $decimals );
		}
		return 0.0;
	}

	/**
	 * Take a refunded purchase value off a line's cards, fullest card first.
	 *
	 * Pure function — unit tested.
	 *
	 * @param float $value    Refunded value.
	 * @param array $balances card_id => balance.
	 * @param int   $decimals Price decimals.
	 * @return array { take: card_id => amount, short: float (could not be reclaimed) }
	 */
	public static function reclaim( float $value, array $balances, int $decimals ): array {
		arsort( $balances );
		$left = round( max( 0.0, $value ), $decimals );
		$take = array();
		foreach ( $balances as $card_id => $balance ) {
			if ( $left <= 0 ) {
				break;
			}
			$amount = round( min( (float) $balance, $left ), $decimals );
			if ( $amount > 0 ) {
				$take[ $card_id ] = $amount;
				$left             = round( $left - $amount, $decimals );
			}
		}
		return array(
			'take'  => $take,
			'short' => max( 0.0, $left ),
		);
	}

	// ── Hooks ─────────────────────────────────────────────────────────────

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
		try {
			$this->return_card_payment( $order, $refund );
			$this->reclaim_purchased_cards( $order, $refund );
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Gift card refund handling failed: ' . $e->getMessage(), 'error', array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Order marked Refunded: return whatever the cards still hold on it.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_status_refunded( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// wc_create_refund flips the status (→ here) BEFORE woocommerce_order_refunded,
		// so the refund already exists: pass its id so the ledger row (and the mirror's
		// woo_refund_id) link to it. Manual status changes have no refund → null.
		$refunds   = $order->get_refunds();
		$refund_id = ! empty( $refunds ) && $refunds[0] instanceof \WC_Order_Refund ? (int) $refunds[0]->get_id() : null;
		$changed   = false;
		foreach ( $this->ledger->order_summary( $order->get_id() ) as $card_id => $totals ) {
			if ( $totals['outstanding'] > Money::EPSILON ) {
				$changed = $this->refund_to_card( $order, (int) $card_id, (float) $totals['outstanding'], 'refund:order-' . $order->get_id() . ':' . (int) $card_id, $refund_id ) || $changed;
			}
		}
		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Effect 1: give the card part back.
	 *
	 * @param \WC_Order        $order  Order.
	 * @param \WC_Order_Refund $refund Refund.
	 */
	protected function return_card_payment( \WC_Order $order, \WC_Order_Refund $refund ): void {
		$summary = $this->ledger->order_summary( $order->get_id() );
		$weights = array();
		$caps    = array();
		foreach ( $summary as $card_id => $totals ) {
			$paid = round( $totals['spent'] - $totals['released'], 4 );
			if ( $paid > Money::EPSILON ) {
				$weights[ $card_id ] = $paid;
				$caps[ $card_id ]    = $totals['outstanding'];
			}
		}
		if ( empty( $weights ) ) {
			return;
		}

		$decimals   = wc_get_price_decimals();
		$card_paid  = array_sum( $weights );
		$cash_total = (float) $order->get_total();
		$share      = self::share(
			abs( (float) $refund->get_amount() ),
			$cash_total,
			$cash_total > Money::EPSILON ? 0.0 : $this->refunded_lines_value( $refund ),
			$cash_total > Money::EPSILON ? 0.0 : $cash_total + $card_paid
		);
		$amount     = round( $card_paid * $share, $decimals );
		$split      = self::distribute( $amount, $weights, $caps, $decimals );

		$changed = false;
		foreach ( $split as $card_id => $card_amount ) {
			$changed = $this->refund_to_card( $order, (int) $card_id, (float) $card_amount, 'refund:' . $refund->get_id() . ':' . (int) $card_id, $refund->get_id() ) || $changed;
		}
		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Write one `refund` row.
	 *
	 * @param \WC_Order $order     Order.
	 * @param int       $card_id   Card id.
	 * @param float     $amount    Amount.
	 * @param string    $idem_key  Key.
	 * @param int|null  $refund_id Refund id.
	 * @return bool Whether a row was written.
	 */
	protected function refund_to_card( \WC_Order $order, int $card_id, float $amount, string $idem_key, $refund_id ): bool {
		$result = $this->ledger->move(
			$card_id,
			$amount,
			Card_Ledger::TYPE_REFUND,
			$idem_key,
			array(
				'order_id'  => $order->get_id(),
				'refund_id' => $refund_id,
			)
		);
		if ( null === $result ) {
			return false;
		}
		if ( is_wp_error( $result ) ) {
			\StoreDash_Helpers::log_message( 'Gift card refund failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
			return false;
		}
		if ( empty( $result['created'] ) ) {
			return false;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: last 4 characters of the gift card code */
				__( 'Refunded %1$s to gift card ····%2$s.', 'storedash' ),
				wp_strip_all_tags( wc_price( (float) $result['row']->amount, array( 'currency' => $order->get_currency() ) ) ),
				$result['card']->code_last4
			)
		);
		Gift_Card_Webhook::send( 'refunded', $result['card'], $result['row'] );
		return true;
	}

	/**
	 * Gross value of a refund's lines, excluding gift card fee lines.
	 *
	 * @param \WC_Order_Refund $refund Refund.
	 * @return float
	 */
	protected function refunded_lines_value( \WC_Order_Refund $refund ): float {
		$value = 0.0;
		foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Fee && (int) $item->get_meta( Redemption::FEE_ITEM_META, true ) > 0 ) {
				continue;
			}
			$value += abs( (float) $item->get_total() ) + abs( (float) $item->get_total_tax() );
		}
		return $value;
	}

	/**
	 * Effect 2: refund of a gift card purchase line.
	 *
	 * @param \WC_Order        $order  Order.
	 * @param \WC_Order_Refund $refund Refund.
	 */
	protected function reclaim_purchased_cards( \WC_Order $order, \WC_Order_Refund $refund ): void {
		$decimals  = wc_get_price_decimals();
		$handled   = false;
		$has_cards = false;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( '' !== (string) $item->get_meta( Recipient_Fields::META_CARD_IDS, true ) ) {
				$has_cards = true;
				break;
			}
		}
		if ( ! $has_cards ) {
			return;
		}

		foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
			$original_id = (int) $refund_item->get_meta( '_refunded_item_id', true );
			$original    = $original_id ? $order->get_item( $original_id ) : null;
			if ( ! $original ) {
				continue;
			}
			$card_ids = json_decode( (string) $original->get_meta( Recipient_Fields::META_CARD_IDS, true ), true );
			if ( empty( $card_ids ) || ! is_array( $card_ids ) ) {
				continue;
			}
			$value = self::face_value_refunded(
				abs( (float) $refund_item->get_total() ) + abs( (float) $refund_item->get_total_tax() ),
				abs( (int) $refund_item->get_quantity() ),
				(float) $original->get_total() + (float) $original->get_total_tax(),
				(float) $original->get_subtotal(),
				(int) $original->get_quantity(),
				$decimals
			);
			if ( $value <= 0 ) {
				continue;
			}
			$handled = true;

			$balances = array();
			foreach ( $card_ids as $card_id ) {
				$card = $this->ledger->get_card( (int) $card_id );
				if ( $card ) {
					$balances[ (int) $card_id ] = (float) $card->balance;
				}
			}

			$plan = self::reclaim( $value, $balances, $decimals );
			foreach ( $plan['take'] as $card_id => $amount ) {
				$result = $this->ledger->move(
					(int) $card_id,
					-$amount,
					Card_Ledger::TYPE_ADJUST,
					'refund_purchase:' . $refund->get_id() . ':' . (int) $card_id,
					array(
						'order_id'  => $order->get_id(),
						'refund_id' => $refund->get_id(),
						/* translators: %d: refund id */
						'note'      => sprintf( __( 'Gift card purchase refunded (refund #%d).', 'storedash' ), $refund->get_id() ),
					)
				);
				if ( is_array( $result ) && ! empty( $result['created'] ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: amount, 2: last 4 characters of the gift card code */
							__( 'Removed %1$s from gift card ····%2$s after refund.', 'storedash' ),
							wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ),
							$result['card']->code_last4
						)
					);
					Gift_Card_Webhook::send( 'adjusted', $result['card'], $result['row'] );
				} elseif ( is_wp_error( $result ) ) {
					\StoreDash_Helpers::log_message( 'Gift card purchase refund failed: ' . $result->get_error_message(), 'error', array( 'order_id' => $order->get_id() ) );
				}
			}

			if ( $plan['short'] > Money::EPSILON ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: amount */
						__( 'Gift card refund: %s had already been spent from the refunded gift card(s) and could not be taken back. Please check this refund.', 'storedash' ),
						wp_strip_all_tags( wc_price( $plan['short'], array( 'currency' => $order->get_currency() ) ) )
					)
				);
			}
		}

		if ( $handled ) {
			$order->save();
			return;
		}

		if ( abs( (float) $refund->get_amount() ) > 0 ) {
			$order->add_order_note( __( 'This order contains gift cards, but the refund did not include the gift card lines, so no gift card balance was changed. Adjust the cards in Storedash if needed.', 'storedash' ) );
			$order->save();
		}
	}
}
