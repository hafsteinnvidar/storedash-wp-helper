<?php
/**
 * Delivery_Estimator: cutoff edge, weekend/holiday rolling, business-day
 * counting, rule precedence, hide, loop guard.
 *
 * @package StoreDash\Tests\Checkout
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Checkout\Delivery\Delivery_Estimator;

require_once __DIR__ . '/../../inc/Checkout/Delivery/Delivery_Estimator.php';

/**
 * @covers \StoreDash\Checkout\Delivery\Delivery_Estimator
 */
class Delivery_EstimatorTest extends TestCase {

	/** @var int[] Mon–Fri */
	private $weekdays = array( 1, 2, 3, 4, 5 );

	private function clock( string $datetime ): DateTimeImmutable {
		return new DateTimeImmutable( $datetime, new DateTimeZone( 'Atlantic/Reykjavik' ) );
	}

	private function dates( array $result ): array {
		return array(
			$result['ship_date']->format( 'Y-m-d' ),
			$result['min_date']->format( 'Y-m-d' ),
			$result['max_date']->format( 'Y-m-d' ),
		);
	}

	private function rule( int $min, int $max ): array {
		return array(
			'min_days' => $min,
			'max_days' => $max,
		);
	}

	/* ---------------------------------------------------------------- */
	/* Cutoff                                                           */
	/* ---------------------------------------------------------------- */

	public function test_before_cutoff_ships_today(): void {
		// Tue 2026-10-27 13:59.
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 13:59:00' ), $this->rule( 1, 3 ), '14:00', $this->weekdays, array() );
		$this->assertSame( array( '2026-10-27', '2026-10-28', '2026-10-30' ), $this->dates( $r ) );
	}

	public function test_at_cutoff_rolls_to_next_day(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 14:00:00' ), $this->rule( 1, 3 ), '14:00', $this->weekdays, array() );
		$this->assertSame( array( '2026-10-28', '2026-10-29', '2026-11-02' ), $this->dates( $r ) );
	}

	public function test_after_cutoff_rolls_to_next_day(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 18:30:00' ), $this->rule( 0, 0 ), '14:00', $this->weekdays, array() );
		$this->assertSame( array( '2026-10-28', '2026-10-28', '2026-10-28' ), $this->dates( $r ) );
	}

	public function test_invalid_cutoff_never_rolls(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 23:00:00' ), $this->rule( 0, 0 ), 'garbage', $this->weekdays, array() );
		$this->assertSame( '2026-10-27', $r['ship_date']->format( 'Y-m-d' ) );
	}

	public function test_timezone_is_respected(): void {
		// 13:30 Reykjavik (UTC) == 13:30 UTC; same instant in Helsinki is 15:30 → after cutoff there.
		$reykjavik = $this->clock( '2026-10-27 13:30:00' );
		$helsinki  = $reykjavik->setTimezone( new DateTimeZone( 'Europe/Helsinki' ) );
		$this->assertSame( '2026-10-27', Delivery_Estimator::estimate( $reykjavik, $this->rule( 0, 0 ), '14:00', $this->weekdays, array() )['ship_date']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-10-28', Delivery_Estimator::estimate( $helsinki, $this->rule( 0, 0 ), '14:00', $this->weekdays, array() )['ship_date']->format( 'Y-m-d' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Weekends + holidays                                              */
	/* ---------------------------------------------------------------- */

	public function test_friday_after_cutoff_ships_monday(): void {
		// Fri 2026-10-30 15:00 → Mon 2026-11-02.
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-30 15:00:00' ), $this->rule( 1, 1 ), '14:00', $this->weekdays, array() );
		$this->assertSame( array( '2026-11-02', '2026-11-03', '2026-11-03' ), $this->dates( $r ) );
	}

	public function test_saturday_order_ships_monday(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-31 10:00:00' ), $this->rule( 0, 0 ), '14:00', $this->weekdays, array() );
		$this->assertSame( '2026-11-02', $r['ship_date']->format( 'Y-m-d' ) );
	}

	public function test_holidays_spanning_weekend_are_skipped(): void {
		// Thu 2026-12-24 10:00; holidays Thu 24, Fri 25 (+ weekend 26/27), Mon 28 → ships Tue 29.
		$holidays = array( '2026-12-24', '2026-12-25', '2026-12-28' );
		$r        = Delivery_Estimator::estimate( $this->clock( '2026-12-24 10:00:00' ), $this->rule( 1, 2 ), '14:00', $this->weekdays, $holidays );
		$this->assertSame( array( '2026-12-29', '2026-12-30', '2026-12-31' ), $this->dates( $r ) );
	}

	public function test_holiday_inside_delivery_window_is_not_counted(): void {
		// Ship Mon 2026-11-02; holiday Tue 03 → +1 business day = Wed 04, +2 = Thu 05.
		$r = Delivery_Estimator::estimate( $this->clock( '2026-11-02 09:00:00' ), $this->rule( 1, 2 ), '14:00', $this->weekdays, array( '2026-11-03' ) );
		$this->assertSame( array( '2026-11-02', '2026-11-04', '2026-11-05' ), $this->dates( $r ) );
	}

	public function test_custom_ship_days_only(): void {
		// Ship days Mon + Thu only. Tue 2026-10-27 → ships Thu 29; +1 = Mon 02.
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:00:00' ), $this->rule( 1, 1 ), '14:00', array( 1, 4 ), array() );
		$this->assertSame( array( '2026-10-29', '2026-11-02', '2026-11-02' ), $this->dates( $r ) );
	}

	public function test_empty_ship_days_falls_back_to_weekdays(): void {
		// Sat 2026-10-31 → Mon 02.
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-31 09:00:00' ), $this->rule( 0, 0 ), '14:00', array(), array() );
		$this->assertSame( '2026-11-02', $r['ship_date']->format( 'Y-m-d' ) );
	}

	public function test_garbage_ship_days_fall_back_to_weekdays(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-31 09:00:00' ), $this->rule( 0, 0 ), '14:00', array( 0, 8, 'x' ), array() );
		$this->assertSame( '2026-11-02', $r['ship_date']->format( 'Y-m-d' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Day counts                                                       */
	/* ---------------------------------------------------------------- */

	public function test_min_equals_max_is_single_date(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:00:00' ), $this->rule( 2, 2 ), '14:00', $this->weekdays, array() );
		$this->assertSame( $r['min_date']->format( 'Y-m-d' ), $r['max_date']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-10-29', $r['max_date']->format( 'Y-m-d' ) );
	}

	public function test_zero_days_is_ship_date(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:00:00' ), $this->rule( 0, 0 ), '14:00', $this->weekdays, array() );
		$this->assertSame( array( '2026-10-27', '2026-10-27', '2026-10-27' ), $this->dates( $r ) );
	}

	public function test_max_below_min_is_lifted_to_min(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:00:00' ), $this->rule( 3, 1 ), '14:00', $this->weekdays, array() );
		$this->assertSame( '2026-10-30', $r['min_date']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-10-30', $r['max_date']->format( 'Y-m-d' ) );
	}

	public function test_days_are_capped_at_sixty(): void {
		$r      = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:00:00' ), $this->rule( 0, 500 ), '14:00', $this->weekdays, array() );
		$capped = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:00:00' ), $this->rule( 0, 60 ), '14:00', $this->weekdays, array() );
		$this->assertSame( $capped['max_date']->format( 'Y-m-d' ), $r['max_date']->format( 'Y-m-d' ) );
	}

	public function test_results_are_midnight_in_input_timezone(): void {
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:17:42' ), $this->rule( 1, 1 ), '14:00', $this->weekdays, array() );
		$this->assertSame( '00:00:00', $r['min_date']->format( 'H:i:s' ) );
		$this->assertSame( 'Atlantic/Reykjavik', $r['min_date']->getTimezone()->getName() );
	}

	/* ---------------------------------------------------------------- */
	/* Loop guard                                                       */
	/* ---------------------------------------------------------------- */

	public function test_all_days_holidays_degrades_to_start_date(): void {
		$holidays = array();
		$day      = $this->clock( '2026-10-27 00:00:00' );
		for ( $i = 0; $i < 200; $i++ ) {
			$holidays[] = $day->format( 'Y-m-d' );
			$day        = $day->modify( '+1 day' );
		}
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:00:00' ), $this->rule( 1, 3 ), '14:00', $this->weekdays, $holidays );
		$this->assertSame( array( '2026-10-27', '2026-10-27', '2026-10-27' ), $this->dates( $r ) );
	}

	public function test_long_holiday_block_in_window_is_bounded(): void {
		// Ship date Tue 27 is clear; every following day is a holiday → delivery walk stops at the guard.
		$holidays = array();
		$day      = $this->clock( '2026-10-28 00:00:00' );
		for ( $i = 0; $i < 200; $i++ ) {
			$holidays[] = $day->format( 'Y-m-d' );
			$day        = $day->modify( '+1 day' );
		}
		$r = Delivery_Estimator::estimate( $this->clock( '2026-10-27 09:00:00' ), $this->rule( 1, 1 ), '14:00', $this->weekdays, $holidays );
		$this->assertSame( '2026-10-27', $r['ship_date']->format( 'Y-m-d' ) );
		$this->assertLessThanOrEqual( Delivery_Estimator::MAX_ITERATIONS, (int) $r['ship_date']->diff( $r['max_date'] )->days );
	}

	/* ---------------------------------------------------------------- */
	/* resolve_rule                                                     */
	/* ---------------------------------------------------------------- */

	private function rules(): array {
		return array(
			array( 'rate_id' => 'flat_rate', 'min_days' => 5, 'max_days' => 7, 'kind' => 'delivery' ),
			array( 'rate_id' => 'flat_rate:3', 'min_days' => 1, 'max_days' => 2, 'kind' => 'delivery' ),
			array( 'rate_id' => 'local_pickup:9', 'min_days' => 0, 'max_days' => 0, 'kind' => 'pickup' ),
			array( 'rate_id' => 'free_shipping:4', 'min_days' => 0, 'max_days' => 0, 'kind' => 'hide' ),
		);
	}

	public function test_exact_rate_id_wins_over_prefix(): void {
		$rule = Delivery_Estimator::resolve_rule( 'flat_rate:3', $this->rules(), $this->rule( 9, 9 ) );
		$this->assertSame( array( 'min_days' => 1, 'max_days' => 2, 'kind' => 'delivery' ), $rule );
	}

	public function test_prefix_rule_matches_other_instances(): void {
		$rule = Delivery_Estimator::resolve_rule( 'flat_rate:12', $this->rules(), $this->rule( 9, 9 ) );
		$this->assertSame( array( 'min_days' => 5, 'max_days' => 7, 'kind' => 'delivery' ), $rule );
	}

	public function test_exact_match_wins_even_when_prefix_is_listed_first(): void {
		$rules = array_reverse( $this->rules() );
		$rule  = Delivery_Estimator::resolve_rule( 'flat_rate:3', $rules, $this->rule( 9, 9 ) );
		$this->assertSame( 1, $rule['min_days'] );
	}

	public function test_unknown_rate_falls_back_to_default_delivery(): void {
		$rule = Delivery_Estimator::resolve_rule( 'table_rate:2', $this->rules(), $this->rule( 9, 11 ) );
		$this->assertSame( array( 'min_days' => 9, 'max_days' => 11, 'kind' => 'delivery' ), $rule );
	}

	public function test_pickup_kind_is_kept(): void {
		$rule = Delivery_Estimator::resolve_rule( 'local_pickup:9', $this->rules(), $this->rule( 9, 9 ) );
		$this->assertSame( 'pickup', $rule['kind'] );
	}

	public function test_hide_returns_null(): void {
		$this->assertNull( Delivery_Estimator::resolve_rule( 'free_shipping:4', $this->rules(), $this->rule( 9, 9 ) ) );
	}

	public function test_unknown_kind_is_treated_as_delivery(): void {
		$rules = array( array( 'rate_id' => 'x:1', 'min_days' => 1, 'max_days' => 1, 'kind' => 'teleport' ) );
		$this->assertSame( 'delivery', Delivery_Estimator::resolve_rule( 'x:1', $rules, array() )['kind'] );
	}

	public function test_malformed_rules_are_ignored(): void {
		$rules = array( 'nope', array( 'min_days' => 1 ), array( 'rate_id' => 7 ) );
		$rule  = Delivery_Estimator::resolve_rule( 'flat_rate:1', $rules, $this->rule( 2, 4 ) );
		$this->assertSame( array( 'min_days' => 2, 'max_days' => 4, 'kind' => 'delivery' ), $rule );
	}

	public function test_empty_default_yields_zero_days(): void {
		$rule = Delivery_Estimator::resolve_rule( 'flat_rate:1', array(), array() );
		$this->assertSame( array( 'min_days' => 0, 'max_days' => 0, 'kind' => 'delivery' ), $rule );
	}
}
