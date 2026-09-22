<?php
/**
 * Earn basis + amount math.
 *
 * Pure functions — unit tested. Lines are plain arrays describing each order
 * line so the calculator never touches WooCommerce objects.
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
 * Earn math.
 *
 * @since 1.17.0
 */
class Earn_Calculator {

	/**
	 * Eligible basis for an order.
	 *
	 * Basis per `earn_basis`:
	 * - subtotal_ex_tax  → eligible line totals after discounts, excluding tax.
	 * - subtotal_inc_tax → eligible line totals after discounts, including tax.
	 * - total            → subtotal_inc_tax + shipping (inc tax) + positive non-credit fees.
	 * Shipping / fees are never included for the two subtotal bases.
	 *
	 * The part of the order paid with rewards credit is subtracted unless
	 * `earn_on_credit_paid_part` is on.
	 *
	 * @param array[] $lines          Each: { total: float (ex tax, after discounts), tax: float, excluded: bool, on_sale: bool }.
	 * @param array   $settings       Normalized settings.
	 * @param float   $credit_applied Credit spent on this order (positive).
	 * @param float   $extras         Shipping inc. tax + positive fees (only used for `total`).
	 * @return float Never negative.
	 */
	public static function basis( array $lines, array $settings, float $credit_applied = 0.0, float $extras = 0.0 ): float {
		$mode        = $settings['earn_basis'] ?? 'subtotal_ex_tax';
		$on_sale_ok  = ! empty( $settings['earn_on_sale_items'] );
		$include_tax = in_array( $mode, array( 'subtotal_inc_tax', 'total' ), true );
		$basis       = 0.0;

		foreach ( $lines as $line ) {
			if ( ! empty( $line['excluded'] ) ) {
				continue;
			}
			if ( ! $on_sale_ok && ! empty( $line['on_sale'] ) ) {
				continue;
			}
			$basis += (float) ( $line['total'] ?? 0 );
			if ( $include_tax ) {
				$basis += (float) ( $line['tax'] ?? 0 );
			}
		}

		if ( 'total' === $mode ) {
			$basis += max( 0.0, $extras );
		}

		if ( empty( $settings['earn_on_credit_paid_part'] ) && $credit_applied > 0 ) {
			$basis -= $credit_applied;
		}

		return max( 0.0, round( $basis, 4 ) );
	}

	/**
	 * Credit amount for a rule on a basis, rounded per settings.
	 *
	 * @param array $rule     Rule ({kind, value}).
	 * @param float $basis    Basis.
	 * @param array $settings Normalized settings.
	 * @param int   $decimals Price decimals.
	 * @return float
	 */
	public static function amount( array $rule, float $basis, array $settings, int $decimals ): float {
		$value = (float) ( $rule['value'] ?? 0 );
		if ( $value <= 0 ) {
			return 0.0;
		}

		if ( 'fixed' === ( $rule['kind'] ?? 'percent' ) ) {
			$raw = $basis > 0 ? $value : 0.0;
		} else {
			$raw = $basis * $value / 100;
		}

		return Money::round( $raw, (string) ( $settings['rounding'] ?? 'down' ), $decimals );
	}

	/**
	 * Expiry datetime for an earn row.
	 *
	 * @param array $rule     Rule (expiry_days overrides settings).
	 * @param array $settings Normalized settings.
	 * @param int   $now      Unix timestamp.
	 * @return string|null UTC MySQL datetime or null (never expires).
	 */
	public static function expires_at( array $rule, array $settings, int $now ) {
		$days = isset( $rule['expiry_days'] ) && null !== $rule['expiry_days'] ? (int) $rule['expiry_days'] : ( $settings['expiry_days'] ?? null );
		if ( null === $days || (int) $days <= 0 ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $now + ( (int) $days * 86400 ) );
	}
}
