<?php
/**
 * Re-activating an order whose gift card can no longer pay (live B2: #183
 * cancelled → released → card adjusted → set processing) must strip that
 * card's fee line, leave the total unpaid and put the order on hold.
 * Also: Rewards credit line gross is rounded (live B1: 1 kr short at qty 2).
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Engine\Fee_Calculator;
use StoreDash\Credit\Engine\Spend_Handler;
use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Engine\Reservation;

require_once __DIR__ . '/gift-card-stubs.php';
require_once __DIR__ . '/Card_LedgerTest.php';
require_once __DIR__ . '/../../inc/Credit/Ledger.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Eligibility.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Spend_Handler.php';

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Minimal WC_Order double for the reservation flow.
	 */
	class WC_Order { // phpcs:ignore
		public $id       = 0;
		public $status   = 'processing';
		public $fees     = array();
		public $goods    = 0.0;
		public $total    = 0.0;
		public $notes    = array();
		public $meta     = array();
		public $statuses = array();

		public function get_id() {
			return $this->id;
		}
		public function get_items( $type ) {
			return 'fee' === $type ? $this->fees : array();
		}
		public function remove_item( $item_id ) {
			unset( $this->fees[ $item_id ] );
		}
		public function calculate_totals( $and_taxes = true ) {
			$this->total = $this->goods;
			foreach ( $this->fees as $fee ) {
				$this->total += $fee->get_total();
			}
			return $this->total;
		}
		public function get_total() {
			return $this->total;
		}
		public function get_status() {
			return $this->status;
		}
		public function update_status( $status, $note = '' ) {
			$this->statuses[] = $status;
			$this->status     = $status;
			$this->notes[]    = $note;
		}
		public function add_order_note( $note ) {
			$this->notes[] = $note;
		}
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}
		public function get_meta( $key, $single = true ) {
			return $this->meta[ $key ] ?? '';
		}
		public function get_currency() {
			return 'ISK';
		}
		public function save() {}
	}
}

/**
 * Fee line double.
 */
class Reservation_Test_Fee {
	public $total;
	public $card_id;

	public function __construct( $total, $card_id ) {
		$this->total   = (float) $total;
		$this->card_id = $card_id;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_meta( $key, $single = true ) {
		return '_storedash_gift_card_id' === $key ? (string) $this->card_id : '';
	}
}

/**
 * @covers \StoreDash\GiftCard\Engine\Reservation
 * @covers \StoreDash\Credit\Engine\Spend_Handler::line_gross
 */
class Reservation_ReactivateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__test_price_decimals'] = 0;
	}

	protected function tearDown(): void {
		$GLOBALS['__test_price_decimals'] = 2;
	}

	public function test_reactivated_order_without_card_money_goes_on_hold_and_owes_the_difference() {
		$ledger = new Memory_Card_Ledger();
		$card   = $ledger->issue( array( 'initial_amount' => 10000, 'currency' => 'ISK' ), 'issue:manual:b2' )['card'];

		// Paid order: 15.100 goods + shipping, 10k card.
		$order        = new WC_Order();
		$order->id    = 183;
		$order->goods = 15100;
		$order->fees  = array( 11 => new Reservation_Test_Fee( -10000, (int) $card->id ) );
		$order->calculate_totals();
		$this->assertIsArray( $ledger->spend_order( 183, array( $card->id => 10000 ) ) );

		$reservation = new Reservation( $ledger );

		// Cancelled → released.
		$reservation->on_status_changed( 183, 'processing', 'cancelled', $order );
		$this->assertSame( 10000.0, (float) $ledger->get_card( $card->id )->balance );

		// Merchant reduces the card to 4.000, then sets the order back to processing.
		$ledger->move( (int) $card->id, -6000, Card_Ledger::TYPE_ADJUST, 'adjust:b2', array( 'note' => 'test' ) );
		$order->status = 'processing';
		$reservation->on_status_changed( 183, 'cancelled', 'processing', $order );

		$this->assertSame( array(), $order->fees, 'card fee line removed' );
		$this->assertSame( 15100.0, (float) $order->get_total(), 'total now unpaid' );
		$this->assertSame( array( 'on-hold' ), $order->statuses );
		$this->assertSame( 4000.0, (float) $ledger->get_card( $card->id )->balance, 'card not charged' );
		$this->assertSame( array(), $order->meta['_storedash_gift_cards_applied'] );
	}

	public function test_reactivated_order_with_enough_balance_is_charged_again() {
		$ledger       = new Memory_Card_Ledger();
		$card         = $ledger->issue( array( 'initial_amount' => 10000, 'currency' => 'ISK' ), 'issue:manual:b2b' )['card'];
		$order        = new WC_Order();
		$order->id    = 184;
		$order->goods = 15100;
		$order->fees  = array( 11 => new Reservation_Test_Fee( -10000, (int) $card->id ) );
		$order->calculate_totals();
		$ledger->spend_order( 184, array( $card->id => 10000 ) );

		$reservation = new Reservation( $ledger );
		$reservation->on_status_changed( 184, 'processing', 'cancelled', $order );
		$order->status = 'processing';
		$reservation->on_status_changed( 184, 'cancelled', 'processing', $order );

		$this->assertCount( 1, $order->fees );
		$this->assertSame( array(), $order->statuses );
		$this->assertSame( 0.0, (float) $ledger->get_card( $card->id )->balance );
		$this->assertNotNull( $ledger->find_by_idem( 'spend:184:' . $card->id . ':2' ) );
	}

	public function test_credit_line_gross_is_rounded_so_qty_2_is_covered_in_full() {
		// Serum 12.000 × 2 incl. 24% VAT: WC keeps 19354.8387 + 4645.1612.
		$line = Spend_Handler::line_gross(
			array(
				'line_total' => 19354.8387,
				'line_tax'   => 4645.1612,
			),
			0
		);
		$this->assertSame( 24000.0, $line );

		$settings = array(
			'max_spend_pct'      => null,
			'min_order_to_spend' => null,
		);
		$this->assertSame( 24000.0, Fee_Calculator::max_applicable( $line, $line, 50000, $settings, 0 ) );
		// Before the fix: the unrounded sum floored to 23 999.
		$this->assertSame( 23999.0, Fee_Calculator::max_applicable( 19354.8387 + 4645.1612, 24000, 50000, $settings, 0 ) );
	}
}
