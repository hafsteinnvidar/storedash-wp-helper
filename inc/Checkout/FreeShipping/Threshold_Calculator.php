<?php
/**
 * Free-shipping threshold calculator (pure logic).
 *
 * Mirrors `WC_Shipping_Free_Shipping::is_available()` so the bar never
 * promises free shipping the gateway would not grant:
 *
 *   total = displayed subtotal
 *   if prices shown incl. tax:  total -= discount tax
 *   unless ignore_discounts:    total -= discount total
 *   total = max( 0, round( total, decimals ) )
 *   remaining = max( 0, threshold - total )
 *
 * `include_tax` resolution (`woo` = follow `woocommerce_tax_display_cart`,
 * `incl` / `excl` force) happens in the caller; this class takes the
 * resolved boolean and the matching displayed subtotal.
 *
 * No WordPress calls: unit-tested standalone.
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout\FreeShipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Threshold calculator.
 *
 * @since 1.29.0
 */
class Threshold_Calculator {

	/**
	 * Amount token in merchant copy.
	 */
	const TOKEN = '{amount}';

	/**
	 * Remaining amount + unlocked flag.
	 *
	 * @param float $threshold          Free-shipping minimum (major units).
	 * @param float $displayed_subtotal Cart subtotal as displayed (incl. or excl. tax per `$prices_incl_tax`).
	 * @param float $discount_total     Coupon discount (excl. tax).
	 * @param float $discount_tax_total Tax part of the coupon discount.
	 * @param bool  $prices_incl_tax    Subtotal is shown including tax.
	 * @param bool  $ignore_discounts   Count coupons towards the threshold (WC "ignore discounts").
	 * @param int   $decimals           Currency decimals (rounding, like WC).
	 * @return array { remaining: float, unlocked: bool, subtotal: float, progress: float }
	 */
	public static function compute( float $threshold, float $displayed_subtotal, float $discount_total, float $discount_tax_total, bool $prices_incl_tax, bool $ignore_discounts, int $decimals = 2 ): array {
		$total = $displayed_subtotal;
		if ( $prices_incl_tax ) {
			$total -= $discount_tax_total;
		}
		if ( ! $ignore_discounts ) {
			$total -= $discount_total;
		}
		$decimals  = max( 0, $decimals );
		$total     = max( 0.0, round( $total, $decimals ) );
		$threshold = round( $threshold, $decimals );
		$remaining = max( 0.0, round( $threshold - $total, $decimals ) );
		$unlocked  = $threshold > 0 && $total >= $threshold;
		$progress  = $threshold > 0 ? min( 100.0, max( 0.0, $total / $threshold * 100 ) ) : 100.0;

		return array(
			'remaining' => $remaining,
			'unlocked'  => $unlocked,
			'subtotal'  => $total,
			'progress'  => $progress,
		);
	}

	/**
	 * Replace every `{amount}` in merchant copy. Plain `str_replace` — the
	 * template is merchant text and must never reach sprintf.
	 *
	 * @param string $template    Copy (already escaped by the caller).
	 * @param string $amount_html Formatted amount (trusted markup).
	 * @return string
	 */
	public static function format_message( string $template, string $amount_html ): string {
		return str_replace( self::TOKEN, $amount_html, $template );
	}
}
