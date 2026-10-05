<?php
/**
 * Contract-C `GiftCard` / `LedgerRow` shapes.
 *
 * Pure functions — unit tested. Money is a decimal string ("10000.00"),
 * datetimes ISO-8601 Z, ids ints, store_id a string. Never includes the code.
 *
 * @package StoreDash\GiftCard
 * @since   1.24.0
 */

namespace StoreDash\GiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Money;

/**
 * Serializers.
 *
 * @since 1.24.0
 */
class Serializer {

	/**
	 * Card row → GiftCard.
	 *
	 * @param object $card     Card DB row.
	 * @param string $store_id Storedash store id.
	 * @param int    $decimals Price decimals.
	 * @return array
	 */
	public static function card( $card, string $store_id, int $decimals ): array {
		return array(
			'id'              => (int) $card->id,
			'store_id'        => $store_id,
			'code_last4'      => (string) $card->code_last4,
			'initial_amount'  => Money::to_decimal_string( $card->initial_amount, $decimals ),
			'balance'         => Money::to_decimal_string( $card->balance, $decimals ),
			'currency'        => (string) $card->currency,
			'status'          => (string) $card->status,
			'source'          => (string) $card->source,
			'order_id'        => self::int_or_null( $card->order_id ?? null ),
			'order_item_id'   => self::int_or_null( $card->order_item_id ?? null ),
			'product_id'      => self::int_or_null( $card->product_id ?? null ),
			'purchaser_email' => self::str_or_null( $card->purchaser_email ?? null ),
			'recipient_email' => self::str_or_null( $card->recipient_email ?? null ),
			'recipient_name'  => self::str_or_null( $card->recipient_name ?? null ),
			'sender_name'     => self::str_or_null( $card->sender_name ?? null ),
			'message'         => self::str_or_null( $card->message ?? null ),
			'send_at'         => Money::to_iso( $card->send_at ?? null ),
			'sent_at'         => Money::to_iso( $card->sent_at ?? null ),
			'note'            => self::str_or_null( $card->note ?? null ),
			'created_at'      => Money::to_iso( $card->created_at ?? null ),
			'updated_at'      => Money::to_iso( $card->updated_at ?? null ),
		);
	}

	/**
	 * Ledger row → LedgerRow.
	 *
	 * @param object $row      Ledger DB row.
	 * @param int    $decimals Price decimals.
	 * @return array
	 */
	public static function row( $row, int $decimals ): array {
		return array(
			'id'            => (int) $row->id,
			'card_id'       => (int) $row->card_id,
			'type'          => (string) $row->type,
			'amount'        => Money::to_decimal_string( $row->amount, $decimals ),
			'balance_after' => Money::to_decimal_string( $row->balance_after, $decimals ),
			'currency'      => (string) $row->currency,
			'order_id'      => self::int_or_null( $row->order_id ?? null ),
			'refund_id'     => self::int_or_null( $row->refund_id ?? null ),
			'note'          => self::str_or_null( $row->note ?? null ),
			'created_at'    => Money::to_iso( $row->created_at ?? null ),
		);
	}

	/**
	 * Positive int or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	protected static function int_or_null( $value ) {
		return ( null === $value || '' === $value || (int) $value <= 0 ) ? null : (int) $value;
	}

	/**
	 * Non-empty string or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	protected static function str_or_null( $value ) {
		return ( null === $value || '' === $value ) ? null : (string) $value;
	}
}
