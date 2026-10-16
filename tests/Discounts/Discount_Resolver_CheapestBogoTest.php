<?php
/**
 * Unit tests for "cheapest in cart" BOGO (rule_config.mode = 'cheapest').
 *
 * Pure-logic tests with injected matcher + DB-handler doubles — no WordPress.
 *
 * @package StoreDash\Tests\Discounts
 */

namespace StoreDash\Tests\Discounts;

use PHPUnit\Framework\TestCase;
use StoreDash\Discounts\Engine\Discount_Resolver;
use WC_Product;

/**
 * @covers \StoreDash\Discounts\Engine\Discount_Resolver
 */
class Discount_Resolver_CheapestBogoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\StoreDash_Helpers::$logs         = array();
		$GLOBALS['__test_price_decimals'] = 0; // ISK-style store.
	}

	protected function tearDown(): void {
		$GLOBALS['__test_wc_cart']        = null;
		$GLOBALS['__test_price_decimals'] = 2;
		\StoreDash_Helpers::$logs         = array();
		parent::tearDown();
	}

	private function discount( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'id'                     => 1,
				'name'                   => '3 fyrir 2',
				'rule_type'              => 'bogo',
				'discount_type'          => 'percentage',
				'amount'                 => '0',
				'priority'               => 10,
				'rule_config'            => json_encode(
					array(
						'mode'         => 'cheapest',
						'buy_quantity' => 2,
						'get_quantity' => 1,
						'discount'     => 100,
					)
				),
				'target_ids'             => '',
				'exclude_ids'            => '',
				'conditions'             => '{}',
				'disable_on_sale'        => '0',
				'disable_lower_priority' => '0',
				'disable_with_coupons'   => '0',
				'apply_to_sale_price'    => '0',
			),
			$overrides
		);
	}

	private function make_resolver( array $discounts, array $applies_map = array() ) {
		$matcher              = new Resolver_Fake_Matcher();
		$matcher->applies_map = $applies_map;
		$db                   = new Resolver_Fake_DB();
		$db->discounts        = $discounts;
		return new Discount_Resolver( $matcher, $db );
	}

	private function product( $id, $regular, $sale = '' ) {
		$product                = new WC_Product( $id );
		$product->regular_price = (string) $regular;
		$product->sale_price    = (string) $sale;
		$product->price         = '' !== (string) $sale ? (string) $sale : (string) $regular;
		return $product;
	}

	/**
	 * @param array<string,array{0:WC_Product,1:int}> $lines key => [product, qty]
	 */
	private function set_cart( array $lines, array $coupons = array() ) {
		$cart = new \Fake_WC_Cart();
		foreach ( $lines as $key => $line ) {
			$cart->cart_contents[ $key ] = array(
				'data'     => $line[0],
				'quantity' => $line[1],
			);
		}
		$cart->applied_coupons     = $coupons;
		$GLOBALS['__test_wc_cart'] = $cart;
		return $cart;
	}

	// ── Allocation ─────────────────────────────────────────────────────

	public function test_cheapest_of_three_different_products_is_free() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$resolver = $this->make_resolver( array( $this->discount() ) );

		$win = $resolver->resolve( $a, 1, 'cart', 'a' );
		$this->assertNotNull( $win );
		$this->assertSame( 'bogo_cheapest', $win->kind );
		$this->assertSame( 0.0, $win->unit_price );
		$this->assertSame( 1000.0, $win->savings );
		$this->assertSame( 1, $win->bogo['free_quantity'] );
		$this->assertSame( 3, $win->bogo['pool_units'] );

		// The two dearer lines are untouched by this rule.
		$this->assertNull( $resolver->resolve( $b, 1, 'cart', 'b' ) );
		$this->assertNull( $resolver->resolve( $c, 1, 'cart', 'c' ) );
	}

	public function test_two_units_short_of_a_set_gives_nothing() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ) ) );
		$resolver = $this->make_resolver( array( $this->discount() ) );

		$this->assertNull( $resolver->resolve( $a, 1, 'cart', 'a' ) );
		$this->assertNull( $resolver->resolve( $b, 1, 'cart', 'b' ) );
	}

	public function test_same_product_three_times_averages_the_line_price_exactly() {
		$a = $this->product( 1, 1000 );
		$this->set_cart( array( 'a' => array( $a, 3 ) ) );
		$resolver = $this->make_resolver( array( $this->discount() ) );

		$win = $resolver->resolve( $a, 3, 'cart', 'a' );
		$this->assertSame( 'bogo_cheapest', $win->kind );
		$this->assertSame( 1000.0, $win->savings );
		$this->assertSame( 1, $win->bogo['free_quantity'] );
		// Unit price is NOT rounded so WooCommerce's line total (price × qty,
		// then precision) lands on 2000 and never 2001.
		$this->assertEqualsWithDelta( 2000.0 / 3, $win->unit_price, 0.0000001 );
		$this->assertEqualsWithDelta( 2000.0, $win->unit_price * 3, 0.0001 );
	}

	public function test_six_units_free_two_cheapest_overall() {
		$a = $this->product( 1, 500 );
		$b = $this->product( 2, 1000 );
		$c = $this->product( 3, 4000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 2 ), 'c' => array( $c, 3 ) ) );
		$resolver = $this->make_resolver( array( $this->discount() ) );

		// 6 units → 2 free: the 500 unit and ONE of the 1000 units.
		$wa = $resolver->resolve( $a, 1, 'cart', 'a' );
		$wb = $resolver->resolve( $b, 2, 'cart', 'b' );
		$this->assertSame( 500.0, $wa->savings );
		$this->assertSame( 1, $wb->bogo['free_quantity'] );
		$this->assertSame( 1000.0, $wb->savings );
		$this->assertSame( 500.0, $wb->unit_price ); // (2×1000 − 1000) / 2
		$this->assertNull( $resolver->resolve( $c, 3, 'cart', 'c' ) );
	}

	public function test_partial_percentage_discounts_the_cheapest_unit() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$config   = array( 'mode' => 'cheapest', 'buy_quantity' => 2, 'get_quantity' => 1, 'discount' => 50 );
		$resolver = $this->make_resolver( array( $this->discount( array( 'rule_config' => json_encode( $config ) ) ) ) );

		$win = $resolver->resolve( $a, 1, 'cart', 'a' );
		$this->assertSame( 500.0, $win->unit_price );
		$this->assertSame( 500.0, $win->savings );
		$this->assertSame( 50.0, $win->bogo['discount_percent'] );
	}

	public function test_scope_leaves_non_qualifying_lines_out_of_the_pool() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		// The matcher says the rule does not apply to product 1 (e.g. excluded /
		// outside the targeted category): only 2 qualifying units → no set.
		$matcher              = new Resolver_Fake_Matcher();
		$matcher->applies_map = array();
		$db                   = new Resolver_Fake_DB();
		$db->discounts        = array( $this->discount() );
		$resolver             = new class( $matcher, $db ) extends Discount_Resolver {
			protected function is_eligible( $discount, $product, $context ) {
				if ( 1 === $product->get_id() ) {
					return false;
				}
				return parent::is_eligible( $discount, $product, $context );
			}
		};

		$this->assertNull( $resolver->resolve( $a, 1, 'cart', 'a' ) );
		$this->assertNull( $resolver->resolve( $b, 1, 'cart', 'b' ) );
	}

	public function test_bogo_free_item_lines_never_count() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$cart = $this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ) ) );
		$cart->cart_contents['free'] = array(
			'data'                   => $this->product( 9, 100 ),
			'quantity'               => 1,
			'storedash_is_free_item' => true,
		);
		$resolver = $this->make_resolver( array( $this->discount() ) );

		$this->assertNull( $resolver->resolve( $a, 1, 'cart', 'a' ) );
	}

	// ── Settings ───────────────────────────────────────────────────────

	public function test_skip_products_on_sale_drops_sale_lines_from_the_pool() {
		$a = $this->product( 1, 1000, 800 ); // on merchant sale
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$resolver = $this->make_resolver( array( $this->discount( array( 'disable_on_sale' => '1' ) ) ) );

		$this->assertNull( $resolver->resolve( $a, 1, 'cart', 'a' ) );
		$this->assertNull( $resolver->resolve( $b, 1, 'cart', 'b' ) );
	}

	public function test_sale_price_basis_discounts_from_the_sale_price() {
		$a = $this->product( 1, 1000, 800 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$config   = array( 'mode' => 'cheapest', 'buy_quantity' => 2, 'get_quantity' => 1, 'discount' => 50 );
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'rule_config' => json_encode( $config ), 'apply_to_sale_price' => '1' ) ) )
		);

		$win = $resolver->resolve( $a, 1, 'cart', 'a' );
		$this->assertSame( 400.0, $win->unit_price ); // 50% of the 800 sale price
		$this->assertSame( 400.0, $win->savings );
	}

	public function test_regular_price_basis_never_charges_more_than_the_sale_price() {
		$a = $this->product( 1, 1000, 400 ); // sale already deeper than 50% of regular
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$config   = array( 'mode' => 'cheapest', 'buy_quantity' => 2, 'get_quantity' => 1, 'discount' => 50 );
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'rule_config' => json_encode( $config ), 'apply_to_sale_price' => '0' ) ) )
		);

		// 50% of regular = 500 > sale 400 → no saving on that unit → it leaves
		// the pool, and the remaining two units are not a set.
		$this->assertNull( $resolver->resolve( $a, 1, 'cart', 'a' ) );
		$this->assertNull( $resolver->resolve( $b, 1, 'cart', 'b' ) );
	}

	public function test_coupon_gate_blocks_the_whole_offer() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ), array( 'SAVE10' ) );
		$resolver = $this->make_resolver( array( $this->discount( array( 'disable_with_coupons' => '1' ) ) ) );

		$this->assertNull( $resolver->resolve( $a, 1, 'cart', 'a' ) );
	}

	public function test_min_cart_total_condition_is_checked_against_the_whole_cart() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );

		$blocked = $this->make_resolver( array( $this->discount( array( 'conditions' => '{"min_cart_total":7000}' ) ) ) );
		$this->assertNull( $blocked->resolve( $a, 1, 'cart', 'a' ) );

		$allowed = $this->make_resolver( array( $this->discount( array( 'conditions' => '{"min_cart_total":6000}' ) ) ) );
		$this->assertSame( 1000.0, $allowed->resolve( $a, 1, 'cart', 'a' )->savings );
	}

	public function test_max_quantity_condition_counts_paid_units() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );

		$blocked = $this->make_resolver( array( $this->discount( array( 'conditions' => '{"max_quantity":2}' ) ) ) );
		$this->assertNull( $blocked->resolve( $a, 1, 'cart', 'a' ) );
	}

	// ── Competition with other rules ───────────────────────────────────

	public function test_line_claimed_by_a_higher_priority_rule_leaves_the_pool() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$product_rule = (object) array_merge(
			(array) $this->discount( array( 'id' => 2, 'priority' => 1 ) ),
			array( 'rule_type' => 'product', 'amount' => '10.00', 'rule_config' => null, 'target_ids' => '1' )
		);
		// Matcher: the product rule applies only to product 1.
		$matcher              = new class() {
			public function discount_applies_to_product( $discount, $product ) {
				if ( 2 === (int) $discount->id ) {
					return 1 === $product->get_id();
				}
				return true;
			}
		};
		$db                   = new Resolver_Fake_DB();
		$db->discounts        = array( $this->discount(), $product_rule );
		$resolver             = new Discount_Resolver( $matcher, $db );

		// Product 1 goes to the 10% rule (priority 1) and is out of the BOGO
		// pool, so only 2 qualifying units remain → no free unit anywhere.
		$wa = $resolver->resolve( $a, 1, 'cart', 'a' );
		$this->assertSame( 'price', $wa->kind );
		$this->assertSame( 900.0, $wa->unit_price );
		$this->assertNull( $resolver->resolve( $b, 1, 'cart', 'b' ) );
	}

	public function test_equal_priority_bogo_wins_its_free_line_on_savings() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$store_wide = (object) array_merge(
			(array) $this->discount( array( 'id' => 2, 'priority' => 10 ) ),
			array( 'rule_type' => 'store_wide', 'amount' => '10.00', 'rule_config' => null )
		);
		$resolver   = $this->make_resolver( array( $this->discount(), $store_wide ) );

		$this->assertSame( 'bogo_cheapest', $resolver->resolve( $a, 1, 'cart', 'a' )->kind );
		$this->assertSame( 'price', $resolver->resolve( $b, 1, 'cart', 'b' )->kind );
	}

	public function test_override_lower_priority_suppresses_other_rules_on_pool_lines() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$store_wide = (object) array_merge(
			(array) $this->discount( array( 'id' => 2, 'priority' => 20 ) ),
			array( 'rule_type' => 'store_wide', 'amount' => '10.00', 'rule_config' => null )
		);
		$resolver   = $this->make_resolver( array( $this->discount( array( 'disable_lower_priority' => '1' ) ), $store_wide ) );

		$this->assertSame( 'bogo_cheapest', $resolver->resolve( $a, 1, 'cart', 'a' )->kind );
		$this->assertNull( $resolver->resolve( $b, 1, 'cart', 'b' ) );
	}

	// ── Mode + context ─────────────────────────────────────────────────

	public function test_rows_without_mode_keep_the_same_product_mechanic() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$legacy   = json_encode( array( 'buy_quantity' => 2, 'get_quantity' => 1, 'discount' => 100 ) );
		$resolver = $this->make_resolver( array( $this->discount( array( 'rule_config' => $legacy ) ) ) );

		$this->assertSame( 'same_product', $resolver->bogo_mode( $this->discount( array( 'rule_config' => $legacy ) ) ) );
		// Mixed single units never trigger same-product BOGO.
		$this->assertNull( $resolver->resolve( $a, 1, 'cart', 'a' ) );
		$this->assertSame( 'bogo', $resolver->resolve( $a, 2, 'cart', 'a' )->kind );
	}

	public function test_cheapest_mode_never_applies_on_display_surfaces() {
		$a = $this->product( 1, 1000 );
		$this->set_cart( array( 'a' => array( $a, 3 ) ) );
		$resolver = $this->make_resolver( array( $this->discount() ) );

		$this->assertNull( $resolver->resolve( $a, 1, 'display' ) );
	}

	public function test_line_key_is_inferred_when_a_caller_has_none() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$resolver = $this->make_resolver( array( $this->discount() ) );

		$this->assertSame( 1000.0, $resolver->resolve( $a, 1, 'cart' )->savings );
	}

	public function test_allocation_is_recomputed_after_clear_cache() {
		$a = $this->product( 1, 1000 );
		$b = $this->product( 2, 2000 );
		$c = $this->product( 3, 3000 );
		$cart     = $this->set_cart( array( 'a' => array( $a, 1 ), 'b' => array( $b, 1 ), 'c' => array( $c, 1 ) ) );
		$resolver = $this->make_resolver( array( $this->discount() ) );
		$this->assertSame( 1000.0, $resolver->resolve( $a, 1, 'cart', 'a' )->savings );

		unset( $cart->cart_contents['c'] );
		$resolver->clear_cache();
		$this->assertNull( $resolver->resolve( $a, 1, 'cart', 'a' ) );
	}
}
