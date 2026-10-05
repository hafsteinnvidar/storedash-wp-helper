<?php
/**
 * Mint gift cards for paid orders (contract A / F).
 *
 * On `woocommerce_order_status_{processing|completed}` and
 * `woocommerce_payment_complete`, every gift card line gets one card per
 * unit, worth line subtotal ÷ quantity (pre-coupon), idempotent by
 * `issue:{order_item_id}:{unit_index}`. Never on pending / on-hold (bank
 * transfer not yet paid). One `giftcard.issued` webhook per card, carrying the
 * code for delivery to the recipient (or the buyer).
 *
 * @package StoreDash\GiftCard\Engine
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Code;
use StoreDash\GiftCard\Gift_Card_Webhook;
use StoreDash\GiftCard\Product\Gift_Card_Product;
use StoreDash\GiftCard\Product\Recipient_Fields;
use StoreDash\GiftCard\Settings;

/**
 * Issue flow.
 *
 * @since 1.24.0
 */
class Issuer {

	/**
	 * Order meta flagging that the "gift cards disabled" note was added.
	 */
	const META_DISABLED_NOTE = '_storedash_gift_card_issue_skipped';

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
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_status' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_status' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'on_status' ), 20, 1 );
	}

	/**
	 * Whether an order in this status should mint.
	 *
	 * Pure function — unit tested. `completed` always qualifies (an order can
	 * skip processing); `processing` only when the store issues on processing.
	 *
	 * @param string $status  Order status (no wc- prefix).
	 * @param string $setting issue_on_status setting.
	 * @return bool
	 */
	public static function should_issue( string $status, string $setting ): bool {
		if ( 'completed' === $status ) {
			return true;
		}
		return 'processing' === $status && 'processing' === $setting;
	}

	/**
	 * Unit value of a gift card line.
	 *
	 * Pure function — unit tested.
	 *
	 * @param float $line_subtotal Line subtotal (pre-coupon).
	 * @param int   $quantity      Ordered quantity.
	 * @param int   $decimals      Price decimals.
	 * @return float
	 */
	public static function unit_value( float $line_subtotal, int $quantity, int $decimals ): float {
		if ( $quantity <= 0 || $line_subtotal <= 0 ) {
			return 0.0;
		}
		return round( $line_subtotal / $quantity, $decimals );
	}

	/**
	 * Status / payment hook.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_status( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) {
			return;
		}
		try {
			$this->maybe_issue( $order );
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Gift card issue failed: ' . $e->getMessage(), 'error', array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Mint every not-yet-issued card of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return int Cards created in this call.
	 */
	public function maybe_issue( \WC_Order $order ): int {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && Gift_Card_Product::is_gift_card_id( (int) $item->get_product_id() ) ) {
				$lines[] = $item;
			}
		}
		if ( empty( $lines ) ) {
			return 0;
		}

		$settings = Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			if ( '1' !== (string) $order->get_meta( self::META_DISABLED_NOTE, true ) ) {
				$order->update_meta_data( self::META_DISABLED_NOTE, '1' );
				$order->add_order_note( __( 'Gift cards were not issued because gift cards are turned off in Storedash. Issue them by hand from Storedash if needed.', 'storedash' ) );
				$order->save();
			}
			return 0;
		}
		if ( ! self::should_issue( (string) $order->get_status(), (string) $settings['issue_on_status'] ) ) {
			return 0;
		}

		Code::check_key_fingerprint();

		$decimals = wc_get_price_decimals();
		$tz       = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$created  = 0;

		foreach ( $lines as $item ) {
			$ordered  = (int) $item->get_quantity();
			$refunded = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
			$units    = max( 0, $ordered - $refunded );
			$value    = self::unit_value( (float) $item->get_subtotal(), $ordered, $decimals );
			if ( $units <= 0 || $value <= 0 ) {
				continue;
			}

			$recipient = Recipient_Fields::from_order_item( $item );
			$card_ids  = $this->card_ids_on_item( $item );
			$new_cards = array();

			for ( $unit = 1; $unit <= $units; $unit++ ) {
				$result = $this->ledger->issue(
					array(
						'initial_amount'  => $value,
						'currency'        => $order->get_currency(),
						'source'          => Card_Ledger::SOURCE_PURCHASE,
						'order_id'        => $order->get_id(),
						'order_item_id'   => $item->get_id(),
						'unit_index'      => $unit,
						'product_id'      => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
						'purchaser_email' => $order->get_billing_email(),
						'recipient_email' => $recipient['recipient_email'] ?? null,
						'recipient_name'  => $recipient['recipient_name'] ?? null,
						'sender_name'     => $recipient['sender_name'] ?? null,
						'message'         => $recipient['message'] ?? null,
						'send_at'         => isset( $recipient['send_at'] ) ? Recipient_Fields::send_at_utc( $recipient['send_at'], $tz ) : null,
					),
					'issue:' . $item->get_id() . ':' . $unit
				);

				if ( is_wp_error( $result ) ) {
					\StoreDash_Helpers::log_message( 'Gift card issue failed: ' . $result->get_error_message(), 'error', array( 'order_item_id' => $item->get_id() ) );
					continue;
				}

				$card_ids[] = (int) $result['card']->id;
				if ( ! empty( $result['created'] ) ) {
					$new_cards[] = $result;
				}
			}

			$card_ids = array_values( array_unique( array_map( 'intval', $card_ids ) ) );
			$item->update_meta_data( Recipient_Fields::META_CARD_IDS, wp_json_encode( $card_ids ) );
			$item->save();

			foreach ( $new_cards as $result ) {
				++$created;
				$order->add_order_note(
					sprintf(
						/* translators: 1: last 4 characters of the code, 2: amount */
						__( 'Issued gift card ····%1$s (%2$s).', 'storedash' ),
						$result['card']->code_last4,
						wp_strip_all_tags( wc_price( (float) $result['card']->initial_amount, array( 'currency' => $order->get_currency() ) ) )
					)
				);
				Gift_Card_Webhook::send( 'issued', $result['card'], $result['row'], self::delivery( $result['card'], $result['code'], 'issued' ) );
			}
		}

		return $created;
	}

	/**
	 * Delivery block for a card (null when there is nobody to send to).
	 *
	 * @param object      $card     Card row.
	 * @param string      $code     Normalized code ('' = decrypt the stored copy).
	 * @param string      $reason   issued|resend.
	 * @param string|null $to_email Override recipient.
	 * @return array|null
	 */
	public static function delivery( $card, string $code, string $reason, $to_email = null ) {
		$to = $to_email ? $to_email : ( ! empty( $card->recipient_email ) ? $card->recipient_email : ( $card->purchaser_email ?? '' ) );
		$to = is_string( $to ) ? trim( $to ) : '';
		if ( '' === $to ) {
			return null;
		}
		if ( '' === $code && ! empty( $card->code_enc ) ) {
			$code = Code::decrypt( (string) $card->code_enc );
		}
		if ( '' === $code ) {
			return null;
		}
		return array(
			'code'     => $code,
			'to_email' => $to,
			'reason'   => $reason,
		);
	}

	/**
	 * Card ids already recorded on an order line.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @return int[]
	 */
	protected function card_ids_on_item( $item ): array {
		$ids = json_decode( (string) $item->get_meta( Recipient_Fields::META_CARD_IDS, true ), true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
