<?php
/**
 * Card store: issue idempotency, atomic multi-card spend, release / refund /
 * adjust, status changes and the per-order summary.
 *
 * Runs the real Card_Ledger logic against an in-memory storage double
 * (transactions modelled as snapshots), like the Credit ledger suite.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Code;
use StoreDash\GiftCard\Engine\Reservation;

require_once __DIR__ . '/gift-card-stubs.php';

/**
 * In-memory card store.
 */
class Memory_Card_Ledger extends Card_Ledger {

	/** @var array id => card */
	public $cards = array();
	/** @var array id => row */
	public $rows = array();
	/** @var int */
	public $next_card = 1;
	/** @var int */
	public $next_row = 1;
	/** @var array|null */
	private $snapshot = null;
	/** @var int */
	public $rollbacks = 0;
	/** @var string|null Idem key whose insert should fail once (simulated race). */
	public $fail_idem = null;

	protected function now(): string {
		return '2026-10-05 12:00:00';
	}

	protected function db_begin(): void {
		$this->snapshot = array( $this->clone_all( $this->cards ), $this->clone_all( $this->rows ), $this->next_card, $this->next_row );
	}

	protected function db_commit(): void {
		$this->snapshot = null;
	}

	protected function db_rollback(): void {
		if ( $this->snapshot ) {
			list( $this->cards, $this->rows, $this->next_card, $this->next_row ) = $this->snapshot;
		}
		$this->snapshot = null;
		++$this->rollbacks;
	}

	private function clone_all( array $items ): array {
		$out = array();
		foreach ( $items as $id => $item ) {
			$out[ $id ] = clone $item;
		}
		return $out;
	}

	protected function db_get_card( int $id, bool $lock ) {
		return isset( $this->cards[ $id ] ) ? clone $this->cards[ $id ] : null;
	}

	protected function db_find_card_by_hash( string $hash ) {
		foreach ( $this->cards as $card ) {
			if ( $card->code_hash === $hash ) {
				return clone $card;
			}
		}
		return null;
	}

	protected function db_find_card_by_item( int $order_item_id, int $unit_index ) {
		foreach ( $this->cards as $card ) {
			if ( (int) $card->order_item_id === $order_item_id && (int) $card->unit_index === $unit_index ) {
				return clone $card;
			}
		}
		return null;
	}

	protected function db_insert_card( array $card ): int {
		foreach ( $this->cards as $existing ) {
			if ( $existing->code_hash === $card['code_hash'] ) {
				return 0;
			}
			if ( null !== $card['order_item_id'] && (int) $existing->order_item_id === (int) $card['order_item_id'] && (int) $existing->unit_index === (int) $card['unit_index'] ) {
				return 0;
			}
		}
		$id                 = $this->next_card++;
		$card['id']         = $id;
		$this->cards[ $id ] = (object) $card;
		return $id;
	}

	protected function db_update_card( int $id, array $fields ): void {
		foreach ( $fields as $key => $value ) {
			$this->cards[ $id ]->$key = $value;
		}
	}

	protected function db_find_by_idem( string $idem_key ) {
		foreach ( $this->rows as $row ) {
			if ( $row->idem_key === $idem_key ) {
				return clone $row;
			}
		}
		return null;
	}

	protected function db_get_row( int $id ) {
		return isset( $this->rows[ $id ] ) ? clone $this->rows[ $id ] : null;
	}

	protected function db_insert_row( array $row ): int {
		if ( null !== $this->fail_idem && $row['idem_key'] === $this->fail_idem ) {
			$this->fail_idem = null;
			return 0;
		}
		foreach ( $this->rows as $existing ) {
			if ( $existing->idem_key === $row['idem_key'] ) {
				return 0;
			}
		}
		$id                = $this->next_row++;
		$row['id']         = $id;
		$this->rows[ $id ] = (object) $row;
		return $id;
	}

	protected function db_rows_for_card( int $card_id ): array {
		return array_values( array_filter( $this->rows, static function ( $r ) use ( $card_id ) {
			return (int) $r->card_id === $card_id;
		} ) );
	}

	protected function db_rows_for_order( int $order_id ): array {
		return array_values( array_filter( $this->rows, static function ( $r ) use ( $order_id ) {
			return (int) $r->order_id === $order_id && in_array( $r->type, array( 'spend', 'release', 'refund' ), true );
		} ) );
	}

	protected function db_cards_since( string $since_utc, int $offset, int $limit ): array {
		return array_slice( array_values( $this->cards ), $offset, $limit );
	}

	protected function db_count_cards_since( string $since_utc ): int {
		return count( $this->cards );
	}

	protected function db_rows_since( string $since_utc, int $offset, int $limit ): array {
		return array_slice( array_values( $this->rows ), $offset, $limit );
	}

	protected function db_count_rows_since( string $since_utc ): int {
		return count( $this->rows );
	}
}

/**
 * @covers \StoreDash\GiftCard\Card_Ledger
 * @covers \StoreDash\GiftCard\Engine\Reservation::plan
 */
class Gift_Card_LedgerTest extends TestCase {

	/** @var Memory_Card_Ledger */
	private $ledger;

	protected function setUp(): void {
		$this->ledger = new Memory_Card_Ledger();
	}

	private function issue( float $amount, string $idem, array $extra = array() ) {
		return $this->ledger->issue(
			array_merge(
				array(
					'initial_amount' => $amount,
					'currency'       => 'ISK',
				),
				$extra
			),
			$idem
		);
	}

	public function test_issue_creates_card_and_issue_row_without_storing_the_code() {
		$result = $this->issue( 10000, 'issue:55:1', array( 'order_item_id' => 55, 'unit_index' => 1, 'order_id' => 9911 ) );

		$this->assertTrue( $result['created'] );
		$this->assertTrue( Code::is_well_formed( $result['code'] ) );
		$card = $result['card'];
		$this->assertSame( 10000.0, (float) $card->balance );
		$this->assertSame( 'active', $card->status );
		$this->assertSame( Code::last4( $result['code'] ), $card->code_last4 );
		$this->assertSame( Code::hash( $result['code'] ), $card->code_hash );
		$this->assertSame( $result['code'], Code::decrypt( $card->code_enc ) );
		foreach ( (array) $card as $value ) {
			$this->assertNotSame( $result['code'], $value, 'raw code must never be stored' );
		}

		$this->assertSame( 'issue', $result['row']->type );
		$this->assertSame( 10000.0, (float) $result['row']->balance_after );
		$found = $this->ledger->find_card_by_code( Code::normalize( Code::format( $result['code'] ) ) );
		$this->assertSame( (int) $card->id, (int) $found->id );
	}

	public function test_issue_is_idempotent_by_key_and_by_order_item_unit() {
		$first  = $this->issue( 5000, 'issue:55:1', array( 'order_item_id' => 55, 'unit_index' => 1 ) );
		$replay = $this->issue( 5000, 'issue:55:1', array( 'order_item_id' => 55, 'unit_index' => 1 ) );
		$this->assertFalse( $replay['created'] );
		$this->assertSame( '', $replay['code'] );
		$this->assertSame( (int) $first['card']->id, (int) $replay['card']->id );

		// Qty 2 → two distinct cards.
		$second = $this->issue( 5000, 'issue:55:2', array( 'order_item_id' => 55, 'unit_index' => 2 ) );
		$this->assertTrue( $second['created'] );
		$this->assertCount( 2, $this->ledger->cards );
		$this->assertCount( 2, $this->ledger->rows );
	}

	public function test_find_by_code_accepts_messy_input_and_rejects_garbage() {
		$result = $this->issue( 1000, 'issue:manual:a' );
		$messy  = strtolower( Code::format( $result['code'] ) );
		$this->assertNotNull( $this->ledger->find_card_by_code( \StoreDash\GiftCard\Code::normalize( $messy ) ) );
		$this->assertNull( $this->ledger->find_card_by_code( 'NOPE' ) );
	}

	public function test_spend_order_is_all_or_nothing_across_cards() {
		$a = $this->issue( 10000, 'issue:manual:a' )['card'];
		$b = $this->issue( 3000, 'issue:manual:b' )['card'];

		$result = $this->ledger->spend_order( 77, array( $a->id => 8000, $b->id => 5000 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_gift_card_insufficient', $result->get_error_code() );
		$this->assertSame( 10000.0, (float) $this->ledger->get_card( $a->id )->balance, 'first card untouched' );
		$this->assertCount( 2, $this->ledger->rows );

		$ok = $this->ledger->spend_order( 77, array( $a->id => 8000, $b->id => 3000 ) );
		$this->assertCount( 2, $ok );
		$this->assertSame( 2000.0, (float) $this->ledger->get_card( $a->id )->balance );
		$this->assertSame( 0.0, (float) $this->ledger->get_card( $b->id )->balance );
		$this->assertNotNull( $this->ledger->find_by_idem( 'spend:77:' . $a->id . ':1' ) );
	}

	public function test_two_checkouts_on_one_card_only_one_wins() {
		$card = $this->issue( 10000, 'issue:manual:a' )['card'];
		$this->assertIsArray( $this->ledger->spend_order( 1, array( $card->id => 8000 ) ) );
		$second = $this->ledger->spend_order( 2, array( $card->id => 8000 ) );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 2000.0, (float) $this->ledger->get_card( $card->id )->balance );
	}

	public function test_disabled_card_cannot_be_spent() {
		$card = $this->issue( 10000, 'issue:manual:a' )['card'];
		$this->ledger->set_status( (int) $card->id, 'disabled' );
		$this->assertInstanceOf( WP_Error::class, $this->ledger->spend_order( 1, array( $card->id => 100 ) ) );
	}

	public function test_conflicting_spend_insert_rolls_back() {
		$card                    = $this->issue( 10000, 'issue:manual:a' )['card'];
		$this->ledger->fail_idem = 'spend:5:' . $card->id . ':1';
		$result                  = $this->ledger->spend_order( 5, array( $card->id => 1000 ) );
		$this->assertSame( 'storedash_gift_card_conflict', $result->get_error_code() );
		$this->assertSame( 10000.0, (float) $this->ledger->get_card( $card->id )->balance );
	}

	public function test_release_restores_and_summary_tracks_attempts() {
		$card = $this->issue( 10000, 'issue:manual:a' )['card'];
		$this->ledger->spend_order( 9, array( $card->id => 8000 ) );

		$release = $this->ledger->move( (int) $card->id, 8000, Card_Ledger::TYPE_RELEASE, 'release:9:' . $card->id . ':1', array( 'order_id' => 9 ) );
		$this->assertTrue( $release['created'] );
		$this->assertSame( 10000.0, (float) $release['card']->balance );

		$again = $this->ledger->move( (int) $card->id, 8000, Card_Ledger::TYPE_RELEASE, 'release:9:' . $card->id . ':1', array( 'order_id' => 9 ) );
		$this->assertFalse( $again['created'], 'idempotent' );
		$this->assertSame( 10000.0, (float) $this->ledger->get_card( $card->id )->balance );

		// Failed → paid again: second attempt uses n=2.
		$this->ledger->spend_order( 9, array( $card->id => 8000 ) );
		$this->assertNotNull( $this->ledger->find_by_idem( 'spend:9:' . $card->id . ':2' ) );

		$summary = $this->ledger->order_summary( 9 );
		$this->assertSame( 16000.0, $summary[ $card->id ]['spent'] );
		$this->assertSame( 8000.0, $summary[ $card->id ]['released'] );
		$this->assertSame( 8000.0, $summary[ $card->id ]['outstanding'] );
		$this->assertSame( 2, $summary[ $card->id ]['spends'] );
	}

	public function test_negative_adjust_is_capped_at_the_balance() {
		$card = $this->issue( 1000, 'issue:manual:a' )['card'];
		$down = $this->ledger->move( (int) $card->id, -5000, Card_Ledger::TYPE_ADJUST, 'adjust:x', array( 'note' => 'fix' ) );
		$this->assertSame( -1000.0, (float) $down['row']->amount );
		$this->assertSame( 0.0, (float) $down['card']->balance );
		$this->assertNull( $this->ledger->move( (int) $card->id, -1, Card_Ledger::TYPE_ADJUST, 'adjust:y' ) );

		$up = $this->ledger->move( (int) $card->id, 250, Card_Ledger::TYPE_ADJUST, 'adjust:z', array( 'note' => 'goodwill' ) );
		$this->assertSame( 250.0, (float) $up['card']->balance );
	}

	public function test_status_changes_keep_balance_and_are_idempotent() {
		$card = $this->issue( 1000, 'issue:manual:a' )['card'];
		$off  = $this->ledger->set_status( (int) $card->id, 'disabled', 'lost' );
		$this->assertTrue( $off['created'] );
		$this->assertSame( 'disable', $off['row']->type );
		$this->assertSame( 0.0, (float) $off['row']->amount );
		$this->assertSame( 1000.0, (float) $off['row']->balance_after );

		$again = $this->ledger->set_status( (int) $card->id, 'disabled' );
		$this->assertFalse( $again['created'] );
		$this->assertSame( (int) $off['row']->id, (int) $again['row']->id );

		$on = $this->ledger->set_status( (int) $card->id, 'active' );
		$this->assertSame( 'enable', $on['row']->type );
		$this->assertSame( 'active', $on['card']->status );
	}

	public function test_reservation_plan() {
		// Nothing held yet: spend the fees.
		$this->assertSame(
			array(
				'release' => array(),
				'spend'   => array( 7 => 8000.0 ),
			),
			Reservation::plan( array( 7 => 8000.0 ), array() )
		);

		// Already holds exactly the fee: no-op (retried checkout).
		$held = array( 7 => array( 'outstanding' => 8000.0 ) );
		$this->assertSame( array( 'release' => array(), 'spend' => array() ), Reservation::plan( array( 7 => 8000.0 ), $held ) );

		// Fee changed: release old hold, spend new amount.
		$this->assertSame(
			array(
				'release' => array( 7 => 8000.0 ),
				'spend'   => array( 7 => 6000.0 ),
			),
			Reservation::plan( array( 7 => 6000.0 ), $held )
		);

		// Card removed from the order: release only.
		$this->assertSame( array( 'release' => array( 7 => 8000.0 ), 'spend' => array() ), Reservation::plan( array(), $held ) );
	}
}
