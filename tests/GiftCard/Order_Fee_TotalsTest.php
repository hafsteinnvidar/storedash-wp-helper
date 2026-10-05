<?php
/**
 * Order side of payment fees: WC_Order::calculate_totals() must not clamp our
 * negative fee lines ex-tax, nor split VAT onto them (live bug: order #180).
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Payment_Fee_Pass;

require_once __DIR__ . '/gift-card-stubs.php';

/**
 * Minimal order item double.
 */
class Payment_Fee_Test_Item {
	public $meta      = array();
	public $total     = 0.0;
	public $total_tax = 0.0;
	public $legacy_fee_key;

	public function __construct( $total, $total_tax = 0.0, $meta = array() ) {
		$this->total     = (float) $total;
		$this->total_tax = (float) $total_tax;
		$this->meta      = $meta;
	}
	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
	public function get_total() {
		return $this->total;
	}
	public function set_total( $total ) {
		$this->total = (float) $total;
	}
	public function get_total_tax() {
		return $this->total_tax;
	}
	public function set_taxes( $taxes ) {
		$this->total_tax = 0.0;
	}
}

/**
 * Minimal order double.
 */
class Payment_Fee_Test_Order {
	public $lines        = array();
	public $fees         = array();
	public $shipping     = 0.0;
	public $shipping_tax = 0.0;
	public $total        = 0.0;
	public $taxes_updated = 0;

	public function get_items( $type ) {
		return $this->lines;
	}
	public function get_fees() {
		return $this->fees;
	}
	public function get_shipping_total() {
		return $this->shipping;
	}
	public function get_shipping_tax() {
		return $this->shipping_tax;
	}
	public function set_total( $total ) {
		$this->total = $total;
	}
	public function update_taxes() {
		++$this->taxes_updated;
	}
}

/**
 * @covers \StoreDash\Credit\Payment_Fee_Pass
 */
class Order_Fee_TotalsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__test_price_decimals'] = 0;
	}

	protected function tearDown(): void {
		$GLOBALS['__test_price_decimals'] = 2;
	}

	/** Serum 12.000 + shipping 3.100, both incl. 24% VAT (live repro #180). */
	private function order(): Payment_Fee_Test_Order {
		$order               = new Payment_Fee_Test_Order();
		$order->lines        = array( new Payment_Fee_Test_Item( 9677, 2323 ) );
		$order->shipping     = 2500;
		$order->shipping_tax = 600;
		return $order;
	}

	/**
	 * Replays WC_Order::calculate_totals(true) with our hooks.
	 */
	private function recalc( Payment_Fee_Pass $pass, Payment_Fee_Test_Order $order ): void {
		$pass->order_before_totals( true, $order );

		// WC clamp: cart_total (ex tax) + earlier fees + shipping (ex tax).
		$cart_total = 0.0;
		foreach ( $order->lines as $line ) {
			$cart_total += $line->total;
		}
		$fees_total = 0.0;
		foreach ( $order->fees as $fee ) {
			if ( $fee->total < 0 ) {
				$max = round( $cart_total + $fees_total + $order->shipping ) * -1;
				if ( $fee->total < $max && $max < 0 ) {
					$fee->total = $max;
				}
			}
			$fees_total += $fee->total;
		}

		// WC_Order_Item_Fee::calculate_taxes(): split 24% onto negative fees, then the action.
		foreach ( $order->fees as $fee ) {
			if ( $fee->total < 0 ) {
				$fee->total_tax = round( $fee->total * 0.24 );
			}
			$pass->order_fee_untaxed( $fee );
		}

		$tax = $order->shipping_tax;
		foreach ( array_merge( $order->lines, $order->fees ) as $item ) {
			$tax += $item->total_tax;
		}
		$order->total = round( $cart_total + $fees_total + $order->shipping + $tax );

		$pass->order_after_totals( true, $order );
	}

	private function order_vat( Payment_Fee_Test_Order $order ): float {
		$tax = $order->shipping_tax;
		foreach ( array_merge( $order->lines, $order->fees ) as $item ) {
			$tax += $item->total_tax;
		}
		return $tax;
	}

	public function test_live_repro_10k_card_order_totals_5100_and_keeps_vat() {
		$order       = $this->order();
		$order->fees = array( new Payment_Fee_Test_Item( -10000, 0, array( '_storedash_gift_card_id' => '7' ) ) );
		$this->recalc( new Payment_Fee_Pass(), $order );

		$this->assertSame( 5100.0, $order->total );
		$this->assertSame( 2923.0, $this->order_vat( $order ) );
		$this->assertSame( -10000.0, $order->fees[0]->total );
		$this->assertSame( 0.0, $order->fees[0]->total_tax );
	}

	public function test_card_larger_than_ex_tax_total_is_not_clamped() {
		$order       = $this->order();
		$order->fees = array( new Payment_Fee_Test_Item( -15100, 0, array( '_storedash_gift_card_id' => '7' ) ) );
		$this->recalc( new Payment_Fee_Pass(), $order );

		$this->assertSame( 0.0, $order->total );
		$this->assertSame( -15100.0, $order->fees[0]->total, 'ledger amount == fee line' );
		$this->assertSame( 2923.0, $this->order_vat( $order ) );
	}

	public function test_credit_then_card() {
		$order       = $this->order();
		$order->fees = array(
			new Payment_Fee_Test_Item( -1000, 0, array( '_storedash_credit_fee' => '1' ) ),
			new Payment_Fee_Test_Item( -10000, 0, array( '_storedash_gift_card_id' => '7' ) ),
		);
		$this->recalc( new Payment_Fee_Pass(), $order );

		$this->assertSame( 4100.0, $order->total );
		$this->assertSame( -1000.0, $order->fees[0]->total );
		$this->assertSame( 2923.0, $this->order_vat( $order ) );
	}

	public function test_other_fees_keep_wc_behaviour() {
		$order       = $this->order();
		$order->fees = array( new Payment_Fee_Test_Item( -500, 0, array() ) );
		$this->recalc( new Payment_Fee_Pass(), $order );

		$this->assertSame( -120.0, $order->fees[0]->total_tax );
	}

	public function test_fees_shrink_from_the_last_when_items_were_removed() {
		$result = Payment_Fee_Pass::order_totals( 5000, 0, 0, array( 'credit' => -2000, 'card' => -10000 ), 0 );
		$this->assertSame( array( 'credit' => -2000.0, 'card' => -3000.0 ), $result['fees'] );
		$this->assertSame( 0.0, $result['total'] );
	}

	public function test_identifies_fee_lines_by_meta_or_cart_key() {
		$this->assertTrue( Payment_Fee_Pass::is_payment_fee_item( new Payment_Fee_Test_Item( -1, 0, array( '_storedash_credit_fee' => '1' ) ) ) );
		$this->assertTrue( Payment_Fee_Pass::is_payment_fee_item( new Payment_Fee_Test_Item( -1, 0, array( '_storedash_gift_card_id' => '7' ) ) ) );
		$legacy                 = new Payment_Fee_Test_Item( -1 );
		$legacy->legacy_fee_key = 'storedash_gift_card_9';
		$this->assertTrue( Payment_Fee_Pass::is_payment_fee_item( $legacy ) );
		$this->assertFalse( Payment_Fee_Pass::is_payment_fee_item( new Payment_Fee_Test_Item( -1 ) ) );
	}
}
