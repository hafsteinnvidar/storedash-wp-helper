<?php
/**
 * Gift card fee cap: covers shipping, stacks after Rewards credit, multiple
 * cards, and survives WooCommerce's negative-fee clamp + tax split.
 *
 * The second half replays WC_Cart_Totals::get_fees_from_cart() (clamp,
 * negative-fee tax split, then the `…_get_fees_from_cart_taxes` filter) and
 * checks the cart total that WooCommerce would compute.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\GiftCard\Engine\Fee_Allocator;
use StoreDash\GiftCard\Engine\Redemption;

require_once __DIR__ . '/gift-card-stubs.php';

/**
 * Cart double exposing the totals WC sets before fees are calculated.
 */
class Gift_Card_Fee_Fake_Cart {
	public $contents_total = 0.0;
	public $contents_tax   = 0.0;
	public $shipping_total = 0.0;
	public $shipping_tax   = 0.0;

	public function get_cart_contents_total() {
		return $this->contents_total;
	}
	public function get_cart_contents_tax() {
		return $this->contents_tax;
	}
	public function get_shipping_total() {
		return $this->shipping_total;
	}
	public function get_shipping_tax() {
		return $this->shipping_tax;
	}
}

/**
 * @covers \StoreDash\GiftCard\Engine\Fee_Allocator
 * @covers \StoreDash\GiftCard\Engine\Redemption::finalize_fee
 */
class Gift_Card_Fee_CapTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__test_price_decimals'] = 0; // ISK.
	}

	protected function tearDown(): void {
		$GLOBALS['__test_price_decimals'] = 2;
	}

	// ── Pure math ─────────────────────────────────────────────────────────

	public function test_15k_order_including_shipping_on_10k_card_leaves_5k_to_pay() {
		$payable = Fee_Allocator::base_payable( 12000, 0, 3000, 0 );
		$this->assertSame( 15000.0, $payable );
		$alloc = Fee_Allocator::allocate( $payable, array( 7 => 10000 ), 0 );
		$this->assertSame( 10000.0, $alloc[7] );
		$this->assertSame( 5000.0, $payable - array_sum( $alloc ) );
	}

	public function test_8k_order_on_10k_card_pays_8k_and_leaves_2k_on_the_card() {
		$alloc = Fee_Allocator::allocate( 8000, array( 7 => 10000 ), 0 );
		$this->assertSame( 8000.0, $alloc[7] );
		$this->assertSame( 2000.0, 10000 - $alloc[7] );
	}

	public function test_two_cards_are_used_in_apply_order() {
		$alloc = Fee_Allocator::allocate( 15000, array( 9 => 5000, 7 => 20000 ), 0 );
		$this->assertSame( array( 9 => 5000.0, 7 => 10000.0 ), $alloc );

		$alloc = Fee_Allocator::allocate( 4000, array( 9 => 5000, 7 => 20000 ), 0 );
		$this->assertSame( array( 9 => 4000.0, 7 => 0.0 ), $alloc );
	}

	public function test_cards_cover_what_rewards_credit_left() {
		// 15k incl. shipping, 3k rewards credit applied first (fee priority 20).
		$payable = Fee_Allocator::base_payable( 12000, 0, 3000, 0 ) - 3000;
		$alloc   = Fee_Allocator::allocate( $payable, array( 7 => 10000 ), 0 );
		$this->assertSame( 10000.0, $alloc[7] );
		$this->assertSame( 2000.0, $payable - $alloc[7] );
	}

	public function test_cap_rounds_to_store_decimals_and_never_overpays() {
		$this->assertSame( 12.34, Fee_Allocator::cap( 12.349, 100, 2 ) );
		$this->assertSame( 99.99, Fee_Allocator::cap( 500, 99.994, 2 ) );
		$this->assertSame( 0.0, Fee_Allocator::cap( 0, 100, 2 ) );
		$this->assertSame( 0.0, Fee_Allocator::cap( 100, 0, 2 ) );
		$this->assertSame( 0.0, Fee_Allocator::cap( 100, -5, 2 ) );
	}

	// ── WC_Cart_Totals replay ─────────────────────────────────────────────

	/**
	 * Replays WC_Cart_Totals::get_fees_from_cart() + calculate_totals() for a
	 * tax-inclusive 24% VAT store (ISK, 0 decimals).
	 *
	 * @param Redemption $redemption Redemption with pending gift card fees.
	 * @param object     $cart       Fake cart.
	 * @param array      $fees       [{id, amount}] in hook order.
	 * @return array { total: float, fees: id => total, fee_taxes: id => tax }
	 */
	private function replay( Redemption $redemption, $cart, array $fees ): array {
		$rate        = 0.24;
		$items_cents = wc_add_number_precision( $cart->contents_total );
		$ship_cents  = wc_add_number_precision( $cart->shipping_total );
		$running     = 0.0;
		$out_fees    = array();
		$out_taxes   = array();

		foreach ( $fees as $fee_props ) {
			$object         = (object) array(
				'id'     => $fee_props['id'],
				'amount' => $fee_props['amount'],
			);
			$fee            = new stdClass();
			$fee->object    = $object;
			$fee->total     = wc_add_number_precision( $object->amount );
			$fee->taxes     = array();
			$max_discount   = round( $items_cents + $running + $ship_cents ) * -1;
			if ( $fee->total < 0 && $fee->total < $max_discount ) {
				$fee->total = $max_discount; // WC clamp (ex-tax).
			}
			$running += $fee->total;
			if ( $fee->total < 0 ) {
				$fee->taxes = array( 1 => $fee->total * $rate ); // WC tax split on negative fees.
			}
			$fee->taxes = $redemption->finalize_fee( $fee->taxes, $fee );

			$out_fees[ $object->id ]  = wc_remove_number_precision( $fee->total );
			$out_taxes[ $object->id ] = wc_remove_number_precision( array_sum( $fee->taxes ) );
		}

		$total = $cart->contents_total + $cart->contents_tax + $cart->shipping_total + $cart->shipping_tax
			+ array_sum( $out_fees ) + array_sum( $out_taxes );

		return array(
			'total'     => max( 0.0, round( $total ) ),
			'fees'      => $out_fees,
			'fee_taxes' => $out_taxes,
		);
	}

	private function redemption_with( $cart, array $pending ): Redemption {
		$redemption = new Redemption( new \StoreDash\GiftCard\Card_Ledger(), new \StoreDash\GiftCard\Engine\Rate_Limiter() );
		\Closure::bind(
			function () use ( $pending, $cart ) {
				$this->pending   = $pending;
				$this->pass_cart = $cart;
				$this->pass_fees = 0.0;
			},
			$redemption,
			Redemption::class
		)();
		return $redemption;
	}

	private function vat_cart(): Gift_Card_Fee_Fake_Cart {
		// 12.000 kr items + 3.000 kr shipping, both incl. 24% VAT.
		$cart                 = new Gift_Card_Fee_Fake_Cart();
		$cart->contents_total = 9677;
		$cart->contents_tax   = 2323;
		$cart->shipping_total = 2419;
		$cart->shipping_tax   = 581;
		return $cart;
	}

	public function test_replay_15k_with_shipping_on_10k_card_totals_5k_and_keeps_vat() {
		$cart   = $this->vat_cart();
		$r      = $this->redemption_with( $cart, array( 'storedash_gift_card_7' => 10000.0 ) );
		$result = $this->replay( $r, $cart, array( array( 'id' => 'storedash_gift_card_7', 'amount' => -10000 ) ) );

		$this->assertSame( 5000.0, $result['total'] );
		$this->assertSame( -10000.0, $result['fees']['storedash_gift_card_7'] );
		$this->assertSame( 0.0, $result['fee_taxes']['storedash_gift_card_7'], 'card fee must not reduce VAT' );
	}

	public function test_replay_card_larger_than_order_covers_the_vat_part_too() {
		// WC alone would clamp the fee at the ex-tax total (12.096) and leave VAT to pay.
		$cart   = $this->vat_cart();
		$r      = $this->redemption_with( $cart, array( 'storedash_gift_card_7' => 20000.0 ) );
		$result = $this->replay( $r, $cart, array( array( 'id' => 'storedash_gift_card_7', 'amount' => -20000 ) ) );

		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( -15000.0, $result['fees']['storedash_gift_card_7'] );
	}

	public function test_replay_after_rewards_credit_with_wc_tax_split() {
		// Rewards credit −2.000 (WC splits −480 VAT onto it) → 12.520 left; card 10k → 2.520 to pay.
		$cart   = $this->vat_cart();
		$r      = $this->redemption_with( $cart, array( 'storedash_gift_card_7' => 10000.0 ) );
		$result = $this->replay(
			$r,
			$cart,
			array(
				array( 'id' => 'storedash_credit', 'amount' => -2000 ),
				array( 'id' => 'storedash_gift_card_7', 'amount' => -10000 ),
			)
		);

		$this->assertSame( 2520.0, $result['total'] );
		$this->assertSame( -480.0, $result['fee_taxes']['storedash_credit'], 'other fees are left alone' );
	}

	public function test_replay_two_cards_second_one_is_capped_by_the_first() {
		$cart   = $this->vat_cart();
		$r      = $this->redemption_with(
			$cart,
			array(
				'storedash_gift_card_9' => 12000.0,
				'storedash_gift_card_7' => 10000.0,
			)
		);
		$result = $this->replay(
			$r,
			$cart,
			array(
				array( 'id' => 'storedash_gift_card_9', 'amount' => -12000 ),
				array( 'id' => 'storedash_gift_card_7', 'amount' => -3000 ),
			)
		);

		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( -12000.0, $result['fees']['storedash_gift_card_9'] );
		$this->assertSame( -3000.0, $result['fees']['storedash_gift_card_7'] );
	}

	public function test_fee_id_parsing() {
		$this->assertSame( 7, Redemption::card_id_from_fee_id( 'storedash_gift_card_7' ) );
		$this->assertSame( 0, Redemption::card_id_from_fee_id( 'storedash_credit' ) );
		$this->assertSame( 0, Redemption::card_id_from_fee_id( 'storedash_gift_card_x' ) );
	}
}
