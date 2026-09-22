<?php
/**
 * Earn basis / amount, fee cap math, product eligibility, and the
 * money serializers used by the REST + Store API contracts.
 *
 * @package StoreDash\Tests\Credit
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Blocks\Store_API_Integration;
use StoreDash\Credit\Engine\Earn_Calculator;
use StoreDash\Credit\Engine\Eligibility;
use StoreDash\Credit\Engine\Fee_Calculator;
use StoreDash\Credit\Engine\Refund_Handler;
use StoreDash\Credit\Money;

require_once __DIR__ . '/../../inc/Credit/Money.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Earn_Calculator.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Fee_Calculator.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Eligibility.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Refund_Handler.php';
require_once __DIR__ . '/../../inc/Credit/Blocks/Store_API_Integration.php';

/**
 * @covers \StoreDash\Credit\Engine\Earn_Calculator
 * @covers \StoreDash\Credit\Engine\Fee_Calculator
 * @covers \StoreDash\Credit\Engine\Eligibility
 * @covers \StoreDash\Credit\Money
 * @covers \StoreDash\Credit\Blocks\Store_API_Integration::format_state
 * @covers \StoreDash\Credit\Engine\Refund_Handler::share
 */
class Earn_And_Fee_MathTest extends TestCase {

	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'earn_basis'               => 'subtotal_ex_tax',
				'earn_on_sale_items'       => false,
				'earn_on_credit_paid_part' => false,
				'spend_on_sale_items'      => false,
				'spend_covers_shipping'    => false,
				'max_spend_pct'            => null,
				'min_order_to_spend'       => null,
				'expiry_days'              => null,
				'rounding'                 => 'down',
				'exclude_product_ids'      => array(),
				'exclude_category_ids'     => array(),
				'exclude_tag_ids'          => array(),
				'exclude_brand_ids'        => array(),
			),
			$overrides
		);
	}

	private function lines(): array {
		return array(
			array( 'total' => 1000, 'tax' => 240, 'excluded' => false, 'on_sale' => false ),
			array( 'total' => 500, 'tax' => 120, 'excluded' => false, 'on_sale' => true ),
			array( 'total' => 300, 'tax' => 72, 'excluded' => true, 'on_sale' => false ),
		);
	}

	// ── earn basis ─────────────────────────────────────────────────────

	public function test_basis_modes_and_exclusions(): void {
		$lines = $this->lines();

		$this->assertSame( 1000.0, Earn_Calculator::basis( $lines, $this->settings() ), 'sale + excluded lines dropped, ex tax' );
		$this->assertSame( 1500.0, Earn_Calculator::basis( $lines, $this->settings( array( 'earn_on_sale_items' => true ) ) ) );
		$this->assertSame( 1240.0, Earn_Calculator::basis( $lines, $this->settings( array( 'earn_basis' => 'subtotal_inc_tax' ) ) ) );
		$this->assertSame( 1240.0, Earn_Calculator::basis( $lines, $this->settings( array( 'earn_basis' => 'subtotal_inc_tax' ) ), 0.0, 990.0 ), 'shipping ignored for subtotal bases' );
		$this->assertSame( 2230.0, Earn_Calculator::basis( $lines, $this->settings( array( 'earn_basis' => 'total' ) ), 0.0, 990.0 ) );
	}

	public function test_credit_paid_part_is_subtracted_unless_allowed(): void {
		$lines = $this->lines();
		$this->assertSame( 700.0, Earn_Calculator::basis( $lines, $this->settings(), 300.0 ) );
		$this->assertSame( 1000.0, Earn_Calculator::basis( $lines, $this->settings( array( 'earn_on_credit_paid_part' => true ) ), 300.0 ) );
		$this->assertSame( 0.0, Earn_Calculator::basis( $lines, $this->settings(), 5000.0 ), 'never negative' );
	}

	public function test_amount_percent_fixed_and_rounding(): void {
		$pct = array( 'kind' => 'percent', 'value' => 7.5 );
		$fix = array( 'kind' => 'fixed', 'value' => 250 );

		$this->assertSame( 92.0, Earn_Calculator::amount( $pct, 1233.0, $this->settings(), 0 ), '92.475 floors to 92 for ISK' );
		$this->assertSame( 92.47, Earn_Calculator::amount( $pct, 1233.0, $this->settings(), 2 ) );
		$this->assertSame( 92.48, Earn_Calculator::amount( $pct, 1233.0, $this->settings( array( 'rounding' => 'nearest' ) ), 2 ) );
		$this->assertSame( 250.0, Earn_Calculator::amount( $fix, 1.0, $this->settings(), 0 ) );
		$this->assertSame( 0.0, Earn_Calculator::amount( $fix, 0.0, $this->settings(), 0 ), 'fixed credit needs an eligible basis' );
		$this->assertSame( 0.0, Earn_Calculator::amount( array( 'kind' => 'percent', 'value' => 0 ), 1000.0, $this->settings(), 0 ) );
	}

	public function test_expiry_rule_overrides_settings(): void {
		$now = strtotime( '2026-09-11 00:00:00 UTC' );
		$this->assertNull( Earn_Calculator::expires_at( array( 'expiry_days' => null ), $this->settings(), $now ) );
		$this->assertSame( '2027-09-11 00:00:00', Earn_Calculator::expires_at( array( 'expiry_days' => null ), $this->settings( array( 'expiry_days' => 365 ) ), $now ) );
		$this->assertSame( '2026-09-21 00:00:00', Earn_Calculator::expires_at( array( 'expiry_days' => 10 ), $this->settings( array( 'expiry_days' => 365 ) ), $now ) );
	}

	// ── fee cap ────────────────────────────────────────────────────────

	public function test_max_applicable_caps_by_pct_min_order_and_balance(): void {
		$s = $this->settings();
		$this->assertSame( 800.0, Fee_Calculator::max_applicable( 800.0, 1000.0, 5000.0, $s, 0 ), 'capped by eligible' );
		$this->assertSame( 300.0, Fee_Calculator::max_applicable( 800.0, 1000.0, 300.0, $s, 0 ), 'capped by balance' );
		$this->assertSame( 400.0, Fee_Calculator::max_applicable( 800.0, 1000.0, 5000.0, $this->settings( array( 'max_spend_pct' => 50 ) ), 0 ) );
		$this->assertSame( 0.0, Fee_Calculator::max_applicable( 800.0, 1000.0, 5000.0, $this->settings( array( 'min_order_to_spend' => 1001 ) ), 0 ) );
		$this->assertSame( 800.0, Fee_Calculator::max_applicable( 800.0, 1000.0, 5000.0, $this->settings( array( 'min_order_to_spend' => 1000 ) ), 0 ) );
		$this->assertSame( 0.0, Fee_Calculator::max_applicable( 0.0, 1000.0, 5000.0, $s, 0 ) );
		$this->assertSame( 266.66, Fee_Calculator::max_applicable( 800.0, 1000.0, 5000.0, $this->settings( array( 'max_spend_pct' => 33.3333 ) ), 2 ), 'rounded down to decimals' );
	}

	public function test_applied_honours_request(): void {
		$this->assertSame( 400.0, Fee_Calculator::applied( 400.0, null, 0 ), 'null = max' );
		$this->assertSame( 150.0, Fee_Calculator::applied( 400.0, '150', 0 ) );
		$this->assertSame( 400.0, Fee_Calculator::applied( 400.0, '9999', 0 ), 'request above max is capped' );
		$this->assertSame( 0.0, Fee_Calculator::applied( 400.0, '0', 0 ) );
		$this->assertSame( 0.0, Fee_Calculator::applied( 0.0, null, 0 ) );
	}

	// ── eligibility ────────────────────────────────────────────────────

	public function test_product_exclusions_by_id_parent_and_taxonomy_with_descendants(): void {
		$GLOBALS['__test_product_terms']          = array(
			20 => array( 'product_cat' => array( 255 ), 'product_tag' => array(), 'product_brand' => array( 7 ) ),
			30 => array( 'product_cat' => array( 1 ), 'product_tag' => array( 9 ), 'product_brand' => array() ),
		);
		$GLOBALS['__test_term_children']          = array( 'product_cat' => array( 223 => array( 255 ) ) );
		$GLOBALS['__test_hierarchical_taxonomies'] = array( 'product_cat' );

		$e         = new Eligibility();
		$simple    = new WC_Product( 30 );
		$variation = new WC_Product( 21 );
		$variation->parent_id = 20;

		$this->assertFalse( $e->product_excluded( $simple, $this->settings() ) );
		$this->assertTrue( $e->product_excluded( $simple, $this->settings( array( 'exclude_product_ids' => array( 30 ) ) ) ) );
		$this->assertTrue( $e->product_excluded( $variation, $this->settings( array( 'exclude_product_ids' => array( 20 ) ) ) ), 'parent id excludes its variations' );
		$this->assertTrue( $e->product_excluded( $variation, $this->settings( array( 'exclude_category_ids' => array( 223 ) ) ) ), 'parent category excludes descendants via the parent product' );
		$this->assertTrue( $e->product_excluded( $simple, $this->settings( array( 'exclude_tag_ids' => array( 9 ) ) ) ) );
		$this->assertTrue( $e->product_excluded( $variation, $this->settings( array( 'exclude_brand_ids' => array( 7 ) ) ) ) );
		$this->assertFalse( $e->product_excluded( $simple, $this->settings( array( 'exclude_brand_ids' => array( 7 ) ) ) ) );
		$this->assertTrue( $e->product_excluded( null, $this->settings() ), 'missing product is never eligible' );
	}

	public function test_is_on_sale_uses_merchant_sale_price_only(): void {
		$e = new Eligibility();
		$p = new WC_Product( 1 );
		$p->regular_price = '100';
		$p->price         = '80'; // dynamic Storedash discount sets price, not sale_price
		$this->assertFalse( $e->is_on_sale( $p ) );
		$p->sale_price = '80';
		$this->assertTrue( $e->is_on_sale( $p ) );
		$p->sale_price = '0';
		$this->assertFalse( $e->is_on_sale( $p ) );
	}

	// ── money / serializers ────────────────────────────────────────────

	public function test_money_rounding_and_minor_units(): void {
		$this->assertSame( 12.3, Money::round( 12.3, 'down', 2 ), 'float noise must not floor 12.30 to 12.29' );
		$this->assertSame( 12.34, Money::round( 12.349, 'down', 2 ) );
		$this->assertSame( 12.35, Money::round( 12.349, 'nearest', 2 ) );
		$this->assertSame( 0.0, Money::round( -5, 'down', 2 ) );
		$this->assertSame( '1250', Money::to_minor( 1250, 0 ) );
		$this->assertSame( '125000', Money::to_minor( '1250.00', 2 ) );
		$this->assertSame( 12.5, Money::from_minor( '1250', 2 ) );
		$this->assertSame( '1250.00', Money::to_decimal_string( 1250, 0 ) );
		$this->assertSame( '1250.500', Money::to_decimal_string( 1250.5, 3 ) );
		$this->assertNull( Money::to_decimal_string( null ) );
		$this->assertSame( '2027-09-11T00:00:00Z', Money::to_iso( '2027-09-11 00:00:00' ) );
		$this->assertNull( Money::to_iso( null ) );
	}

	public function test_format_row_matches_contract_b(): void {
		$row = (object) array(
			'id'           => '42',
			'customer_key' => 'a@b.is',
			'user_id'      => '17',
			'type'         => 'earn',
			'amount'       => '1250.0000',
			'remaining'    => '1250.0000',
			'currency'     => 'ISK',
			'order_id'     => '9911',
			'refund_id'    => null,
			'rule_id'      => 'uuid',
			'note'         => null,
			'expires_at'   => '2027-09-11 00:00:00',
			'created_at'   => '2026-09-11 16:00:00',
		);
		$this->assertSame(
			array(
				'id'             => 42,
				'store_id'       => '1043',
				'customer_email' => 'a@b.is',
				'user_id'        => 17,
				'type'           => 'earn',
				'amount'         => '1250.00',
				'remaining'      => '1250.00',
				'currency'       => 'ISK',
				'order_id'       => 9911,
				'refund_id'      => null,
				'rule_id'        => 'uuid',
				'note'           => null,
				'expires_at'     => '2027-09-11T00:00:00Z',
				'created_at'     => '2026-09-11T16:00:00Z',
			),
			Money::format_row( $row, '1043', 0 )
		);

		$spend = clone $row;
		$spend->type      = 'spend';
		$spend->amount    = '-300.0000';
		$spend->remaining = null;
		$spend->user_id   = null;
		$out              = Money::format_row( $spend, '1043', 0 );
		$this->assertSame( '-300.00', $out['amount'] );
		$this->assertNull( $out['remaining'] );
		$this->assertNull( $out['user_id'] );
	}

	public function test_store_api_state_is_minor_unit_strings(): void {
		$state = array(
			'enabled'        => true,
			'logged_in'      => true,
			'currency'       => 'ISK',
			'balance'        => 1250.0,
			'eligible'       => 9800.0,
			'max_applicable' => 1250.0,
			'requested'      => null,
			'applied'        => 1250.0,
			'expiring_next'  => array( 'amount' => 200.0, 'at' => '2026-12-01 00:00:00' ),
		);
		$this->assertSame(
			array(
				'enabled'        => true,
				'logged_in'      => true,
				'currency'       => 'ISK',
				'balance'        => '1250',
				'eligible'       => '9800',
				'max_applicable' => '1250',
				'requested'      => null,
				'applied'        => '1250',
				'expiring_next'  => array( 'amount' => '200', 'at' => '2026-12-01T00:00:00Z' ),
			),
			Store_API_Integration::format_state( $state, 0 )
		);

		$state['requested'] = '12.5';
		$out                = Store_API_Integration::format_state( $state, 2 );
		$this->assertSame( '1250', $out['requested'] );
		$this->assertSame( '125000', $out['balance'] );

		$guest = Store_API_Integration::format_state( array( 'enabled' => true, 'logged_in' => false, 'currency' => 'ISK' ), 0 );
		$this->assertSame( '0', $guest['balance'] );
		$this->assertNull( $guest['expiring_next'] );
	}

	public function test_refund_share(): void {
		$this->assertSame( 0.25, Refund_Handler::share( 250, 1000 ) );
		$this->assertSame( 1.0, Refund_Handler::share( 5000, 1000 ), 'capped at 100%' );
		$this->assertSame( 1.0, Refund_Handler::share( 10, 0 ), 'zero-total (fully credit-paid) order refunds everything' );
	}
}
