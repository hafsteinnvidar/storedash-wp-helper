<?php
/**
 * Spend cap math for the checkout fee.
 *
 * Pure function — unit tested.
 *
 * @package StoreDash\Credit\Engine
 * @since   1.17.0
 */

namespace StoreDash\Credit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Money;

/**
 * Computes how much credit may be applied to a cart.
 *
 * @since 1.17.0
 */
class Fee_Calculator {

	/**
	 * Maximum credit applicable to the cart, before the customer's request.
	 *
	 * @param float $eligible      Eligible cart amount (inc. tax; shipping already added when covered).
	 * @param float $cart_subtotal Cart items total inc. tax (for `min_order_to_spend`).
	 * @param float $balance       Customer balance.
	 * @param array $settings      Normalized settings.
	 * @param int   $decimals      Price decimals.
	 * @return float
	 */
	public static function max_applicable( float $eligible, float $cart_subtotal, float $balance, array $settings, int $decimals ): float {
		if ( $eligible <= 0 || $balance <= 0 ) {
			return 0.0;
		}

		$min = $settings['min_order_to_spend'] ?? null;
		if ( null !== $min && (float) $min > 0 && $cart_subtotal + Money::EPSILON < (float) $min ) {
			return 0.0;
		}

		$cap = $eligible;
		$pct = $settings['max_spend_pct'] ?? null;
		if ( null !== $pct && (float) $pct < 100 ) {
			$cap = $eligible * (float) $pct / 100;
		}

		return Money::round( min( $cap, $balance ), 'down', $decimals );
	}

	/**
	 * Amount to apply given the customer's request.
	 *
	 * @param float      $max_applicable Result of max_applicable().
	 * @param float|null $requested      Requested amount, null = as much as possible.
	 * @param int        $decimals       Price decimals.
	 * @return float
	 */
	public static function applied( float $max_applicable, $requested, int $decimals ): float {
		if ( $max_applicable <= 0 ) {
			return 0.0;
		}
		if ( null === $requested || '' === $requested ) {
			return $max_applicable;
		}
		$requested = (float) $requested;
		if ( $requested <= 0 ) {
			return 0.0;
		}
		return Money::round( min( $requested, $max_applicable ), 'down', $decimals );
	}
}
