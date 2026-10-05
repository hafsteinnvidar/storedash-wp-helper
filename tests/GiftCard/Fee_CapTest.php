<?php
/**
 * Payment fees (Rewards credit + gift cards): exact amounts, no VAT split,
 * shipping covered, credit (priority 20) then gift cards (priority 30).
 *
 * The replay half re-runs WC_Cart_Totals::get_fees_from_cart() (ex-tax clamp,
 * negative-fee tax split, then the `…_get_fees_from_cart_taxes` filter) and
 * checks the cart total WooCommerce would compute. Store: ISK (0 decimals),
 * prices incl. 24% VAT.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Engine\Fee_Calculator;
use StoreDash\Credit\Money;
use StoreDash\Credit\Payment_Fee_Pass;
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
 * @covers \StoreDash\Credit\Payment_Fee_Pass
 * @covers \StoreDash\GiftCard\Engine\Fee_Allocator
 */
class Gift_Card_Fee_CapTest extends TestCase {

	const VAT = 0.24;

	protected function setUp(): void {
		$GLOBALS['__test_price_decimals'] = 0; // ISK.
	}

	protected function tearDown(): void {
		$GLOBALS['__test_price_decimals'] = 2;
	}

	// ── Pure math ─────────────────────────────────────────────────────────

	public function test_15k_order_including_shipping_on_10k_card_leaves_5k_to_pay() {
		$payable = Payment_Fee_Pass::base_payable( 12000, 0, 3000, 0 );
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
		$this->assertSame( array( 9 => 5000.0, 7 => 10000.0 ), Fee_Allocator::allocate( 15000, array( 9 => 5000, 7 => 20000 ), 0 ) );
		$this->assertSame( array( 9 => 4000.0, 7 => 0.0 ), Fee_Allocator::allocate( 4000, array( 9 => 5000, 7 => 20000 ), 0 ) );
	}

	public function test_cards_cover_what_rewards_credit_left() {
		$payable = Payment_Fee_Pass::base_payable( 12000, 0, 3000, 0 ) - 3000;
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

	public function test_credit_spend_covers_shipping_only_when_enabled() {
		$settings = array(
			'max_spend_pct'      => null,
			'min_order_to_spend' => null,
		);
		$items    = 12000.0;                                         // Eligible lines incl. VAT.
		$shipping = Payment_Fee_Pass::shipping_gross( $this->vat_cart() ); // 3.000 incl. VAT, known in the fee hook.
		$this->assertSame( 3000.0, $shipping );

		$this->assertSame( 12000.0, Fee_Calculator::max_applicable( $items, $items, 20000, $settings, 0 ) );
		$this->assertSame( 15000.0, Fee_Calculator::max_applicable( $items + $shipping, $items, 20000, $settings, 0 ) );

		$settings['max_spend_pct'] = 50;
		$this->assertSame( 7500.0, Fee_Calculator::max_applicable( $items + $shipping, $items, 20000, $settings, 0 ) );

		$settings['min_order_to_spend'] = 13000;
		$this->assertSame( 0.0, Fee_Calculator::max_applicable( $items + $shipping, $items, 20000, $settings, 0 ) );
	}

	// ── WC_Cart_Totals replay ─────────────────────────────────────────────

	/**
	 * Replays WC_Cart_Totals::get_fees_from_cart() + calculate_totals().
	 *
	 * @param object $cart Fake cart.
	 * @param array  $fees [{id, amount, cap?}] in hook order; `cap` = claim callback.
	 * @return array { total, fees: id => total, fee_taxes: id => tax, vat }
	 */
	private function replay( $cart, array $fees ): array {
		$pass = new Payment_Fee_Pass();
		$pass->begin( $cart );
		foreach ( $fees as $fee_props ) {
			if ( isset( $fee_props['cap'] ) ) {
				$pass->claim( $fee_props['id'], $fee_props['cap'] );
			}
		}

		$items_cents = wc_add_number_precision( $cart->contents_total );
		$ship_cents  = wc_add_number_precision( $cart->shipping_total );
		$running     = 0.0;
		$out_fees    = array();
		$out_taxes   = array();

		foreach ( $fees as $fee_props ) {
			$object       = (object) array(
				'id'     => $fee_props['id'],
				'amount' => $fee_props['amount'],
			);
			$fee          = new stdClass();
			$fee->object  = $object;
			$fee->total   = wc_add_number_precision( $object->amount );
			$fee->taxes   = array();
			$max_discount = round( $items_cents + $running + $ship_cents ) * -1;
			if ( $fee->total < 0 && $fee->total < $max_discount ) {
				$fee->total = $max_discount; // WC clamp (ex-tax).
			}
			$running += $fee->total;
			if ( $fee->total < 0 ) {
				$fee->taxes = array( 1 => $fee->total * self::VAT ); // WC tax split on negative fees.
			}
			$fee->taxes = $pass->finalize( $fee->taxes, $fee );

			$out_fees[ $object->id ]  = wc_remove_number_precision( $fee->total );
			$out_taxes[ $object->id ] = wc_remove_number_precision( array_sum( $fee->taxes ) );
		}

		$vat   = $cart->contents_tax + $cart->shipping_tax + array_sum( $out_taxes );
		$total = $cart->contents_total + $cart->shipping_total + array_sum( $out_fees ) + $vat;

		return array(
			'total'     => max( 0.0, round( $total ) ),
			'fees'      => $out_fees,
			'fee_taxes' => $out_taxes,
			'vat'       => round( $vat ),
		);
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

	/** Claim used by Spend_Handler::apply_fee (credit already capped by Fee_Calculator). */
	private function credit_cap( float $applied ): callable {
		return static function ( float $payable ) use ( $applied ): float {
			return Money::round( min( $applied, $payable ), 'down', 0 );
		};
	}

	/** Claim used by Redemption::apply_fees. */
	private function card_cap( float $balance ): callable {
		return static function ( float $payable ) use ( $balance ): float {
			return Fee_Allocator::cap( $balance, $payable, 0 );
		};
	}

	public function test_replay_without_the_fix_credit_would_take_vat_too() {
		// Baseline: an unclaimed −1.000 fee takes 1.240 off (WC splits VAT onto it).
		$result = $this->replay( $this->vat_cart(), array( array( 'id' => 'storedash_credit', 'amount' => -1000 ) ) );
		$this->assertSame( 13760.0, $result['total'] );
	}

	public function test_replay_1000_credit_takes_exactly_1000_and_keeps_vat() {
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'storedash_credit',
					'amount' => -1000,
					'cap'    => $this->credit_cap( 1000 ),
				),
			)
		);
		$this->assertSame( 14000.0, $result['total'] );
		$this->assertSame( -1000.0, $result['fees']['storedash_credit'] );
		$this->assertSame( 0.0, $result['fee_taxes']['storedash_credit'] );
		$this->assertSame( 2904.0, $result['vat'], 'VAT stays on the full goods + shipping value' );
	}

	public function test_replay_credit_not_covering_shipping_stops_at_goods() {
		// Balance 20k, spend_covers_shipping off → Fee_Calculator caps at 12k goods.
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'storedash_credit',
					'amount' => -12000,
					'cap'    => $this->credit_cap( 12000 ),
				),
			)
		);
		$this->assertSame( 3000.0, $result['total'], 'shopper pays shipping' );
		$this->assertSame( 2904.0, $result['vat'] );
	}

	public function test_replay_credit_covering_shipping_pays_everything_despite_wc_clamp() {
		// spend_covers_shipping on → 15k eligible; WC alone would clamp at 12.096 ex tax.
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'storedash_credit',
					'amount' => -15000,
					'cap'    => $this->credit_cap( 15000 ),
				),
			)
		);
		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( -15000.0, $result['fees']['storedash_credit'] );
		$this->assertSame( 2904.0, $result['vat'] );
	}

	public function test_replay_15k_with_shipping_on_10k_card_totals_5k_and_keeps_vat() {
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'storedash_gift_card_7',
					'amount' => -10000,
					'cap'    => $this->card_cap( 10000 ),
				),
			)
		);
		$this->assertSame( 5000.0, $result['total'] );
		$this->assertSame( -10000.0, $result['fees']['storedash_gift_card_7'] );
		$this->assertSame( 0.0, $result['fee_taxes']['storedash_gift_card_7'] );
		$this->assertSame( 2904.0, $result['vat'] );
	}

	public function test_replay_card_larger_than_order_covers_the_vat_part_too() {
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'storedash_gift_card_7',
					'amount' => -20000,
					'cap'    => $this->card_cap( 20000 ),
				),
			)
		);
		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( -15000.0, $result['fees']['storedash_gift_card_7'] );
	}

	public function test_replay_credit_then_gift_card() {
		// Credit 2.000 (prio 20) then a 10k card (prio 30) on 15k → 3.000 left to pay.
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'storedash_credit',
					'amount' => -2000,
					'cap'    => $this->credit_cap( 2000 ),
				),
				array(
					'id'     => 'storedash_gift_card_7',
					'amount' => -10000,
					'cap'    => $this->card_cap( 10000 ),
				),
			)
		);
		$this->assertSame( 3000.0, $result['total'] );
		$this->assertSame( -2000.0, $result['fees']['storedash_credit'] );
		$this->assertSame( -10000.0, $result['fees']['storedash_gift_card_7'] );
		$this->assertSame( 2904.0, $result['vat'] );
	}

	public function test_replay_credit_then_gift_card_larger_than_rest() {
		// Credit 12k (goods) then a 10k card → card covers the remaining 3k shipping only.
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'storedash_credit',
					'amount' => -12000,
					'cap'    => $this->credit_cap( 12000 ),
				),
				array(
					'id'     => 'storedash_gift_card_7',
					'amount' => -10000,
					'cap'    => $this->card_cap( 10000 ),
				),
			)
		);
		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( -3000.0, $result['fees']['storedash_gift_card_7'] );
	}

	public function test_replay_unclaimed_fees_are_left_alone_and_counted() {
		// A third-party −500 fee (WC splits −120 VAT) before a card: card covers the remaining 14.380.
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'other_plugin',
					'amount' => -500,
				),
				array(
					'id'     => 'storedash_gift_card_7',
					'amount' => -20000,
					'cap'    => $this->card_cap( 20000 ),
				),
			)
		);
		$this->assertSame( -120.0, $result['fee_taxes']['other_plugin'] );
		$this->assertSame( -14380.0, $result['fees']['storedash_gift_card_7'] );
		$this->assertSame( 0.0, $result['total'] );
	}

	public function test_replay_two_cards_second_one_is_capped_by_the_first() {
		$result = $this->replay(
			$this->vat_cart(),
			array(
				array(
					'id'     => 'storedash_gift_card_9',
					'amount' => -12000,
					'cap'    => $this->card_cap( 12000 ),
				),
				array(
					'id'     => 'storedash_gift_card_7',
					'amount' => -3000,
					'cap'    => $this->card_cap( 10000 ),
				),
			)
		);
		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( -12000.0, $result['fees']['storedash_gift_card_9'] );
		$this->assertSame( -3000.0, $result['fees']['storedash_gift_card_7'] );
	}

	public function test_no_pass_running_leaves_taxes_untouched() {
		$pass       = new Payment_Fee_Pass();
		$fee        = new stdClass();
		$fee->total = -100000;
		$fee->object = (object) array( 'id' => 'storedash_credit', 'amount' => -1000 );
		$this->assertSame( array( 1 => -240 ), $pass->finalize( array( 1 => -240 ), $fee ) );
	}

	public function test_fee_id_parsing() {
		$this->assertSame( 7, Redemption::card_id_from_fee_id( 'storedash_gift_card_7' ) );
		$this->assertSame( 0, Redemption::card_id_from_fee_id( 'storedash_credit' ) );
		$this->assertSame( 0, Redemption::card_id_from_fee_id( 'storedash_gift_card_x' ) );
	}
}
