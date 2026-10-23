<?php
/**
 * Threshold_Calculator: WC free-shipping arithmetic + {amount} templating.
 *
 * @package StoreDash\Tests\Checkout
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Checkout\FreeShipping\Threshold_Calculator;

require_once __DIR__ . '/checkout-stubs.php';

/**
 * @covers \StoreDash\Checkout\FreeShipping\Threshold_Calculator
 */
class Threshold_CalculatorTest extends TestCase {

	public function test_excl_tax_subtracts_discount_only(): void {
		$r = Threshold_Calculator::compute( 100.0, 80.0, 10.0, 2.4, false, false );
		$this->assertSame( 30.0, $r['remaining'] );
		$this->assertFalse( $r['unlocked'] );
		$this->assertSame( 70.0, $r['subtotal'] );
		$this->assertSame( 70.0, $r['progress'] );
	}

	public function test_incl_tax_also_subtracts_discount_tax(): void {
		$r = Threshold_Calculator::compute( 100.0, 99.2, 10.0, 2.4, true, false );
		// 99.2 - 2.4 - 10 = 86.8
		$this->assertSame( 13.2, $r['remaining'] );
		$this->assertFalse( $r['unlocked'] );
	}

	public function test_ignore_discounts_keeps_coupons_counting(): void {
		$r = Threshold_Calculator::compute( 100.0, 99.2, 10.0, 2.4, true, true );
		// Discount ignored, discount tax still removed like WC core: 99.2 - 2.4 = 96.8
		$this->assertSame( 3.2, $r['remaining'] );

		$r = Threshold_Calculator::compute( 100.0, 100.0, 10.0, 0.0, false, true );
		$this->assertTrue( $r['unlocked'] );
		$this->assertSame( 0.0, $r['remaining'] );
	}

	public function test_unlocked_exactly_at_threshold(): void {
		$r = Threshold_Calculator::compute( 50.0, 50.0, 0.0, 0.0, false, false );
		$this->assertTrue( $r['unlocked'] );
		$this->assertSame( 0.0, $r['remaining'] );
		$this->assertSame( 100.0, $r['progress'] );

		$r = Threshold_Calculator::compute( 50.0, 49.99, 0.0, 0.0, false, false );
		$this->assertFalse( $r['unlocked'] );
		$this->assertSame( 0.01, $r['remaining'] );
	}

	public function test_over_threshold_caps_progress_and_remaining(): void {
		$r = Threshold_Calculator::compute( 50.0, 120.0, 0.0, 0.0, false, false );
		$this->assertTrue( $r['unlocked'] );
		$this->assertSame( 0.0, $r['remaining'] );
		$this->assertSame( 100.0, $r['progress'] );
	}

	public function test_zero_decimal_currency_rounds_at_the_boundary(): void {
		// ISK: 4999.6 rounds to 5000 like WC's NumberUtil::round( $total, 0 ).
		$r = Threshold_Calculator::compute( 5000.0, 4999.6, 0.0, 0.0, false, false, 0 );
		$this->assertTrue( $r['unlocked'] );
		$this->assertSame( 0.0, $r['remaining'] );

		$r = Threshold_Calculator::compute( 5000.0, 4999.4, 0.0, 0.0, false, false, 0 );
		$this->assertFalse( $r['unlocked'] );
		$this->assertSame( 1.0, $r['remaining'] );
	}

	public function test_empty_cart_and_negative_totals_never_go_below_zero(): void {
		$r = Threshold_Calculator::compute( 100.0, 0.0, 0.0, 0.0, true, false );
		$this->assertSame( 100.0, $r['remaining'] );
		$this->assertSame( 0.0, $r['progress'] );

		$r = Threshold_Calculator::compute( 100.0, 5.0, 20.0, 0.0, false, false );
		$this->assertSame( 0.0, $r['subtotal'] );
		$this->assertSame( 100.0, $r['remaining'] );
		$this->assertSame( 0.0, $r['progress'] );
	}

	public function test_zero_threshold_is_always_unlocked_progress_without_flag(): void {
		$r = Threshold_Calculator::compute( 0.0, 10.0, 0.0, 0.0, false, false );
		$this->assertFalse( $r['unlocked'] );
		$this->assertSame( 0.0, $r['remaining'] );
		$this->assertSame( 100.0, $r['progress'] );
	}

	public function test_format_message_replaces_every_token(): void {
		$this->assertSame(
			'Add <b>5</b> more (<b>5</b>)',
			Threshold_Calculator::format_message( 'Add {amount} more ({amount})', '<b>5</b>' )
		);
	}

	public function test_format_message_without_token_returns_template(): void {
		$this->assertSame( 'Almost there!', Threshold_Calculator::format_message( 'Almost there!', '5' ) );
	}

	public function test_format_message_never_interprets_printf_directives(): void {
		$this->assertSame( '100% off %s {amount', Threshold_Calculator::format_message( '100% off %s {amount', '5' ) );
	}
}
