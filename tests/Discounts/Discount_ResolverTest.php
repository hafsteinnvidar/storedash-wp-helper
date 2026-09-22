<?php
/**
 * Unit tests for Discount_Resolver — the resolution rulebook.
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
 * Matcher double for the resolver: applicability keyed by discount id
 * (missing key = applies).
 */
final class Resolver_Fake_Matcher {

	/** @var array<int,bool> */
	public $applies_map = array();

	public function discount_applies_to_product( $discount, $product ) {
		$id = isset( $discount->id ) ? (int) $discount->id : 0;
		return array_key_exists( $id, $this->applies_map ) ? $this->applies_map[ $id ] : true;
	}
}

/**
 * DB-handler double returning a fixed active-discount list.
 */
final class Resolver_Fake_DB {

	/** @var array */
	public $discounts = array();

	public function get_active_discounts( $rule_type = null ) {
		return $this->discounts;
	}
}

/**
 * @covers \StoreDash\Discounts\Engine\Discount_Resolver
 */
class Discount_ResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\StoreDash_Helpers::$logs = array();
	}

	protected function tearDown(): void {
		$GLOBALS['__test_wc_cart']        = null;
		$GLOBALS['__test_price_decimals'] = 2;
		\StoreDash_Helpers::$logs         = array();
		parent::tearDown();
	}

	/**
	 * Build a discount row as $wpdb returns it (strings for TINYINT etc).
	 */
	private function discount( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'id'                     => 1,
				'rule_type'              => 'store_wide',
				'discount_type'          => 'percentage',
				'amount'                 => '10.00',
				'priority'               => 10,
				'rule_config'            => null,
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

	private function product( $regular, $sale = '', $id = 101 ) {
		$product                = new WC_Product( $id );
		$product->regular_price = (string) $regular;
		$product->sale_price    = (string) $sale;
		$product->price         = '' !== (string) $sale ? (string) $sale : (string) $regular;
		return $product;
	}

	private function set_cart( $count = 0, $subtotal = 0.0, array $coupons = array() ) {
		$cart        = new \Fake_WC_Cart();
		$cart->count = $count;
		// WooCommerce resets totals BEFORE firing woocommerce_before_calculate_totals,
		// so get_subtotal() is always 0.0 inside the hook where the resolver runs.
		// Mirror that: the requested subtotal is represented as cart CONTENTS and the
		// subtotal property stays at the reset value.
		$cart->subtotal = 0.0;
		if ( $subtotal > 0 ) {
			$line_product                = new WC_Product( 900 );
			$line_product->regular_price = (string) $subtotal;
			$line_product->price         = (string) $subtotal;
			$cart->cart_contents['line'] = array(
				'data'     => $line_product,
				'quantity' => 1,
			);
		}
		// The quantity gate derives its count from cart CONTENTS (skipping BOGO
		// free-item lines); model any remaining count as a zero-price filler line
		// so it adds quantity without disturbing the subtotal.
		$remaining = $count - ( $subtotal > 0 ? 1 : 0 );
		if ( $remaining > 0 ) {
			$filler                        = new WC_Product( 901 );
			$filler->regular_price         = '0';
			$filler->price                 = '0';
			$cart->cart_contents['filler'] = array(
				'data'     => $filler,
				'quantity' => $remaining,
			);
		}
		$cart->applied_coupons     = $coupons;
		$GLOBALS['__test_wc_cart'] = $cart;
		return $cart;
	}

	// ── Winner selection ────────────────────────────────────────────────

	public function test_lower_priority_number_beats_bigger_saving() {
		$resolver = $this->make_resolver(
			array(
				$this->discount( array( 'id' => 1, 'priority' => 5, 'amount' => '10.00' ) ),
				$this->discount( array( 'id' => 2, 'priority' => 20, 'amount' => '50.00' ) ),
			)
		);
		$result = $resolver->resolve( $this->product( 100 ) );
		$this->assertSame( 1, (int) $result->discount->id );
		$this->assertSame( 90.0, $result->unit_price );
	}

	public function test_equal_priority_biggest_saving_wins() {
		$resolver = $this->make_resolver(
			array(
				$this->discount( array( 'id' => 1, 'priority' => 10, 'amount' => '10.00' ) ),
				$this->discount( array( 'id' => 2, 'priority' => 10, 'amount' => '25.00' ) ),
			)
		);
		$result = $resolver->resolve( $this->product( 100 ) );
		$this->assertSame( 2, (int) $result->discount->id );
		$this->assertSame( 75.0, $result->unit_price );
	}

	public function test_equal_priority_equal_saving_lowest_id_wins() {
		$resolver = $this->make_resolver(
			array(
				$this->discount( array( 'id' => 7, 'priority' => 10, 'amount' => '20.00' ) ),
				$this->discount( array( 'id' => 3, 'priority' => 10, 'amount' => '20.00' ) ),
			)
		);
		$result = $resolver->resolve( $this->product( 100 ) );
		$this->assertSame( 3, (int) $result->discount->id );
	}

	public function test_null_priority_treated_as_ten() {
		$resolver = $this->make_resolver(
			array(
				$this->discount( array( 'id' => 1, 'priority' => null, 'amount' => '10.00' ) ),
				$this->discount( array( 'id' => 2, 'priority' => 11, 'amount' => '50.00' ) ),
			)
		);
		$result = $resolver->resolve( $this->product( 100 ) );
		$this->assertSame( 1, (int) $result->discount->id );
	}

	public function test_suppression_ceiling_kills_lower_priority_but_tie_survives() {
		$resolver = $this->make_resolver(
			array(
				$this->discount( array( 'id' => 1, 'priority' => 2, 'amount' => '5.00', 'disable_lower_priority' => '1' ) ),
				$this->discount( array( 'id' => 2, 'priority' => 2, 'amount' => '30.00' ) ),
				$this->discount( array( 'id' => 3, 'priority' => 5, 'amount' => '50.00' ) ),
			)
		);
		$result = $resolver->resolve( $this->product( 100 ) );
		// id 3 suppressed (5 > ceiling 2); tie between 1 and 2 → best saving wins.
		$this->assertSame( 2, (int) $result->discount->id );
	}

	// ── Price basis (apply_to_sale_price) ───────────────────────────────

	public function test_apply_to_sale_price_true_discounts_the_sale_price() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'amount' => '20.00', 'apply_to_sale_price' => '1' ) ) )
		);
		$result = $resolver->resolve( $this->product( 100, 90 ) );
		$this->assertSame( 72.0, $result->unit_price );
		$this->assertSame( 90.0, $result->base_price );
	}

	public function test_apply_to_sale_price_false_uses_regular_and_never_beats_sale() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'amount' => '5.00', 'apply_to_sale_price' => '0' ) ) )
		);
		// 5% off 100 = 95 > sale 90 → not a real discount → no win.
		$this->assertNull( $resolver->resolve( $this->product( 100, 90 ) ) );
	}

	public function test_apply_to_sale_price_false_wins_when_beating_the_sale() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'amount' => '20.00', 'apply_to_sale_price' => '0' ) ) )
		);
		$result = $resolver->resolve( $this->product( 100, 90 ) );
		$this->assertSame( 80.0, $result->unit_price );
		$this->assertSame( 90.0, $result->base_price );
	}

	// ── Amount math + invariants ────────────────────────────────────────

	public function test_fixed_amount_clamps_at_zero() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'discount_type' => 'fixed_amount', 'amount' => '150.00' ) ) )
		);
		$result = $resolver->resolve( $this->product( 100 ) );
		$this->assertSame( 0.0, $result->unit_price );
	}

	public function test_fixed_price_above_current_price_does_not_win() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'discount_type' => 'fixed_price', 'amount' => '95.00', 'apply_to_sale_price' => '1' ) ) )
		);
		// current (sale) price is 90; fixed price 95 would RAISE it → no win.
		$this->assertNull( $resolver->resolve( $this->product( 100, 90 ) ) );
	}

	public function test_percentage_above_100_clamps_to_free_not_negative() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'amount' => '150.00' ) ) )
		);
		$result = $resolver->resolve( $this->product( 100 ) );
		$this->assertSame( 0.0, $result->unit_price );
	}

	public function test_rounding_uses_price_decimals() {
		$GLOBALS['__test_price_decimals'] = 0; // ISK-style zero-decimal store
		$resolver                         = $this->make_resolver(
			array( $this->discount( array( 'amount' => '33.00' ) ) )
		);
		$result = $resolver->resolve( $this->product( 1000 ) );
		$this->assertSame( 670.0, $result->unit_price ); // 1000 * 0.67 = 670, round(…, 0)
	}

	public function test_zero_regular_price_never_matches() {
		$resolver = $this->make_resolver( array( $this->discount() ) );
		$this->assertNull( $resolver->resolve( $this->product( 0 ) ) );
	}

	// ── Context: display vs cart ────────────────────────────────────────

	public function test_display_context_excludes_cart_rule_types() {
		$resolver = $this->make_resolver(
			array(
				$this->discount( array( 'id' => 1, 'rule_type' => 'quantity', 'priority' => 1, 'rule_config' => json_encode( array( 'tiers' => array( array( 'min_quantity' => 1, 'max_quantity' => null, 'discount' => 50 ) ) ) ) ) ),
				$this->discount( array( 'id' => 2, 'priority' => 20, 'amount' => '10.00' ) ),
			)
		);
		$result = $resolver->resolve( $this->product( 100 ), 1, 'display' );
		$this->assertSame( 2, (int) $result->discount->id );
	}

	public function test_display_context_excludes_rules_with_cart_conditions() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'conditions' => json_encode( array( 'min_cart_total' => 50 ) ) ) ) )
		);
		$this->assertNull( $resolver->resolve( $this->product( 100 ), 1, 'display' ) );
	}

	public function test_cart_context_applies_conditioned_rule_when_condition_met() {
		$this->set_cart( 2, 200.0 );
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'conditions' => json_encode( array( 'min_cart_total' => 50 ) ) ) ) )
		);
		$result = $resolver->resolve( $this->product( 100 ), 1, 'cart' );
		$this->assertNotNull( $result );
		$this->assertSame( 90.0, $result->unit_price );
	}

	public function test_cart_context_skips_conditioned_rule_when_condition_unmet() {
		$this->set_cart( 1, 20.0 );
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'conditions' => json_encode( array( 'min_cart_total' => 50 ) ) ) ) )
		);
		$this->assertNull( $resolver->resolve( $this->product( 100 ), 1, 'cart' ) );
	}

	public function test_cart_total_condition_derives_from_contents_not_reset_subtotal() {
		// Regression: WC_Cart::calculate_totals() calls reset_totals() BEFORE firing
		// woocommerce_before_calculate_totals, so get_subtotal() is 0 inside the
		// orchestrator. The gate must derive the pre-discount subtotal from cart
		// contents or min_cart_total can never pass.
		$cart = $this->set_cart( 2, 300.0 );
		$this->assertSame( 0.0, $cart->get_subtotal() );

		$resolver = $this->make_resolver(
			array( $this->discount( array( 'conditions' => json_encode( array( 'min_cart_total' => 200 ) ) ) ) )
		);
		$result = $resolver->resolve( $this->product( 100 ), 1, 'cart' );
		$this->assertNotNull( $result );
		$this->assertSame( 90.0, $result->unit_price );
	}

	public function test_cart_total_subtotal_excludes_free_items_and_uses_sale_price() {
		// Free (BOGO output) lines don't count toward the subtotal; sale-priced
		// lines count at their sale price (what the shopper actually pays).
		$cart = $this->set_cart( 3, 0.0 );

		$paid                = new WC_Product( 901 );
		$paid->regular_price = '100';
		$paid->sale_price    = '60';
		$paid->price         = '60';

		$free                = new WC_Product( 902 );
		$free->regular_price = '100';
		$free->price         = '0';

		$cart->cart_contents = array(
			'paid' => array(
				'data'     => $paid,
				'quantity' => 2,
			),
			'free' => array(
				'data'                   => $free,
				'quantity'               => 1,
				'storedash_is_free_item' => true,
			),
		);

		// Subtotal = 2 × 60 = 120: passes min 100, fails min 150.
		$pass = $this->make_resolver(
			array( $this->discount( array( 'conditions' => json_encode( array( 'min_cart_total' => 100 ) ) ) ) )
		);
		$this->assertNotNull( $pass->resolve( $this->product( 100 ), 1, 'cart' ) );

		$fail = $this->make_resolver(
			array( $this->discount( array( 'conditions' => json_encode( array( 'min_cart_total' => 150 ) ) ) ) )
		);
		$this->assertNull( $fail->resolve( $this->product( 100 ), 1, 'cart' ) );
	}

	public function test_quantity_conditions_exclude_bogo_free_items() {
		// Regression (MF-3): quantity gates used get_cart_contents_count(),
		// which includes BOGO free-item lines. Paid quantity here is 2 (the
		// free line must not count), so max_quantity=2 passes and
		// min_quantity=3 fails.
		$cart = $this->set_cart( 0, 0.0 );

		$paid                = new WC_Product( 903 );
		$paid->regular_price = '50';
		$paid->price         = '50';

		$free                = new WC_Product( 904 );
		$free->regular_price = '50';
		$free->price         = '0';

		$cart->cart_contents = array(
			'paid' => array(
				'data'     => $paid,
				'quantity' => 2,
			),
			'free' => array(
				'data'                   => $free,
				'quantity'               => 1,
				'storedash_is_free_item' => true,
			),
		);
		// Model real Woo: contents count INCLUDES the free line.
		$cart->count = 3;

		// max_quantity=2: contents count is 3 but paid quantity is 2 → applies.
		$max = $this->make_resolver(
			array( $this->discount( array( 'conditions' => json_encode( array( 'max_quantity' => 2 ) ) ) ) )
		);
		$this->assertNotNull( $max->resolve( $this->product( 100 ), 1, 'cart' ) );

		// min_quantity=3: contents count is 3 but paid quantity is 2 → skipped.
		$min = $this->make_resolver(
			array( $this->discount( array( 'conditions' => json_encode( array( 'min_quantity' => 3 ) ) ) ) )
		);
		$this->assertNull( $min->resolve( $this->product( 100 ), 1, 'cart' ) );
	}

	// ── Corrupt conditions blob fails CLOSED ────────────────────────────

	public function test_empty_string_conditions_are_treated_as_no_conditions() {
		$this->set_cart( 1, 100.0 );
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'conditions' => '' ) ) )
		);
		// Genuinely-empty conditions → discount still applies (unchanged behaviour).
		$this->assertNotNull( $resolver->resolve( $this->product( 100 ), 1, 'cart' ) );
		$this->assertCount( 0, \StoreDash_Helpers::$logs );
	}

	public function test_null_conditions_are_treated_as_no_conditions() {
		$this->set_cart( 1, 100.0 );
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'conditions' => null ) ) )
		);
		$this->assertNotNull( $resolver->resolve( $this->product( 100 ), 1, 'cart' ) );
		$this->assertCount( 0, \StoreDash_Helpers::$logs );
	}

	public function test_truncated_conditions_json_fails_closed_and_logs() {
		$this->set_cart( 1, 100.0 );
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'id' => 42, 'conditions' => '{"min_cart_total":' ) ) )
		);
		// Corrupt JSON must NOT apply unconditionally.
		$this->assertNull( $resolver->resolve( $this->product( 100 ), 1, 'cart' ) );
		$this->assertCount( 1, \StoreDash_Helpers::$logs );
		$this->assertSame( 'error', \StoreDash_Helpers::$logs[0]['level'] );
		$this->assertSame( 42, \StoreDash_Helpers::$logs[0]['context']['discount_id'] );
	}

	public function test_scalar_json_conditions_fail_closed() {
		$this->set_cart( 1, 100.0 );
		// Valid JSON but a scalar, not an object → not conditions → fail closed.
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'conditions' => '5' ) ) )
		);
		$this->assertNull( $resolver->resolve( $this->product( 100 ), 1, 'cart' ) );
		$this->assertCount( 1, \StoreDash_Helpers::$logs );
	}

	public function test_disable_with_coupons_skips_in_cart_context_only() {
		$this->set_cart( 1, 100.0, array( 'SUMMER10' ) );
		$discounts = array( $this->discount( array( 'disable_with_coupons' => '1' ) ) );

		$this->assertNull( $this->make_resolver( $discounts )->resolve( $this->product( 100 ), 1, 'cart' ) );
		// Display context ignores coupons (catalog can't know checkout coupons).
		$this->assertNotNull( $this->make_resolver( $discounts )->resolve( $this->product( 100 ), 1, 'display' ) );
	}

	public function test_disable_on_sale_skips_sale_products() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'disable_on_sale' => '1' ) ) )
		);
		$this->assertNull( $resolver->resolve( $this->product( 100, 90 ) ) );
		// Distinct id so the per-request cache (context:product_id:qty) doesn't
		// collide with the on-sale product above — a real product has one price.
		$this->assertNotNull( $resolver->resolve( $this->product( 100, '', 102 ) ) );
	}

	public function test_structural_non_match_is_skipped() {
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'id' => 9 ) ) ),
			array( 9 => false )
		);
		$this->assertNull( $resolver->resolve( $this->product( 100 ) ) );
	}

	// ── Quantity + BOGO kinds (cart context) ────────────────────────────

	public function test_quantity_tier_selected_by_line_quantity() {
		$tiers    = array(
			array( 'min_quantity' => 1, 'max_quantity' => 2, 'discount' => 0 ),
			array( 'min_quantity' => 3, 'max_quantity' => null, 'discount' => 20 ),
		);
		$resolver = $this->make_resolver(
			array( $this->discount( array( 'rule_type' => 'quantity', 'rule_config' => json_encode( array( 'tiers' => $tiers ) ) ) ) )
		);
		$this->set_cart( 3, 300.0 );
		$this->assertNull( $resolver->resolve( $this->product( 100 ), 2, 'cart' ) );
		$result = $resolver->resolve( $this->product( 100 ), 3, 'cart' );
		$this->assertSame( 'quantity', $result->kind );
		$this->assertSame( 80.0, $result->unit_price );
		$this->assertSame( 60.0, $result->savings ); // (100-80) * 3
	}

	public function test_bogo_beats_price_rule_on_savings_at_equal_priority() {
		$this->set_cart( 4, 400.0 );
		$bogo     = $this->discount(
			array(
				'id'          => 1,
				'rule_type'   => 'bogo',
				'priority'    => 10,
				'rule_config' => json_encode( array( 'buy_quantity' => 2, 'get_quantity' => 1, 'discount' => 100 ) ),
			)
		);
		$price    = $this->discount( array( 'id' => 2, 'priority' => 10, 'amount' => '10.00' ) );
		$resolver = $this->make_resolver( array( $bogo, $price ) );

		// qty 4 → 2 free items → savings 200 vs price rule savings 40.
		$result = $resolver->resolve( $this->product( 100 ), 4, 'cart' );
		$this->assertSame( 'bogo', $result->kind );
		$this->assertNull( $result->unit_price );
		$this->assertSame( 2, $result->bogo['free_quantity'] );
		$this->assertSame( 200.0, $result->savings );

		// qty 1 → no free item → bogo yields nothing → price rule wins.
		$result = $resolver->resolve( $this->product( 100 ), 1, 'cart' );
		$this->assertSame( 'price', $result->kind );
	}

	// ── Caching ─────────────────────────────────────────────────────────

	public function test_result_cached_per_context_product_qty_and_clearable() {
		$db            = new Resolver_Fake_DB();
		$db->discounts = array( $this->discount() );
		$resolver      = new Discount_Resolver( new Resolver_Fake_Matcher(), $db );

		$first = $resolver->resolve( $this->product( 100 ) );
		$this->assertNotNull( $first );

		$db->discounts = array();
		// Same key → cached result survives the source change.
		$this->assertNotNull( $resolver->resolve( $this->product( 100 ) ) );
		// After clear_cache() the new (empty) set is honoured.
		$resolver->clear_cache();
		$this->assertNull( $resolver->resolve( $this->product( 100 ) ) );
	}
}
