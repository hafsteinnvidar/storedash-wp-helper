<?php
/**
 * Rule_Matcher tests: highest priority wins, ties → lowest supabase_id, every
 * condition gates, no stacking.
 *
 * @package StoreDash\Tests\Credit
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Engine\Rule_Matcher;
use StoreDash\Credit\Rules_Repository;
use StoreDash\Credit\Settings;

require_once __DIR__ . '/../../inc/Credit/Settings.php';
require_once __DIR__ . '/../../inc/Credit/Rules_Repository.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Rule_Matcher.php';

/**
 * @covers \StoreDash\Credit\Engine\Rule_Matcher
 * @covers \StoreDash\Credit\Rules_Repository::normalize
 */
class Rule_MatcherTest extends TestCase {

	private function rule( string $id, int $priority = 0, array $conditions = array(), array $extra = array() ): array {
		return Rules_Repository::normalize(
			array_merge(
				array(
					'supabase_id' => $id,
					'name'        => $id,
					'enabled'     => true,
					'priority'    => $priority,
					'kind'        => 'percent',
					'value'       => 5,
					'conditions'  => $conditions,
				),
				$extra
			)
		);
	}

	private function ctx( array $overrides = array() ): array {
		return array_merge(
			array(
				'order_total'    => 1000.0,
				'roles'          => array( 'customer' ),
				'category_ids'   => array( 10, 11 ),
				'brand_ids'      => array( 7 ),
				'now'            => strtotime( '2026-09-11 12:00:00 UTC' ),
				'is_first_order' => false,
			),
			$overrides
		);
	}

	public function test_highest_priority_wins_and_ties_go_to_lowest_id(): void {
		$rules = array(
			$this->rule( 'b-rule', 10 ),
			$this->rule( 'a-rule', 10 ),
			$this->rule( 'c-rule', 5 ),
		);
		$this->assertSame( 'a-rule', Rule_Matcher::pick( $rules, $this->ctx() )['supabase_id'] );

		$rules[] = $this->rule( 'z-rule', 99 );
		$this->assertSame( 'z-rule', Rule_Matcher::pick( $rules, $this->ctx() )['supabase_id'] );
	}

	public function test_disabled_rules_are_ignored(): void {
		$rules = array( $this->rule( 'off', 99, array(), array( 'enabled' => false ) ), $this->rule( 'on', 1 ) );
		$this->assertSame( 'on', Rule_Matcher::pick( $rules, $this->ctx() )['supabase_id'] );
		$this->assertNull( Rule_Matcher::pick( array( $rules[0] ), $this->ctx() ) );
	}

	public function test_order_total_bounds(): void {
		$rule = $this->rule( 'r', 0, array( 'min_order_total' => 500, 'max_order_total' => 1000 ) );
		$this->assertTrue( Rule_Matcher::matches( $rule, $this->ctx( array( 'order_total' => 500 ) ) ) );
		$this->assertTrue( Rule_Matcher::matches( $rule, $this->ctx( array( 'order_total' => 1000 ) ) ) );
		$this->assertFalse( Rule_Matcher::matches( $rule, $this->ctx( array( 'order_total' => 499.99 ) ) ) );
		$this->assertFalse( Rule_Matcher::matches( $rule, $this->ctx( array( 'order_total' => 1000.01 ) ) ) );
	}

	public function test_date_window(): void {
		$rule = $this->rule( 'r', 0, array( 'start_date' => '2026-09-01T00:00:00Z', 'end_date' => '2026-09-30T23:59:59Z' ) );
		$this->assertTrue( Rule_Matcher::matches( $rule, $this->ctx() ) );
		$this->assertFalse( Rule_Matcher::matches( $rule, $this->ctx( array( 'now' => strtotime( '2026-08-31 23:00:00 UTC' ) ) ) ) );
		$this->assertFalse( Rule_Matcher::matches( $rule, $this->ctx( array( 'now' => strtotime( '2026-10-01 00:00:00 UTC' ) ) ) ) );
	}

	public function test_customer_roles_require_overlap_and_fail_for_guests(): void {
		$rule = $this->rule( 'r', 0, array( 'customer_roles' => array( 'wholesale', 'vip' ) ) );
		$this->assertFalse( Rule_Matcher::matches( $rule, $this->ctx() ) );
		$this->assertTrue( Rule_Matcher::matches( $rule, $this->ctx( array( 'roles' => array( 'customer', 'vip' ) ) ) ) );
		$this->assertFalse( Rule_Matcher::matches( $rule, $this->ctx( array( 'roles' => array() ) ) ) );
	}

	public function test_category_and_brand_conditions_mean_order_contains(): void {
		$cat = $this->rule( 'c', 0, array( 'category_ids' => array( 11, 99 ) ) );
		$this->assertTrue( Rule_Matcher::matches( $cat, $this->ctx() ) );
		$this->assertFalse( Rule_Matcher::matches( $cat, $this->ctx( array( 'category_ids' => array( 1 ) ) ) ) );

		$brand = $this->rule( 'b', 0, array( 'brand_ids' => array( 8 ) ) );
		$this->assertFalse( Rule_Matcher::matches( $brand, $this->ctx() ) );
		$this->assertTrue( Rule_Matcher::matches( $brand, $this->ctx( array( 'brand_ids' => array( 8, 7 ) ) ) ) );
	}

	public function test_first_order_only_is_evaluated_lazily(): void {
		$rule   = $this->rule( 'r', 0, array( 'first_order_only' => true ) );
		$called = 0;
		$ctx    = $this->ctx(
			array(
				'is_first_order' => function () use ( &$called ) {
					++$called;
					return true;
				},
			)
		);
		$this->assertTrue( Rule_Matcher::matches( $rule, $ctx ) );
		$this->assertSame( 1, $called );
		$this->assertFalse( Rule_Matcher::matches( $rule, $this->ctx( array( 'is_first_order' => false ) ) ) );

		// A rule without the condition never calls the resolver.
		$called = 0;
		Rule_Matcher::matches( $this->rule( 'plain' ), $ctx );
		$this->assertSame( 0, $called );
	}

	public function test_normalize_drops_unusable_rules_and_coerces_types(): void {
		$this->assertNull( Rules_Repository::normalize( array( 'name' => 'no id', 'value' => 5 ) ) );
		$this->assertNull( Rules_Repository::normalize( array( 'supabase_id' => 'x', 'value' => 0 ) ) );

		$rule = Rules_Repository::normalize(
			array(
				'supabase_id' => 'abc',
				'enabled'     => 'false',
				'priority'    => '12',
				'kind'        => 'fixed',
				'value'       => '250',
				'expiry_days' => '0',
				'conditions'  => array(
					'min_order_total' => '100',
					'max_order_total' => null,
					'customer_roles'  => array( 'vip' ),
					'category_ids'    => array( '3', 0 ),
					'start_date'      => '2026-09-01T00:00:00+02:00',
				),
			)
		);
		$this->assertSame( 0, $rule['enabled'] );
		$this->assertSame( 12, $rule['priority'] );
		$this->assertSame( 'fixed', $rule['kind'] );
		$this->assertSame( 250.0, $rule['value'] );
		$this->assertNull( $rule['expiry_days'] );
		$this->assertSame( 100.0, $rule['conditions']['min_order_total'] );
		$this->assertNull( $rule['conditions']['max_order_total'] );
		$this->assertSame( array( 3 ), $rule['conditions']['category_ids'] );
		$this->assertSame( '2026-08-31 22:00:00', $rule['conditions']['start_date'] );
		$this->assertFalse( $rule['conditions']['first_order_only'] );
	}

	public function test_settings_normalize_applies_defaults_bounds_and_lists(): void {
		$s = Settings::normalize(
			array(
				'enabled'             => 'true',
				'earn_basis'          => 'bogus',
				'max_spend_pct'       => 150,
				'min_order_to_spend'  => -5,
				'expiry_days'         => '365',
				'rounding'            => 'nearest',
				'exclude_product_ids' => array( '5', 5, 0, 'x' ),
				'unknown'             => 1,
			)
		);
		$this->assertTrue( $s['enabled'] );
		$this->assertSame( 'subtotal_ex_tax', $s['earn_basis'] );
		$this->assertSame( 100.0, $s['max_spend_pct'] );
		$this->assertSame( 0.0, $s['min_order_to_spend'] );
		$this->assertSame( 365, $s['expiry_days'] );
		$this->assertSame( 'nearest', $s['rounding'] );
		$this->assertSame( array( 5 ), $s['exclude_product_ids'] );
		$this->assertArrayNotHasKey( 'unknown', $s );
		$this->assertNull( Settings::normalize( array( 'expiry_days' => null ) )['expiry_days'] );
	}
}
