<?php
/**
 * Refund_Handler hook flows, replaying WooCommerce's order:
 * wc_create_refund → woocommerce_order_fully_refunded → status Refunded
 * (on_status_refunded) → woocommerce_order_refunded (on_refund).
 *
 * - Card-only orders: full refunds (zeroed totals, bare 0, manual status)
 *   return everything; a quantity-subset refund returns its share (audit CI-2).
 * - Order marked Refunded with no line items reclaims purchased cards (CI-3).
 * - Status-refund key is scoped by spend count (CI-7).
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Engine\Refund_Handler;
use StoreDash\GiftCard\Engine\Reservation;

require_once __DIR__ . '/order-doubles.php';
require_once __DIR__ . '/Card_LedgerTest.php';

/**
 * @covers \StoreDash\GiftCard\Engine\Refund_Handler
 */
class Refund_Handler_FlowTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__test_price_decimals'] = 0;
	}

	protected function tearDown(): void {
		$GLOBALS['__test_price_decimals'] = 2;
		unset( $GLOBALS['__test_wc_order'] );
	}

	/**
	 * Card-only order: 4 × 2.000, paid 8.000 by one card (cash total 0).
	 */
	private function card_only_order( Memory_Card_Ledger $ledger, int $order_id ): array {
		$card          = $ledger->issue( array( 'initial_amount' => 8000, 'currency' => 'ISK' ), 'issue:manual:r' . $order_id )['card'];
		$order         = new GC_Test_Order();
		$order->id     = $order_id;
		$order->status = 'completed';
		$order->lines  = array( 5 => new GC_Test_Item( 8000, 4 ) );
		$order->fees   = array( 6 => new GC_Test_Item( -8000, 1, array( '_storedash_gift_card_id' => (string) $card->id ) ) );
		$order->total  = 0.0;
		$this->assertIsArray( $ledger->spend_order( $order_id, array( $card->id => 8000 ) ) );
		return array( $order, $card );
	}

	/** Refund line for item 5 (totals zeroed, as wp-admin posts with taxes on). */
	private function qty_line( int $qty ): GC_Test_Item {
		return new GC_Test_Item( 0, -$qty, array( '_refunded_item_id' => 5 ) );
	}

	/**
	 * Replays wc_create_refund() for an order WooCommerce treats as fully refunded.
	 */
	private function wc_full_refund( Refund_Handler $handler, GC_Test_Order $order, WC_Order_Refund $refund ): void {
		array_unshift( $order->refunds, $refund );
		$handler->on_fully_refunded( $order->id, $refund->id );
		if ( 'refunded' !== $order->status ) {
			$order->status              = 'refunded';
			$GLOBALS['__test_wc_order'] = $refund;
			$handler->on_status_refunded( $order );
		}
		$handler->on_refund( $order, $refund );
	}

	private function balance( Memory_Card_Ledger $ledger, $card ): float {
		return (float) $ledger->get_card( $card->id )->balance;
	}

	// ── CI-2 ──────────────────────────────────────────────────────────────

	public function test_card_only_full_refund_with_zeroed_totals_returns_everything() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->card_only_order( $ledger, 401 );
		$handler              = new Refund_Handler( $ledger );

		$this->wc_full_refund( $handler, $order, new WC_Order_Refund( 4011, 0, array( 50 => $this->qty_line( 4 ) ) ) );

		$this->assertSame( 8000.0, $this->balance( $ledger, $card ) );
		$this->assertSame( 0.0, $ledger->order_summary( 401 )[ $card->id ]['outstanding'] );
		$row = $ledger->find_by_idem( 'refund:order-401:' . $card->id . ':1' );
		$this->assertNotNull( $row );
		$this->assertSame( 4011, (int) $row->refund_id, 'linked to the refund being created' );
	}

	public function test_card_only_quantity_subset_refund_returns_only_its_share() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->card_only_order( $ledger, 402 );
		$handler              = new Refund_Handler( $ledger );

		// 1 of 4 (zeroed totals): WooCommerce still marks the order Refunded.
		$this->wc_full_refund( $handler, $order, new WC_Order_Refund( 4021, 0, array( 51 => $this->qty_line( 1 ) ) ) );
		$this->assertSame( 2000.0, $this->balance( $ledger, $card ) );
		$this->assertNull( $ledger->find_by_idem( 'refund:order-402:' . $card->id . ':1' ), 'no sweep' );
		$this->assertNotNull( $ledger->find_by_idem( 'refund:4021:' . $card->id ) );

		// The other 3 later (status already Refunded → no status hook): the rest comes back.
		$this->wc_full_refund( $handler, $order, new WC_Order_Refund( 4022, 0, array( 52 => $this->qty_line( 3 ) ) ) );
		$this->assertSame( 8000.0, $this->balance( $ledger, $card ) );
		$this->assertSame( 0.0, $ledger->order_summary( 402 )[ $card->id ]['outstanding'] );
	}

	public function test_card_only_subset_refund_typed_at_line_value_is_pro_rata() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->card_only_order( $ledger, 403 );
		$handler              = new Refund_Handler( $ledger );

		$line = new GC_Test_Item( -2000, -1, array( '_refunded_item_id' => 5 ) );
		$this->wc_full_refund( $handler, $order, new WC_Order_Refund( 4031, 0, array( 53 => $line ) ) );

		$this->assertSame( 2000.0, $this->balance( $ledger, $card ) );
	}

	public function test_manual_status_change_and_bare_zero_refund_sweep_everything() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->card_only_order( $ledger, 404 );
		$handler              = new Refund_Handler( $ledger );

		// Manual Refunded on a card-only order: WooCommerce creates no refund.
		$order->status = 'refunded';
		$handler->on_status_refunded( $order );
		$this->assertSame( 8000.0, $this->balance( $ledger, $card ) );
		$this->assertNull( $ledger->find_by_idem( 'refund:order-404:' . $card->id . ':1' )->refund_id );

		list( $order2, $card2 ) = $this->card_only_order( $ledger, 405 );
		$this->wc_full_refund( $handler, $order2, new WC_Order_Refund( 4051, 0, array() ) );
		$this->assertSame( 8000.0, $this->balance( $ledger, $card2 ), 'refund 0 without lines = full' );
	}

	// ── CI-7 ──────────────────────────────────────────────────────────────

	public function test_refunded_then_respent_order_refunded_again_credits_the_second_spend() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->card_only_order( $ledger, 406 );
		$handler              = new Refund_Handler( $ledger );
		$reservation          = new Reservation( $ledger );

		$order->status = 'refunded';
		$handler->on_status_refunded( $order );
		$this->assertSame( 8000.0, $this->balance( $ledger, $card ) );

		// Refunded → cancelled (nothing held) → processing (re-spend, n = 2).
		$order->status = 'cancelled';
		$reservation->on_status_changed( 406, 'refunded', 'cancelled', $order );
		$order->status = 'processing';
		$reservation->on_status_changed( 406, 'cancelled', 'processing', $order );
		$this->assertSame( 0.0, $this->balance( $ledger, $card ) );

		$order->status = 'refunded';
		$handler->on_status_refunded( $order );
		$this->assertSame( 8000.0, $this->balance( $ledger, $card ) );
		$this->assertNotNull( $ledger->find_by_idem( 'refund:order-406:' . $card->id . ':2' ) );

		// Re-runs replay.
		$rows = count( $ledger->rows );
		$handler->on_status_refunded( $order );
		$this->assertCount( $rows, $ledger->rows );
	}

	public function test_legacy_unsuffixed_status_refund_row_is_not_paid_twice() {
		$ledger               = new Memory_Card_Ledger();
		list( $order, $card ) = $this->card_only_order( $ledger, 407 );
		// Written by a plugin version before the `:n` suffix.
		$ledger->move( (int) $card->id, 3000, Card_Ledger::TYPE_REFUND, 'refund:order-407:' . $card->id, array( 'order_id' => 407 ) );

		$order->status = 'refunded';
		( new Refund_Handler( $ledger ) )->on_status_refunded( $order );

		$this->assertSame( 3000.0, $this->balance( $ledger, $card ) );
		$this->assertNull( $ledger->find_by_idem( 'refund:order-407:' . $card->id . ':1' ) );
	}

	// ── CI-3 ──────────────────────────────────────────────────────────────

	/**
	 * Cash order that bought 2 × 10.000 gift cards (one line, qty 2).
	 */
	private function purchase_order( Memory_Card_Ledger $ledger, int $order_id ): array {
		$a = $ledger->issue( array( 'initial_amount' => 10000, 'currency' => 'ISK', 'order_item_id' => 9, 'unit_index' => 0 ), 'issue:9:0:' . $order_id )['card'];
		$b = $ledger->issue( array( 'initial_amount' => 10000, 'currency' => 'ISK', 'order_item_id' => 9, 'unit_index' => 1 ), 'issue:9:1:' . $order_id )['card'];

		$order                   = new GC_Test_Order();
		$order->id               = $order_id;
		$order->status           = 'completed';
		$order->total            = 20000.0;
		$order->remaining_refund = 20000.0;
		$order->lines            = array( 9 => new GC_Test_Item( 20000, 2, array( '_storedash_gc_card_ids' => wp_json_encode( array( (int) $a->id, (int) $b->id ) ) ) ) );
		return array( $order, $a, $b );
	}

	public function test_order_marked_refunded_reclaims_purchased_cards_up_to_their_balance() {
		$ledger             = new Memory_Card_Ledger();
		list( $o, $a, $b )  = $this->purchase_order( $ledger, 408 );
		$ledger->spend_order( 999, array( $a->id => 3000 ) ); // Card A partly spent elsewhere.
		$handler            = new Refund_Handler( $ledger );

		// wc_order_fully_refunded(): status already Refunded, refund with no line items.
		$o->status           = 'refunded';
		$o->remaining_refund = 0.0;
		$refund              = new WC_Order_Refund( 4081, 20000, array() );
		$o->refunds          = array( $refund );
		$handler->on_refund( $o, $refund );

		$this->assertSame( 0.0, $this->balance( $ledger, $a ) );
		$this->assertSame( 0.0, $this->balance( $ledger, $b ) );
		$this->assertNotNull( $ledger->find_by_idem( 'refund_purchase:4081:' . $a->id ) );
		$this->assertNotNull( $ledger->find_by_idem( 'refund_purchase:4081:' . $b->id ) );
		$this->assertStringContainsString( '$3,000.00 had already been spent', implode( ' | ', $o->notes ), 'spent part noted' );

		$rows = count( $ledger->rows );
		$handler->on_refund( $o, $refund );
		$this->assertCount( $rows, $ledger->rows, 'idempotent' );
	}

	public function test_full_refund_after_a_line_refund_takes_only_the_face_value_left() {
		$ledger            = new Memory_Card_Ledger();
		list( $o, $a, $b ) = $this->purchase_order( $ledger, 409 );
		$handler           = new Refund_Handler( $ledger );

		// One card's worth refunded as a line first.
		$first               = new WC_Order_Refund( 4091, 10000, array( 60 => new GC_Test_Item( -10000, -1, array( '_refunded_item_id' => 9 ) ) ) );
		$o->refunds          = array( $first );
		$o->remaining_refund = 10000.0;
		$handler->on_refund( $o, $first );
		$this->assertSame( 10000.0, $this->balance( $ledger, $a ) + $this->balance( $ledger, $b ) );

		// Customer spends 4.000 of the card that is left, then the rest is refunded.
		$left = $this->balance( $ledger, $a ) > 0 ? $a : $b;
		$ledger->spend_order( 998, array( $left->id => 4000 ) );
		$o->status           = 'refunded';
		$o->remaining_refund = 0.0;
		$second              = new WC_Order_Refund( 4092, 10000, array() );
		array_unshift( $o->refunds, $second );
		$notes = count( $o->notes );
		$handler->on_refund( $o, $second );

		$this->assertSame( 0.0, $this->balance( $ledger, $a ) );
		$this->assertSame( 0.0, $this->balance( $ledger, $b ) );
		$taken = abs( (float) $ledger->find_by_idem( 'refund_purchase:4092:' . $left->id )->amount );
		$this->assertSame( 6000.0, $taken );
		$this->assertStringContainsString( '$4,000.00 had already been spent', implode( ' | ', array_slice( $o->notes, $notes ) ), 'shortfall = what was spent of the face value left' );
	}

	public function test_partial_amount_only_refund_keeps_the_note_and_the_cards() {
		$ledger            = new Memory_Card_Ledger();
		list( $o, $a, $b ) = $this->purchase_order( $ledger, 410 );
		$o->remaining_refund = 15000.0;
		$refund              = new WC_Order_Refund( 4101, 5000, array() );
		$o->refunds          = array( $refund );

		( new Refund_Handler( $ledger ) )->on_refund( $o, $refund );

		$this->assertSame( 10000.0, $this->balance( $ledger, $a ) );
		$this->assertSame( 10000.0, $this->balance( $ledger, $b ) );
		$this->assertStringContainsString( 'did not include the gift card lines', implode( ' | ', $o->notes ) );
	}
}
