<?php
/**
 * Ledger tests: FIFO consumption, release, reverse, expiry and the
 * idempotency guarantees the cross-repo contract relies on.
 *
 * Runs the real Ledger logic against an in-memory storage double (the
 * protected db_* primitives are overridden), so transactions are modelled as
 * plain snapshots and no MySQL is needed.
 *
 * @package StoreDash\Tests\Credit
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Ledger;

require_once __DIR__ . '/../../inc/Credit/Money.php';
require_once __DIR__ . '/../../inc/Credit/Ledger.php';

/**
 * In-memory ledger storage.
 */
class Memory_Ledger extends Ledger {

	/** @var array id => row (object) */
	public $rows = array();
	/** @var int */
	public $next_id = 1;
	/** @var string Fake "now" (UTC MySQL). */
	public $now = '2026-09-11 12:00:00';
	/** @var array|null Snapshot taken at db_begin() for rollback. */
	private $snapshot = null;
	/** @var int */
	public $commits = 0;
	/** @var int */
	public $rollbacks = 0;

	public function __construct() {
		parent::__construct( 'ISK' );
	}

	protected function now(): string {
		return $this->now;
	}

	protected function db_find_by_idem( string $idem_key ) {
		foreach ( $this->rows as $row ) {
			if ( $row->idem_key === $idem_key ) {
				return clone $row;
			}
		}
		return null;
	}

	protected function db_get( int $id, bool $lock = false ) {
		return isset( $this->rows[ $id ] ) ? clone $this->rows[ $id ] : null;
	}

	protected function db_insert( array $row ): int {
		foreach ( $this->rows as $existing ) {
			if ( $existing->idem_key === $row['idem_key'] ) {
				return 0; // UNIQUE violation.
			}
		}
		$id                = $this->next_id++;
		$row['id']         = $id;
		$this->rows[ $id ] = (object) $row;
		return $id;
	}

	protected function db_update_remaining( int $id, float $remaining ): void {
		$this->rows[ $id ]->remaining = round( $remaining, 4 );
	}

	protected function db_update_meta( int $id, array $meta ): void {
		$this->rows[ $id ]->meta = json_encode( $meta );
	}

	private function is_open( $row, string $now ): bool {
		return null !== $row->remaining && $row->remaining > 0 && ( empty( $row->expires_at ) || $row->expires_at > $now );
	}

	protected function db_open_rows( string $customer_key, string $now, bool $lock ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( $row->customer_key === $customer_key && $this->is_open( $row, $now ) ) {
				$out[] = clone $row;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$an = empty( $a->expires_at );
				$bn = empty( $b->expires_at );
				if ( $an !== $bn ) {
					return $an ? 1 : -1; // NULLS LAST.
				}
				if ( ! $an && $a->expires_at !== $b->expires_at ) {
					return strcmp( $a->expires_at, $b->expires_at );
				}
				if ( $a->created_at !== $b->created_at ) {
					return strcmp( $a->created_at, $b->created_at );
				}
				return $a->id <=> $b->id;
			}
		);
		return $out;
	}

	protected function db_sum_open( string $customer_key, string $now ): float {
		$sum = 0.0;
		foreach ( $this->db_open_rows( $customer_key, $now, false ) as $row ) {
			$sum += (float) $row->remaining;
		}
		return $sum;
	}

	protected function db_due_rows( string $now ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( null !== $row->remaining && $row->remaining > 0 && ! empty( $row->expires_at ) && $row->expires_at <= $now ) {
				$out[] = clone $row;
			}
		}
		return $out;
	}

	protected function db_rows_for_customer( string $customer_key, int $limit ): array {
		$out = array();
		foreach ( array_reverse( $this->rows, true ) as $row ) {
			if ( $row->customer_key === $customer_key ) {
				$out[] = clone $row;
			}
		}
		return array_slice( $out, 0, $limit );
	}

	protected function db_rows_since( string $since_utc, int $offset, int $limit ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( '' === $since_utc || $row->created_at >= $since_utc ) {
				$out[] = clone $row;
			}
		}
		return array_slice( $out, $offset, $limit );
	}

	protected function db_count_since( string $since_utc ): int {
		return count( $this->db_rows_since( $since_utc, 0, PHP_INT_MAX ) );
	}

	protected function db_spend_rows_for_order( int $order_id ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( (int) $row->order_id === $order_id && Ledger::TYPE_SPEND === $row->type ) {
				$out[] = clone $row;
			}
		}
		return $out;
	}

	protected function db_sum_refund_credit( int $order_id ): float {
		$sum = 0.0;
		foreach ( $this->rows as $row ) {
			if ( (int) $row->order_id === $order_id && Ledger::TYPE_REVERSE === $row->type && null !== $row->remaining && 0 === strpos( $row->idem_key, 'refund_credit:' ) ) {
				$sum += (float) $row->amount;
			}
		}
		return $sum;
	}

	protected function db_begin(): void {
		$this->snapshot = array_map(
			static function ( $row ) {
				return clone $row;
			},
			$this->rows
		);
	}

	protected function db_commit(): void {
		++$this->commits;
		$this->snapshot = null;
	}

	protected function db_rollback(): void {
		++$this->rollbacks;
		if ( null !== $this->snapshot ) {
			$this->rows     = $this->snapshot;
			$this->snapshot = null;
		}
	}
}

/**
 * @covers \StoreDash\Credit\Ledger
 */
class LedgerTest extends TestCase {

	/** @var Memory_Ledger */
	private $ledger;

	protected function setUp(): void {
		parent::setUp();
		$this->ledger = new Memory_Ledger();
	}

	/**
	 * Add an earn row.
	 *
	 * @return object Row.
	 */
	private function earn( string $idem, float $amount, $expires_at = null, ?string $created_at = null, string $key = 'a@b.is' ) {
		if ( null !== $created_at ) {
			$this->ledger->now = $created_at;
		}
		$result            = $this->ledger->add_credit(
			array(
				'customer_key' => $key,
				'amount'       => $amount,
				'idem_key'     => $idem,
				'order_id'     => 100,
				'expires_at'   => $expires_at,
			)
		);
		$this->ledger->now = '2026-09-11 12:00:00';
		$this->assertNotInstanceOf( WP_Error::class, $result );
		return $result['row'];
	}

	// ── add_credit ─────────────────────────────────────────────────────

	public function test_add_credit_sets_remaining_and_is_idempotent(): void {
		$first  = $this->ledger->add_credit(
			array(
				'customer_key' => 'a@b.is',
				'amount'       => 100,
				'idem_key'     => 'earn:1',
			)
		);
		$second = $this->ledger->add_credit(
			array(
				'customer_key' => 'a@b.is',
				'amount'       => 999,
				'idem_key'     => 'earn:1',
			)
		);

		$this->assertTrue( $first['created'] );
		$this->assertFalse( $second['created'] );
		$this->assertSame( $first['row']->id, $second['row']->id );
		$this->assertSame( 100.0, (float) $first['row']->remaining );
		$this->assertSame( 100.0, $this->ledger->balance( 'a@b.is' ) );
		$this->assertCount( 1, $this->ledger->rows );
	}

	public function test_add_credit_rejects_non_positive_amounts(): void {
		$result = $this->ledger->add_credit(
			array(
				'customer_key' => 'a@b.is',
				'amount'       => 0,
				'idem_key'     => 'earn:0',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_credit_invalid_amount', $result->get_error_code() );
	}

	// ── FIFO ───────────────────────────────────────────────────────────

	public function test_allocate_fifo_is_pure_and_partial(): void {
		$rows = array(
			(object) array( 'id' => 1, 'remaining' => 30 ),
			(object) array( 'id' => 2, 'remaining' => 50 ),
			(object) array( 'id' => 3, 'remaining' => 40 ),
		);
		$this->assertSame( array( 1 => 30.0, 2 => 45.0 ), Ledger::allocate_fifo( $rows, 75 ) );
		$this->assertSame( array( 1 => 10.0 ), Ledger::allocate_fifo( $rows, 10 ) );
		$this->assertSame( array(), Ledger::allocate_fifo( $rows, 0 ) );
	}

	public function test_consume_takes_soonest_expiry_first_then_oldest_nulls_last(): void {
		$never   = $this->earn( 'earn:never', 100, null, '2026-01-01 00:00:00' );
		$late    = $this->earn( 'earn:late', 100, '2027-12-01 00:00:00', '2026-02-01 00:00:00' );
		$soon    = $this->earn( 'earn:soon', 100, '2026-12-01 00:00:00', '2026-03-01 00:00:00' );
		$expired = $this->earn( 'earn:expired', 100, '2026-01-01 00:00:00', '2025-01-01 00:00:00' );

		$this->assertSame( 300.0, $this->ledger->balance( 'a@b.is' ), 'expired row is excluded from balance without any cron' );

		$result = $this->ledger->consume(
			'a@b.is',
			150,
			array(
				'idem_key' => 'spend:9',
				'order_id' => 9,
			)
		);

		$this->assertTrue( $result['created'] );
		$this->assertSame( -150.0, (float) $result['row']->amount );
		$this->assertNull( $result['row']->remaining );
		$this->assertSame(
			array(
				array( 'id' => $soon->id, 'remaining' => 0.0 ),
				array( 'id' => $late->id, 'remaining' => 50.0 ),
			),
			$result['affected']
		);
		$this->assertSame( 100.0, (float) $this->ledger->rows[ $never->id ]->remaining, 'never-expiring credit is consumed last' );
		$this->assertSame( 100.0, (float) $this->ledger->rows[ $expired->id ]->remaining, 'expired credit is never consumed' );
		$this->assertSame( 150.0, $this->ledger->balance( 'a@b.is' ) );

		$meta = json_decode( $result['row']->meta, true );
		$this->assertSame( array( $soon->id => 100.0, $late->id => 50.0 ), array_map( 'floatval', $meta['allocated'] ) );
		$this->assertSame( 1, $this->ledger->commits );
	}

	public function test_consume_is_idempotent_by_key(): void {
		$this->earn( 'earn:1', 100 );
		$first  = $this->ledger->consume( 'a@b.is', 40, array( 'idem_key' => 'spend:1' ) );
		$second = $this->ledger->consume( 'a@b.is', 40, array( 'idem_key' => 'spend:1' ) );

		$this->assertTrue( $first['created'] );
		$this->assertFalse( $second['created'] );
		$this->assertSame( $first['row']->id, $second['row']->id );
		$this->assertSame( 60.0, $this->ledger->balance( 'a@b.is' ), 'replay must not consume twice' );
		$this->assertSame( array( array( 'id' => 1, 'remaining' => 60.0 ) ), $second['affected'] );
	}

	public function test_consume_rejects_insufficient_balance_and_rolls_back(): void {
		$this->earn( 'earn:1', 100 );
		$result = $this->ledger->consume( 'a@b.is', 100.01, array( 'idem_key' => 'spend:2' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_credit_insufficient', $result->get_error_code() );
		$this->assertSame( 100.0, $this->ledger->balance( 'a@b.is' ) );
		$this->assertCount( 1, $this->ledger->rows );
		$this->assertSame( 1, $this->ledger->rollbacks );
	}

	public function test_consume_never_crosses_customers(): void {
		$this->earn( 'earn:a', 100, null, null, 'a@b.is' );
		$this->earn( 'earn:c', 100, null, null, 'c@d.is' );

		$result = $this->ledger->consume( 'a@b.is', 150, array( 'idem_key' => 'spend:x' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 100.0, $this->ledger->balance( 'c@d.is' ) );
	}

	public function test_adjust_type_is_preserved(): void {
		$this->earn( 'earn:1', 100 );
		$result = $this->ledger->consume(
			'a@b.is',
			10,
			array(
				'idem_key' => 'adjust:k',
				'type'     => Ledger::TYPE_ADJUST,
				'note'     => 'oops',
			)
		);
		$this->assertSame( Ledger::TYPE_ADJUST, $result['row']->type );
		$this->assertSame( 'oops', $result['row']->note );
	}

	// ── release ────────────────────────────────────────────────────────

	public function test_release_restores_exactly_the_consumed_rows_and_is_idempotent(): void {
		$a = $this->earn( 'earn:a', 30, '2026-12-01 00:00:00', '2026-01-01 00:00:00' );
		$b = $this->earn( 'earn:b', 50, null, '2026-02-01 00:00:00' );

		$this->ledger->consume(
			'a@b.is',
			60,
			array(
				'idem_key' => 'spend:77',
				'order_id' => 77,
			)
		);
		$this->assertSame( 20.0, $this->ledger->balance( 'a@b.is' ) );

		$first = $this->ledger->release_order( 77, 'release:77' );
		$this->assertTrue( $first['created'] );
		$this->assertSame( Ledger::TYPE_REVERSE, $first['row']->type );
		$this->assertSame( 60.0, (float) $first['row']->amount );
		$this->assertNull( $first['row']->remaining, 'release row holds no credit itself' );
		$this->assertSame(
			array(
				array( 'id' => $a->id, 'remaining' => 30.0 ),
				array( 'id' => $b->id, 'remaining' => 50.0 ),
			),
			$first['affected']
		);
		$this->assertSame( 80.0, $this->ledger->balance( 'a@b.is' ) );

		$second = $this->ledger->release_order( 77, 'release:77' );
		$this->assertFalse( $second['created'] );
		$this->assertSame( 80.0, $this->ledger->balance( 'a@b.is' ), 'replay must not restore twice' );

		// A third call with a NEW key finds nothing left to release (spend marked released).
		$this->assertNull( $this->ledger->release_order( 77, 'release:77:2' ) );
	}

	public function test_release_does_not_resurrect_credit_that_expired_meanwhile(): void {
		$a = $this->earn( 'earn:a', 30, '2026-09-11 13:00:00' ); // expires in one hour
		$this->ledger->consume(
			'a@b.is',
			30,
			array(
				'idem_key' => 'spend:5',
				'order_id' => 5,
			)
		);

		$this->ledger->now = '2026-09-12 00:00:00'; // past expiry
		$result            = $this->ledger->release_order( 5, 'release:5' );

		$this->assertTrue( $result['created'] );
		$this->assertSame( array(), $result['affected'] );
		$this->assertSame( 0.0, (float) $this->ledger->rows[ $a->id ]->remaining );
		$this->assertSame( 0.0, $this->ledger->balance( 'a@b.is' ) );
	}

	public function test_release_without_spend_returns_null(): void {
		$this->assertNull( $this->ledger->release_order( 404, 'release:404' ) );
	}

	// ── reverse_earn ───────────────────────────────────────────────────

	public function test_reverse_earn_is_capped_at_remaining_and_idempotent(): void {
		$earn = $this->earn( 'earn:1', 100 );
		$this->ledger->consume( 'a@b.is', 70, array( 'idem_key' => 'spend:1' ) );

		$result = $this->ledger->reverse_earn( $earn->id, 50, 'reverse:r1', array( 'refund_id' => 900 ) );
		$this->assertTrue( $result['created'] );
		$this->assertSame( -30.0, (float) $result['row']->amount, 'only the unspent 30 can be clawed back' );
		$this->assertSame( 900, (int) $result['row']->refund_id );
		$this->assertSame( array( array( 'id' => $earn->id, 'remaining' => 0.0 ) ), $result['affected'] );
		$this->assertSame( 0.0, $this->ledger->balance( 'a@b.is' ) );

		$again = $this->ledger->reverse_earn( $earn->id, 50, 'reverse:r1' );
		$this->assertFalse( $again['created'] );
		$this->assertCount( 3, $this->ledger->rows );

		$this->assertNull( $this->ledger->reverse_earn( $earn->id, 50, 'reverse:r2' ), 'nothing left → null, no row' );
	}

	// ── expire_due ─────────────────────────────────────────────────────

	public function test_expire_due_writes_one_row_per_earn_and_is_idempotent(): void {
		$old = $this->earn( 'earn:old', 40, '2026-09-01 00:00:00' );
		$new = $this->earn( 'earn:new', 60, '2027-09-01 00:00:00' );

		$this->assertSame( 60.0, $this->ledger->balance( 'a@b.is' ), 'balance ignores expired credit before any cron run' );

		$results = $this->ledger->expire_due();
		$this->assertCount( 1, $results );
		$this->assertSame( Ledger::TYPE_EXPIRE, $results[0]['row']->type );
		$this->assertSame( -40.0, (float) $results[0]['row']->amount );
		$this->assertSame( 'expire:' . $old->id, $results[0]['row']->idem_key );
		$this->assertSame( array( array( 'id' => $old->id, 'remaining' => 0.0 ) ), $results[0]['affected'] );
		$this->assertSame( 0.0, (float) $this->ledger->rows[ $old->id ]->remaining );
		$this->assertSame( 60.0, (float) $this->ledger->rows[ $new->id ]->remaining );

		$this->assertSame( array(), $this->ledger->expire_due(), 'second run finds nothing' );
		$this->assertCount( 3, $this->ledger->rows );
	}

	public function test_expire_due_self_heals_when_expire_row_exists_but_remaining_was_not_zeroed(): void {
		$old = $this->earn( 'earn:old', 40, '2026-09-01 00:00:00' );
		$this->ledger->expire_due();
		$this->ledger->rows[ $old->id ]->remaining = 40.0; // simulate a crash between insert and update

		$this->assertSame( array(), $this->ledger->expire_due() );
		$this->assertSame( 0.0, (float) $this->ledger->rows[ $old->id ]->remaining );
		$this->assertCount( 2, $this->ledger->rows, 'no duplicate expire row' );
	}

	// ── reads ──────────────────────────────────────────────────────────

	public function test_expiring_next_and_feed(): void {
		$this->earn( 'earn:a', 10, null, '2026-01-01 00:00:00' );
		$this->earn( 'earn:b', 20, '2026-11-01 00:00:00', '2026-02-01 00:00:00' );
		$this->earn( 'earn:c', 30, '2026-10-01 00:00:00', '2026-03-01 00:00:00' );

		$this->assertSame(
			array(
				'amount' => 30.0,
				'at'     => '2026-10-01 00:00:00',
			),
			$this->ledger->expiring_next( 'a@b.is' )
		);

		$feed = $this->ledger->feed( '2026-02-01 00:00:00', 1, 1 );
		$this->assertSame( 2, $feed['total'] );
		$this->assertCount( 1, $feed['rows'] );
		$this->assertSame( 'earn:b', $feed['rows'][0]->idem_key );

		$this->assertSame( 0.0, $this->ledger->balance( '' ) );
		$this->assertSame( 'a@b.is', Ledger::customer_key( '  A@B.is ' ) );
	}
}
