<?php
/**
 * Reservation follows order edits:
 * - admin Recalculate shrinks a held card fee line → the ledger re-syncs
 *   (audit MF-2), refunds and nested recalcs are ignored;
 * - an unpaid order deleted for good releases its card money, trash does not
 *   (audit CI-4).
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Payment_Fee_Pass;
use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Engine\Reservation;

require_once __DIR__ . '/order-doubles.php';
require_once __DIR__ . '/Card_LedgerTest.php';

/**
 * Pass whose `storedash_payment_fees_adjusted` goes straight to listeners.
 */
class Resync_Test_Pass extends Payment_Fee_Pass {
	public $fired     = 0;
	public $listeners = array();

	protected function fees_adjusted( $order ): void {
		++$this->fired;
		foreach ( $this->listeners as $listener ) {
			call_user_func( $listener, $order );
		}
	}
}

/**
 * Ledger that counts order_summary() reads.
 */
class Resync_Spy_Ledger extends Memory_Card_Ledger {
	public $summaries = 0;

	public function order_summary( int $order_id ): array {
		++$this->summaries;
		return parent::order_summary( $order_id );
	}
}

/**
 * @covers \StoreDash\GiftCard\Engine\Reservation
 * @covers \StoreDash\Credit\Payment_Fee_Pass::order_after_totals
 */
class Reservation_Resync_DeleteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__test_price_decimals'] = 0;
		$GLOBALS['__test_post_types']     = array();
	}

	protected function tearDown(): void {
		$GLOBALS['__test_price_decimals'] = 2;
		$GLOBALS['__test_post_types']     = array();
	}

	/**
	 * On-hold order: 15.100 goods, 10k card spent (and optionally 2k credit first).
	 */
	private function held_order( Memory_Card_Ledger $ledger, int $order_id, bool $with_credit = false ): array {
		$card         = $ledger->issue( array( 'initial_amount' => 10000, 'currency' => 'ISK' ), 'issue:manual:o' . $order_id )['card'];
		$order        = new GC_Test_Order();
		$order->id    = $order_id;
		$order->status = 'on-hold';
		$order->lines = array( 1 => new GC_Test_Item( 15100 ) );
		$fees         = array();
		if ( $with_credit ) {
			$fees[20] = new GC_Test_Item( -2000, 1, array( '_storedash_credit_fee' => '1' ) );
		}
		$fees[21]     = new GC_Test_Item( -10000, 1, array( '_storedash_gift_card_id' => (string) $card->id ) );
		$order->fees  = $fees;
		$this->assertIsArray( $ledger->spend_order( $order_id, array( $card->id => 10000 ) ) );
		return array( $order, $card );
	}

	private function wire( Memory_Card_Ledger $ledger, GC_Test_Order $order ): Resync_Test_Pass {
		$pass        = new Resync_Test_Pass();
		$reservation = new Reservation( $ledger );
		$pass->listeners[] = array( $reservation, 'on_fees_adjusted' );
		$order->pass = $pass;
		return $pass;
	}

	public function test_recalc_that_shrinks_a_held_card_resyncs_the_ledger_to_the_fee_line() {
		$ledger                = new Memory_Card_Ledger();
		list( $order, $card )  = $this->held_order( $ledger, 301 );
		$pass                  = $this->wire( $ledger, $order );

		// Merchant removes items down to 3.000 and recalculates.
		$order->lines = array( 1 => new GC_Test_Item( 3000 ) );
		$order->calculate_totals();

		$this->assertSame( -3000.0, $order->fees[21]->get_total() );
		$this->assertSame( 0.0, $order->get_total() );
		$this->assertSame( 1, $pass->fired );
		$summary = $ledger->order_summary( 301 );
		$this->assertSame( abs( $order->fees[21]->get_total() ), $summary[ $card->id ]['outstanding'], 'outstanding == |fee line|' );
		$this->assertSame( 7000.0, (float) $ledger->get_card( $card->id )->balance );
		$this->assertNotNull( $ledger->find_by_idem( 'release:301:' . $card->id . ':1' ) );
		$this->assertNotNull( $ledger->find_by_idem( 'spend:301:' . $card->id . ':2' ) );
		$this->assertSame( 3000.0, (float) $order->meta['_storedash_gift_cards_applied'][0]['amount'] );

		// Recalculating again changes nothing (no shrink → no event, no new rows).
		$rows = count( $ledger->rows );
		$order->calculate_totals();
		$this->assertSame( 1, $pass->fired );
		$this->assertCount( $rows, $ledger->rows );

		// A second shrink uses the next keys, never a used one.
		$order->lines = array( 1 => new GC_Test_Item( 1000 ) );
		$order->calculate_totals();
		$this->assertSame( 1000.0, $ledger->order_summary( 301 )[ $card->id ]['outstanding'] );
		$this->assertNotNull( $ledger->find_by_idem( 'release:301:' . $card->id . ':2' ) );
		$this->assertNotNull( $ledger->find_by_idem( 'spend:301:' . $card->id . ':3' ) );
		$this->assertSame( 9000.0, (float) $ledger->get_card( $card->id )->balance );
	}

	public function test_credit_fee_ahead_of_the_card_is_kept_and_only_the_card_shrinks() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->held_order( $ledger, 302, true );
		$pass                 = $this->wire( $ledger, $order );

		$order->lines = array( 1 => new GC_Test_Item( 5000 ) );
		$order->calculate_totals();

		$this->assertSame( -2000.0, $order->fees[20]->get_total(), 'credit fee untouched' );
		$this->assertSame( -3000.0, $order->fees[21]->get_total() );
		$this->assertSame( 1, $pass->fired );
		$this->assertSame( 3000.0, $ledger->order_summary( 302 )[ $card->id ]['outstanding'] );
	}

	public function test_recalc_without_shrink_or_with_only_a_credit_shrink_fires_nothing() {
		$pass         = new Resync_Test_Pass();
		$order        = new GC_Test_Order();
		$order->pass  = $pass;
		$order->lines = array( 1 => new GC_Test_Item( 15100 ) );
		$order->fees  = array( 21 => new GC_Test_Item( -10000, 1, array( '_storedash_gift_card_id' => '5' ) ) );
		$order->calculate_totals();
		$this->assertSame( 0, $pass->fired );

		$order->fees = array( 20 => new GC_Test_Item( -2000, 1, array( '_storedash_credit_fee' => '1' ) ) );
		$order->lines = array( 1 => new GC_Test_Item( 500 ) );
		$order->calculate_totals();
		$this->assertSame( -500.0, $order->fees[20]->get_total() );
		$this->assertSame( 0, $pass->fired, 'credit-only shrink is not a gift card event' );
	}

	public function test_order_without_held_money_is_left_to_checkout() {
		$ledger       = new Memory_Card_Ledger();
		$card         = $ledger->issue( array( 'initial_amount' => 10000, 'currency' => 'ISK' ), 'issue:manual:o303' )['card'];
		$order        = new GC_Test_Order();
		$order->id    = 303;
		$order->lines = array( 1 => new GC_Test_Item( 3000 ) );
		$order->fees  = array( 21 => new GC_Test_Item( -3000, 1, array( '_storedash_gift_card_id' => (string) $card->id ) ) );

		( new Reservation( $ledger ) )->on_fees_adjusted( $order );

		$this->assertSame( array(), $ledger->order_summary( 303 ) );
		$this->assertSame( 10000.0, (float) $ledger->get_card( $card->id )->balance );
	}

	public function test_refund_recalc_never_reaches_the_ledger() {
		$ledger = new Resync_Spy_Ledger();
		( new Reservation( $ledger ) )->on_fees_adjusted( new WC_Order_Refund( 900, 0 ) );
		$this->assertSame( 0, $ledger->summaries );
	}

	public function test_failed_respend_strips_the_card_fee_through_a_nested_recalc_without_looping() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->held_order( $ledger, 304 );
		$pass                 = $this->wire( $ledger, $order );
		// Card disabled after checkout: the re-spend after release must fail.
		$ledger->cards[ $card->id ]->status = Card_Ledger::STATUS_DISABLED;

		$order->lines = array( 1 => new GC_Test_Item( 3000 ) );
		$order->calculate_totals();

		$this->assertSame( 2, $order->recalcs, 'one nested recalc from strip_card_fees' );
		$this->assertSame( 1, $pass->fired, 'nested pass had no card fee left to shrink' );
		$this->assertSame( array(), $order->fees );
		$this->assertSame( 3000.0, $order->get_total(), 'order now owes what the card no longer covers' );
		$this->assertSame( 0.0, $ledger->order_summary( 304 )[ $card->id ]['outstanding'] );
		$this->assertSame( 10000.0, (float) $ledger->get_card( $card->id )->balance );
		$this->assertSame( array(), $order->statuses, 'on-hold stays on-hold' );
	}

	// ── CI-4: delete ────────────────────────────────────────────────────

	public function test_permanently_deleting_an_unpaid_order_releases_its_card_money() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->held_order( $ledger, 305 );
		$reservation          = new Reservation( $ledger );

		$reservation->on_before_delete_order( 305, $order );
		$this->assertSame( 10000.0, (float) $ledger->get_card( $card->id )->balance );
		$this->assertSame( 0.0, $ledger->order_summary( 305 )[ $card->id ]['outstanding'] );

		// CPT orders fire before_delete_post too: the second call is a no-op.
		$rows                                 = count( $ledger->rows );
		$GLOBALS['__test_post_types'][305]    = 'shop_order';
		$GLOBALS['__test_wc_order']           = $order;
		$reservation->on_before_delete_post( 305 );
		$this->assertCount( $rows, $ledger->rows );
		unset( $GLOBALS['__test_wc_order'] );
	}

	public function test_deleting_a_trashed_unpaid_order_uses_its_pre_trash_status() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->held_order( $ledger, 306 );
		$order->status        = 'trash';
		$order->meta['_wp_trash_meta_status'] = 'wc-pending';

		$GLOBALS['__test_post_types'][306] = 'shop_order';
		$GLOBALS['__test_wc_order']        = $order;
		( new Reservation( $ledger ) )->on_before_delete_post( 306 );
		unset( $GLOBALS['__test_wc_order'] );

		$this->assertSame( 10000.0, (float) $ledger->get_card( $card->id )->balance );
	}

	public function test_trash_and_paid_order_delete_keep_the_money_held() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->held_order( $ledger, 307 );
		$reservation          = new Reservation( $ledger );

		// Trashing is not a release (untrash keeps the card fee lines).
		$order->status                        = 'trash';
		$order->meta['_wp_trash_meta_status'] = 'wc-on-hold';
		$reservation->on_status_changed( 307, 'on-hold', 'trash', $order );
		$this->assertSame( 0.0, (float) $ledger->get_card( $card->id )->balance );

		// A paid (trashed) order deleted for good keeps what it paid.
		$order->meta['_wp_trash_meta_status'] = 'wc-completed';
		$reservation->on_before_delete_order( 307, $order );
		$order->status = 'processing';
		$reservation->on_before_delete_order( 307, $order );
		$this->assertSame( 0.0, (float) $ledger->get_card( $card->id )->balance );

		// Non-order posts are ignored.
		$GLOBALS['__test_post_types'][307] = 'post';
		$order->status                     = 'pending';
		$GLOBALS['__test_wc_order']        = $order;
		$reservation->on_before_delete_post( 307 );
		unset( $GLOBALS['__test_wc_order'] );
		$this->assertSame( 0.0, (float) $ledger->get_card( $card->id )->balance );
	}
}
