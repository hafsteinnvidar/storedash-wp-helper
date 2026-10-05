<?php
/**
 * Gift card fee cap math.
 *
 * Pure functions — unit tested. A card covers min(balance, what is still
 * payable), where "payable" is the gross cart total INCLUDING shipping and
 * after every fee computed before it (Rewards credit at priority 20, earlier
 * cards). Cards are used in the order they were applied.
 *
 * @package StoreDash\GiftCard\Engine
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fee math.
 *
 * @since 1.24.0
 */
class Fee_Allocator {

	/**
	 * Amount one card covers.
	 *
	 * @param float $balance  Card balance.
	 * @param float $payable  Still payable (gross, incl. shipping, after earlier fees).
	 * @param int   $decimals Price decimals.
	 * @return float Positive amount, rounded to the store's decimals, never above either input.
	 */
	public static function cap( float $balance, float $payable, int $decimals ): float {
		if ( $balance <= 0 || $payable <= 0 ) {
			return 0.0;
		}
		$payable = round( $payable, $decimals );
		$balance = self::floor_to( $balance, $decimals );
		return max( 0.0, min( $balance, $payable ) );
	}

	/**
	 * Split a payable amount across cards in apply order.
	 *
	 * @param float $payable  Payable before the first card.
	 * @param array $balances card_id => balance, in apply order.
	 * @param int   $decimals Price decimals.
	 * @return array card_id => applied (cards that cover nothing map to 0.0).
	 */
	public static function allocate( float $payable, array $balances, int $decimals ): array {
		$out  = array();
		$left = round( max( 0.0, $payable ), $decimals );
		foreach ( $balances as $card_id => $balance ) {
			$applied         = self::cap( (float) $balance, $left, $decimals );
			$out[ $card_id ] = $applied;
			$left            = round( $left - $applied, $decimals );
		}
		return $out;
	}

	/**
	 * Floor to the store's decimals (a card never pays more than it holds).
	 *
	 * @param float $amount   Amount.
	 * @param int   $decimals Decimals.
	 * @return float
	 */
	protected static function floor_to( float $amount, int $decimals ): float {
		$factor = pow( 10, max( 0, $decimals ) );
		return floor( $amount * $factor + 1e-6 ) / $factor;
	}
}
