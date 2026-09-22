<?php
/**
 * Money helpers for rewards credit.
 *
 * Pure functions — unit tested. Rounding follows the merchant's `rounding`
 * setting; ledger rows are serialized as decimal strings (contract B) and
 * Store API figures as minor-unit integer strings (contract C).
 *
 * @package StoreDash\Credit
 * @since   1.17.0
 */

namespace StoreDash\Credit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rounding + serialization helpers.
 *
 * @since 1.17.0
 */
class Money {

	/**
	 * Tolerance for float comparisons (a quarter of the smallest 4-decimal unit).
	 */
	const EPSILON = 0.000025;

	/**
	 * Round an amount per the merchant's rounding mode.
	 *
	 * @param float  $amount   Amount.
	 * @param string $rounding 'down' or 'nearest'.
	 * @param int    $decimals Price decimals.
	 * @return float Never negative.
	 */
	public static function round( float $amount, string $rounding, int $decimals ): float {
		if ( $amount <= 0 ) {
			return 0.0;
		}
		$factor = pow( 10, max( 0, $decimals ) );
		if ( 'nearest' === $rounding ) {
			return round( $amount, $decimals );
		}
		// floor() with a tiny epsilon so 12.30 * 100 = 1229.9999… still floors to 1230.
		return floor( $amount * $factor + 1e-9 ) / $factor;
	}

	/**
	 * Decimal string for ledger rows / webhooks, e.g. "1250.00".
	 *
	 * @param float|string|null $amount   Amount.
	 * @param int               $decimals Price decimals (minimum 2 is used).
	 * @return string|null
	 */
	public static function to_decimal_string( $amount, int $decimals = 2 ) {
		if ( null === $amount || '' === $amount ) {
			return null;
		}
		return number_format( (float) $amount, max( 2, $decimals ), '.', '' );
	}

	/**
	 * Minor-unit integer string for the Store API, e.g. 12.50 → "1250".
	 *
	 * @param float|string $amount   Amount in major units.
	 * @param int          $decimals Currency minor unit (wc_get_price_decimals()).
	 * @return string
	 */
	public static function to_minor( $amount, int $decimals ): string {
		return (string) (int) round( (float) $amount * pow( 10, max( 0, $decimals ) ) );
	}

	/**
	 * Major-unit float from a Store API minor-unit string.
	 *
	 * @param string|int|float $minor    Minor-unit amount.
	 * @param int              $decimals Currency minor unit.
	 * @return float
	 */
	public static function from_minor( $minor, int $decimals ): float {
		return (float) $minor / pow( 10, max( 0, $decimals ) );
	}

	/**
	 * a >= b within tolerance.
	 *
	 * @param float $a Left.
	 * @param float $b Right.
	 * @return bool
	 */
	public static function gte( float $a, float $b ): bool {
		return $a + self::EPSILON >= $b;
	}

	/**
	 * Serialize a ledger row to the contract-B `LedgerRow` shape.
	 *
	 * Pure function — unit tested.
	 *
	 * @param object $row      Ledger row (DB object).
	 * @param string $store_id Storedash store id.
	 * @param int    $decimals Price decimals.
	 * @return array
	 */
	public static function format_row( $row, string $store_id, int $decimals ): array {
		return array(
			'id'             => (int) $row->id,
			'store_id'       => $store_id,
			'customer_email' => (string) $row->customer_key,
			'user_id'        => ! empty( $row->user_id ) ? (int) $row->user_id : null,
			'type'           => (string) $row->type,
			'amount'         => self::to_decimal_string( $row->amount, $decimals ),
			'remaining'      => ( null === $row->remaining || '' === $row->remaining ) ? null : self::to_decimal_string( $row->remaining, $decimals ),
			'currency'       => (string) $row->currency,
			'order_id'       => ! empty( $row->order_id ) ? (int) $row->order_id : null,
			'refund_id'      => ! empty( $row->refund_id ) ? (int) $row->refund_id : null,
			'rule_id'        => ! empty( $row->rule_id ) ? (string) $row->rule_id : null,
			'note'           => ( isset( $row->note ) && '' !== $row->note ) ? (string) $row->note : null,
			'expires_at'     => self::to_iso( $row->expires_at ?? null ),
			'created_at'     => self::to_iso( $row->created_at ?? null ),
		);
	}

	/**
	 * MySQL UTC datetime → ISO-8601 Z string (null passthrough).
	 *
	 * @param string|null $mysql_utc "Y-m-d H:i:s" in UTC.
	 * @return string|null
	 */
	public static function to_iso( $mysql_utc ) {
		if ( empty( $mysql_utc ) || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		return false === $ts ? null : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
