<?php
/**
 * Unit tests for Discount_Priority_Resolver (CI-3 cross-engine suppression).
 *
 * Pure-logic tests with injected matcher + DB-handler doubles — no WordPress.
 *
 * @package StoreDash\Tests\Discounts
 */

namespace StoreDash\Tests\Discounts;

use PHPUnit\Framework\TestCase;
use StoreDash\Discounts\Engine\Discount_Priority_Resolver;
use WC_Product;

/**
 * Matcher double: reports whether a discount applies to a product.
 */
final class Fake_Matcher {

	/** @var bool */
	public $applies = true;

	/**
	 * @param object     $discount Discount.
	 * @param WC_Product $product  Product.
	 * @return bool
	 */
	public function discount_applies_to_product( $discount, $product ) {
		return $this->applies;
	}
}

/**
 * DB-handler double: returns a fixed active-discount list.
 */
final class Fake_DB_Handler {

	/** @var array */
	public $discounts = array();

	/**
	 * @param string|null $rule_type Ignored.
	 * @return array
	 */
	public function get_active_discounts( $rule_type = null ) {
		return $this->discounts;
	}
}

/**
 * @covers \StoreDash\Discounts\Engine\Discount_Priority_Resolver
 */
class Discount_Priority_ResolverTest extends TestCase {

	/**
	 * Build a resolver with injected doubles.
	 *
	 * @param array $discounts Active discounts.
	 * @param bool  $applies   Whether the matcher reports applicability.
	 * @return Discount_Priority_Resolver
	 */
	private function make_resolver( array $discounts, $applies = true ) {
		$matcher          = new Fake_Matcher();
		$matcher->applies = $applies;
		$db               = new Fake_DB_Handler();
		$db->discounts    = $discounts;
		return new Discount_Priority_Resolver( $matcher, $db );
	}

	/**
	 * Build a discount stub.
	 *
	 * @param int   $priority Priority number.
	 * @param mixed $flag     disable_lower_priority value.
	 * @return object
	 */
	private function discount( $priority, $flag ) {
		return (object) array(
			'priority'               => $priority,
			'disable_lower_priority' => $flag,
		);
	}

	/**
	 * Build a flagged discount carrying a JSON conditions blob.
	 *
	 * @param int   $priority   Priority number.
	 * @param array $conditions Conditions array (encoded to JSON, as $wpdb returns).
	 * @return object
	 */
	private function discount_with_conditions( $priority, array $conditions ) {
		return (object) array(
			'priority'               => $priority,
			'disable_lower_priority' => 1,
			'conditions'             => json_encode( $conditions ),
		);
	}

	/**
	 * Reset the cart double between tests (default: no cart = display time).
	 */
	protected function setUp(): void {
		parent::setUp();
		\StoreDash_Helpers::$logs = array();
	}

	protected function tearDown(): void {
		$GLOBALS['__test_wc_cart'] = null;
		\StoreDash_Helpers::$logs  = array();
		parent::tearDown();
	}

	/**
	 * Point the WC() cart double at a cart with the given total item count.
	 *
	 * The quantity gate derives its count from cart CONTENTS (skipping BOGO
	 * free-item lines), so the count is modelled as a paid content line.
	 *
	 * @param int $count Cart contents count.
	 */
	private function set_cart_count( $count ) {
		$cart        = new \Fake_WC_Cart();
		$cart->count = $count;
		if ( $count > 0 ) {
			$cart->cart_contents['paid'] = array( 'quantity' => $count );
		}
		$GLOBALS['__test_wc_cart'] = $cart;
		return $cart;
	}

	/**
	 * With no flagged discount, the ceiling is PHP_INT_MAX and nothing is
	 * suppressed — proves the feature is byte-for-byte inert by default.
	 */
	public function test_no_flag_means_no_ceiling_and_no_suppression() {
		$resolver = $this->make_resolver(
			array(
				$this->discount( 1, 0 ),
				$this->discount( 5, 0 ),
			)
		);

		$ceiling = $resolver->get_priority_ceiling( new WC_Product( 101 ) );
		$this->assertSame( PHP_INT_MAX, $ceiling );

		// Even a very low-priority discount is not suppressed.
		$this->assertFalse( $resolver->is_suppressed( $this->discount( 99, 0 ), $ceiling ) );
	}

	/**
	 * A flagged, applicable discount sets the ceiling to its own priority; only
	 * strictly greater priority numbers are suppressed (ties survive).
	 */
	public function test_flagged_discount_sets_ceiling_and_tie_survives() {
		$resolver = $this->make_resolver( array( $this->discount( 2, 1 ) ) );

		$ceiling = $resolver->get_priority_ceiling( new WC_Product( 101 ) );
		$this->assertSame( 2, $ceiling );

		// Strictly greater number (lower priority) → suppressed.
		$this->assertTrue( $resolver->is_suppressed( $this->discount( 5, 0 ), $ceiling ) );
		// Equal number (tie) → NOT suppressed.
		$this->assertFalse( $resolver->is_suppressed( $this->discount( 2, 0 ), $ceiling ) );
		// Smaller number (higher priority) → NOT suppressed.
		$this->assertFalse( $resolver->is_suppressed( $this->discount( 1, 0 ), $ceiling ) );
	}

	/**
	 * When several flagged discounts apply, the smallest priority number wins.
	 */
	public function test_lowest_flagged_priority_wins() {
		$resolver = $this->make_resolver(
			array(
				$this->discount( 3, 1 ),
				$this->discount( 1, 1 ),
				$this->discount( 7, 1 ),
			)
		);

		$this->assertSame( 1, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * A flagged discount that does NOT apply to the product imposes no ceiling.
	 */
	public function test_flagged_but_not_applicable_imposes_no_ceiling() {
		$resolver = $this->make_resolver( array( $this->discount( 1, 1 ) ), false );

		$this->assertSame( PHP_INT_MAX, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * Falsy TINYINT representations of the flag are all treated as "off".
	 *
	 * @dataProvider falsy_flag_provider
	 *
	 * @param mixed $falsy A falsy disable_lower_priority value.
	 */
	public function test_falsy_flag_values_are_inert( $falsy ) {
		$resolver = $this->make_resolver( array( $this->discount( 1, $falsy ) ) );

		$this->assertSame( PHP_INT_MAX, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * @return array<string,array>
	 */
	public function falsy_flag_provider() {
		return array(
			'int zero'    => array( 0 ),
			'string zero' => array( '0' ),
			'false'       => array( false ),
			'empty'       => array( '' ),
			'null'        => array( null ),
		);
	}

	/**
	 * The string '1' (how MySQL TINYINT comes back via $wpdb) is truthy.
	 */
	public function test_string_one_flag_is_active() {
		$resolver = $this->make_resolver( array( $this->discount( 4, '1' ) ) );

		$this->assertSame( 4, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * A non-product argument never imposes a ceiling.
	 */
	public function test_non_product_returns_no_ceiling() {
		$resolver = $this->make_resolver( array( $this->discount( 1, 1 ) ) );

		$this->assertSame( PHP_INT_MAX, $resolver->get_priority_ceiling( null ) );
	}

	/**
	 * is_suppressed is always false for a PHP_INT_MAX ceiling regardless of
	 * priority, and never suppresses an equal/smaller priority number.
	 */
	public function test_is_suppressed_edges() {
		$resolver = $this->make_resolver( array() );

		$this->assertFalse( $resolver->is_suppressed( $this->discount( 100, 0 ), PHP_INT_MAX ) );
		$this->assertTrue( $resolver->is_suppressed( $this->discount( 10, 0 ), 5 ) );
		$this->assertFalse( $resolver->is_suppressed( $this->discount( 5, 0 ), 5 ) );
		$this->assertFalse( $resolver->is_suppressed( $this->discount( 1, 0 ), 5 ) );
	}

	/**
	 * The ceiling is cached per product ID.
	 */
	public function test_ceiling_is_cached_per_product() {
		$db       = new Fake_DB_Handler();
		$db->discounts = array( $this->discount( 2, 1 ) );
		$resolver = new Discount_Priority_Resolver( new Fake_Matcher(), $db );

		$first = $resolver->get_priority_ceiling( new WC_Product( 101 ) );
		$this->assertSame( 2, $first );

		// Mutate the source; the cached product still returns the old ceiling.
		$db->discounts = array();
		$this->assertSame( 2, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );

		// A different product recomputes against the new (empty) set.
		$this->assertSame( PHP_INT_MAX, $resolver->get_priority_ceiling( new WC_Product( 202 ) ) );
	}

	/**
	 * Group-G fix: a flagged quantity discount whose cart-quantity condition is
	 * UNMET at cart-calc time must NOT raise the ceiling, so it cannot
	 * over-suppress a lower-priority discount.
	 */
	public function test_flagged_discount_with_unmet_min_quantity_imposes_no_ceiling() {
		$this->set_cart_count( 1 ); // cart has 1 item …
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'min_quantity' => 3 ) ) ) // … but needs 3.
		);

		$this->assertSame(
			PHP_INT_MAX,
			$resolver->get_priority_ceiling( new WC_Product( 101 ) )
		);
	}

	/**
	 * The mirror case: when the cart-quantity condition IS met, the flagged
	 * discount raises the ceiling exactly as a condition-less flagged discount.
	 */
	public function test_flagged_discount_with_met_min_quantity_sets_ceiling() {
		$this->set_cart_count( 5 ); // 5 >= min 3 → condition met.
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'min_quantity' => 3 ) ) )
		);

		$this->assertSame( 2, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * A flagged discount whose max_quantity is exceeded imposes no ceiling.
	 */
	public function test_flagged_discount_with_exceeded_max_quantity_imposes_no_ceiling() {
		$this->set_cart_count( 9 ); // 9 > max 4 → condition unmet.
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'max_quantity' => 4 ) ) )
		);

		$this->assertSame(
			PHP_INT_MAX,
			$resolver->get_priority_ceiling( new WC_Product( 101 ) )
		);
	}

	/**
	 * Regression (MF-3): the quantity gate must count PAID lines only — a BOGO
	 * free-item line must neither break a max_quantity ceiling nor satisfy a
	 * min_quantity one.
	 */
	public function test_quantity_ceiling_gates_exclude_bogo_free_items() {
		// 2 paid + 1 free line: contents count 3, paid quantity 2.
		$cart                        = $this->set_cart_count( 2 );
		$cart->cart_contents['free'] = array(
			'quantity'               => 1,
			'storedash_is_free_item' => true,
		);
		$cart->count = 3; // Real Woo counts the free line too.

		// max_quantity=2: paid 2 <= 2 → condition met → ceiling set.
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'max_quantity' => 2 ) ) )
		);
		$this->assertSame( 2, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );

		// min_quantity=3: paid 2 < 3 → condition unmet → no ceiling.
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'min_quantity' => 3 ) ) )
		);
		$this->assertSame( PHP_INT_MAX, $resolver->get_priority_ceiling( new WC_Product( 102 ) ) );
	}

	/**
	 * Display-time invariance: with NO cart (the default), a flagged discount that
	 * carries a quantity condition raises the ceiling exactly as before — the gate
	 * is not-evaluable and treated as met, so display pricing is byte-unchanged.
	 */
	public function test_quantity_condition_not_evaluated_without_a_cart() {
		// No set_cart_count(): $GLOBALS['__test_wc_cart'] is null = display time.
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'min_quantity' => 3 ) ) )
		);

		$this->assertSame( 2, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * Build a paid cart line at the given regular price and quantity — enough for
	 * cart_base_subtotal (reads data->get_regular_price() and quantity).
	 *
	 * @param float $regular_price Per-unit regular price.
	 * @param int   $quantity      Line quantity.
	 * @return array
	 */
	private function cart_line( $regular_price, $quantity ) {
		$product                = new WC_Product( 1 );
		$product->regular_price = (string) $regular_price;
		return array(
			'data'     => $product,
			'quantity' => $quantity,
		);
	}

	/**
	 * Point the WC() cart double at a cart with explicit contents + coupons.
	 *
	 * @param array $contents Cart contents (each an array with data/quantity).
	 * @param array $coupons  Applied coupon codes.
	 */
	private function set_cart( array $contents, array $coupons = array() ) {
		$cart                  = new \Fake_WC_Cart();
		$cart->cart_contents   = $contents;
		$cart->applied_coupons = $coupons;
		$GLOBALS['__test_wc_cart'] = $cart;
	}

	/**
	 * MF-6: a flagged discount whose min_cart_total is UNMET at cart-calc time must
	 * NOT raise the ceiling — otherwise it over-suppresses a lower-priority discount
	 * while itself being dropped by is_eligible, and the customer pays full price.
	 */
	public function test_flagged_discount_with_unmet_min_cart_total_imposes_no_ceiling() {
		$this->set_cart( array( $this->cart_line( 50, 1 ) ) ); // subtotal 50 …
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'min_cart_total' => 100 ) ) ) // … needs 100.
		);

		$this->assertSame(
			PHP_INT_MAX,
			$resolver->get_priority_ceiling( new WC_Product( 101 ) )
		);
	}

	/**
	 * The mirror case: when min_cart_total IS met, the flagged discount raises the
	 * ceiling exactly as a condition-less flagged discount.
	 */
	public function test_flagged_discount_with_met_min_cart_total_sets_ceiling() {
		$this->set_cart( array( $this->cart_line( 100, 2 ) ) ); // subtotal 200 >= 100.
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'min_cart_total' => 100 ) ) )
		);

		$this->assertSame( 2, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * A flagged discount whose max_cart_total is exceeded imposes no ceiling.
	 */
	public function test_flagged_discount_with_exceeded_max_cart_total_imposes_no_ceiling() {
		$this->set_cart( array( $this->cart_line( 300, 1 ) ) ); // subtotal 300 > max 200.
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'max_cart_total' => 200 ) ) )
		);

		$this->assertSame(
			PHP_INT_MAX,
			$resolver->get_priority_ceiling( new WC_Product( 101 ) )
		);
	}

	/**
	 * MF-6: a flagged discount carrying disable_with_coupons must NOT raise the
	 * ceiling when a coupon is applied — is_eligible drops it, so its ceiling must
	 * not survive to suppress a lower-priority winner.
	 */
	public function test_flagged_discount_with_disable_with_coupons_and_coupon_present_imposes_no_ceiling() {
		$this->set_cart( array( $this->cart_line( 50, 1 ) ), array( 'SUMMER10' ) );
		$resolver = $this->make_resolver(
			array(
				(object) array(
					'priority'               => 2,
					'disable_lower_priority' => 1,
					'disable_with_coupons'   => 1,
				),
			)
		);

		$this->assertSame(
			PHP_INT_MAX,
			$resolver->get_priority_ceiling( new WC_Product( 101 ) )
		);
	}

	/**
	 * The mirror case: disable_with_coupons flagged discount with NO coupon applied
	 * raises the ceiling normally.
	 */
	public function test_flagged_discount_with_disable_with_coupons_but_no_coupon_sets_ceiling() {
		$this->set_cart( array( $this->cart_line( 50, 1 ) ), array() ); // no coupons.
		$resolver = $this->make_resolver(
			array(
				(object) array(
					'priority'               => 2,
					'disable_lower_priority' => 1,
					'disable_with_coupons'   => 1,
				),
			)
		);

		$this->assertSame( 2, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * Display-time invariance for cart-total conditions: with NO cart, a flagged
	 * discount carrying min_cart_total raises the ceiling exactly as before.
	 */
	public function test_cart_total_condition_not_evaluated_without_a_cart() {
		// No set_cart(): $GLOBALS['__test_wc_cart'] is null = display time.
		$resolver = $this->make_resolver(
			array( $this->discount_with_conditions( 2, array( 'min_cart_total' => 100 ) ) )
		);

		$this->assertSame( 2, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );
	}

	/**
	 * Build a flagged discount carrying a raw (possibly corrupt) conditions blob.
	 *
	 * @param int   $priority Priority number.
	 * @param mixed $raw      Raw conditions value as stored (string/null).
	 * @return object
	 */
	private function discount_with_raw_conditions( $priority, $raw ) {
		return (object) array(
			'id'                     => 7,
			'priority'               => $priority,
			'disable_lower_priority' => 1,
			'conditions'             => $raw,
		);
	}

	// ── Corrupt conditions blob fails CLOSED ────────────────────────────

	/**
	 * A flagged discount with a corrupt conditions blob must NOT raise the
	 * ceiling at cart-calc time — fail closed — and it logs once.
	 */
	public function test_truncated_conditions_json_imposes_no_ceiling_and_logs() {
		$this->set_cart( array( $this->cart_line( 100, 1 ) ) );
		$resolver = $this->make_resolver(
			array( $this->discount_with_raw_conditions( 2, '{"min_cart_total":' ) )
		);

		$this->assertSame(
			PHP_INT_MAX,
			$resolver->get_priority_ceiling( new WC_Product( 101 ) )
		);
		$this->assertCount( 1, \StoreDash_Helpers::$logs );
		$this->assertSame( 'error', \StoreDash_Helpers::$logs[0]['level'] );
	}

	/**
	 * A flagged discount whose conditions decode to a JSON scalar fails closed.
	 */
	public function test_scalar_conditions_json_imposes_no_ceiling() {
		$this->set_cart( array( $this->cart_line( 100, 1 ) ) );
		$resolver = $this->make_resolver(
			array( $this->discount_with_raw_conditions( 2, '5' ) )
		);

		$this->assertSame(
			PHP_INT_MAX,
			$resolver->get_priority_ceiling( new WC_Product( 101 ) )
		);
		$this->assertCount( 1, \StoreDash_Helpers::$logs );
	}

	/**
	 * Genuinely-empty conditions ('' or null) are unchanged: the flagged discount
	 * still raises the ceiling and nothing is logged.
	 */
	public function test_empty_conditions_still_raise_ceiling_without_logging() {
		$this->set_cart( array( $this->cart_line( 100, 1 ) ) );

		$resolver = $this->make_resolver(
			array( $this->discount_with_raw_conditions( 2, '' ) )
		);
		$this->assertSame( 2, $resolver->get_priority_ceiling( new WC_Product( 101 ) ) );

		$resolver_null = $this->make_resolver(
			array( $this->discount_with_raw_conditions( 3, null ) )
		);
		$this->assertSame( 3, $resolver_null->get_priority_ceiling( new WC_Product( 202 ) ) );

		$this->assertCount( 0, \StoreDash_Helpers::$logs );
	}
}
