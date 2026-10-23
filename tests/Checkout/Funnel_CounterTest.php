<?php
/**
 * Funnel_Counter: dedupe per session/day, key cap, allowlists, bot skip, variant key.
 *
 * @package StoreDash\Tests\Checkout
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Checkout\Funnel\Funnel_Counter;

require_once __DIR__ . '/checkout-stubs.php';

/**
 * @covers \StoreDash\Checkout\Funnel\Funnel_Counter
 */
class Funnel_CounterTest extends TestCase {

	/** @var mixed Transient value. */
	private $store_value = false;
	/** @var array Session storage. */
	private $session = array();
	/** @var string Clock. */
	private $today = '2026-10-23';
	/** @var string UA. */
	private $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';

	protected function setUp(): void {
		$this->store_value = false;
		$this->session     = array();
		$this->today       = '2026-10-23';
		$this->ua          = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';
	}

	private function counter(): Funnel_Counter {
		$self = $this;
		return new Funnel_Counter(
			function ( $op, $value = null ) use ( $self ) {
				if ( 'get' === $op ) {
					return $self->store_value;
				}
				if ( 'set' === $op ) {
					$self->store_value = $value;
					return true;
				}
				$self->store_value = false;
				return true;
			},
			function () use ( $self ) {
				return $self->today;
			},
			function ( $key ) use ( $self ) {
				return $self->session[ $key ] ?? null;
			},
			function ( $key, $value ) use ( $self ) {
				$self->session[ $key ] = $value;
			},
			function () use ( $self ) {
				return $self->ua;
			}
		);
	}

	public function test_first_hit_counts_and_marks_session(): void {
		$c = $this->counter();
		$this->assertTrue( $c->record( 'cart_view', 'mobile' ) );
		$this->assertSame( array( '2026-10-23|cart_view|mobile|' => 1 ), $this->store_value );
		$this->assertSame( array( 'cart_view' => '2026-10-23' ), $this->session[ Funnel_Counter::SESSION_KEY ] );
	}

	public function test_repeat_same_session_same_day_does_not_count(): void {
		$c = $this->counter();
		$this->assertTrue( $c->record( 'cart_view', 'mobile' ) );
		$this->assertFalse( $c->record( 'cart_view', 'mobile' ) );
		$this->assertFalse( $c->record( 'cart_view', 'desktop' ) ); // Dedupe is per step, not per device.
		$this->assertSame( array( '2026-10-23|cart_view|mobile|' => 1 ), $this->store_value );
	}

	public function test_other_steps_count_independently(): void {
		$c = $this->counter();
		$this->assertTrue( $c->record( 'cart_view', 'desktop' ) );
		$this->assertTrue( $c->record( 'checkout_view', 'desktop' ) );
		$this->assertTrue( $c->record( 'order_placed', 'desktop' ) );
		$this->assertCount( 3, $this->store_value );
	}

	public function test_counts_again_on_a_new_day(): void {
		$c = $this->counter();
		$this->assertTrue( $c->record( 'checkout_view', 'desktop' ) );
		$this->today = '2026-10-24';
		$this->assertTrue( $c->record( 'checkout_view', 'desktop' ) );
		$this->assertSame(
			array(
				'2026-10-23|checkout_view|desktop|' => 1,
				'2026-10-24|checkout_view|desktop|' => 1,
			),
			$this->store_value
		);
		$this->assertSame( '2026-10-24', $this->session[ Funnel_Counter::SESSION_KEY ]['checkout_view'] );
	}

	public function test_sessions_sum_in_the_same_key(): void {
		$c = $this->counter();
		$this->assertTrue( $c->record( 'cart_view', 'mobile' ) );
		$this->session = array(); // Second shopper.
		$this->assertTrue( $c->record( 'cart_view', 'mobile' ) );
		$this->assertSame( array( '2026-10-23|cart_view|mobile|' => 2 ), $this->store_value );
	}

	public function test_unknown_step_or_device_is_rejected(): void {
		$c = $this->counter();
		$this->assertFalse( $c->record( 'purchase', 'mobile' ) );
		$this->assertFalse( $c->record( 'cart_view', 'tablet' ) );
		$this->assertFalse( $this->store_value );
		$this->assertSame( array(), $this->session );
	}

	public function test_bot_user_agent_is_skipped(): void {
		$this->ua = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';
		$c        = $this->counter();
		$this->assertFalse( $c->record( 'cart_view', 'desktop' ) );
		$this->assertFalse( $this->store_value );
		$this->assertSame( array(), $this->session );
	}

	public function test_empty_user_agent_is_not_a_bot(): void {
		$this->ua = '';
		$this->assertTrue( $this->counter()->record( 'cart_view', 'unknown' ) );
	}

	public function test_variant_is_sanitized_into_the_key(): void {
		$c = $this->counter();
		$this->assertTrue( $c->record( 'bump_view', 'desktop', ' Bump-A/1 ' ) );
		$this->assertSame( array( '2026-10-23|bump_view|desktop|bump-a1' => 1 ), $this->store_value );
	}

	public function test_key_cap_drops_oldest_date_keys(): void {
		$counts = array();
		for ( $i = 0; $i < Funnel_Counter::MAX_KEYS; $i++ ) {
			$day            = sprintf( '2026-%02d-%02d', 1 + intdiv( $i, 28 ), 1 + ( $i % 28 ) );
			$key            = Funnel_Counter::key( $day, 'cart_view', 'desktop', 'v' . $i );
			$counts[ $key ] = 1;
		}
		$this->assertCount( Funnel_Counter::MAX_KEYS, $counts );

		$oldest = Funnel_Counter::key( '2026-01-01', 'cart_view', 'desktop', 'v0' );
		$newest = Funnel_Counter::key( '2026-12-31', 'order_placed', 'mobile', '' );
		$after  = Funnel_Counter::add_count( $counts, $newest );

		$this->assertCount( Funnel_Counter::MAX_KEYS, $after );
		$this->assertArrayNotHasKey( $oldest, $after );
		$this->assertSame( 1, $after[ $newest ] );

		// An existing key increments without evicting anything.
		$again = Funnel_Counter::add_count( $after, $newest );
		$this->assertCount( Funnel_Counter::MAX_KEYS, $again );
		$this->assertSame( 2, $again[ $newest ] );
	}

	public function test_rows_and_merge_rows_round_trip(): void {
		$counts = array(
			'2026-10-23|checkout_view|desktop|' => 2,
			'2026-10-22|cart_view|mobile|a'     => 5,
			'bad-key'                           => 9,
			'2026-10-23|order_placed|mobile|'   => 0,
		);
		$rows   = Funnel_Counter::rows( $counts );
		$this->assertSame(
			array(
				array(
					'date'     => '2026-10-22',
					'step'     => 'cart_view',
					'device'   => 'mobile',
					'variant'  => 'a',
					'sessions' => 5,
				),
				array(
					'date'     => '2026-10-23',
					'step'     => 'checkout_view',
					'device'   => 'desktop',
					'variant'  => '',
					'sessions' => 2,
				),
			),
			$rows
		);

		$merged = Funnel_Counter::merge_rows( array( '2026-10-23|checkout_view|desktop|' => 1 ), $rows );
		$this->assertSame( 3, $merged['2026-10-23|checkout_view|desktop|'] );
		$this->assertSame( 5, $merged['2026-10-22|cart_view|mobile|a'] );
	}
}
