<?php
/**
 * Unit tests for Bogo_Discount_Rule free-item price mechanics.
 *
 * @package StoreDash\Tests\Discounts
 */

namespace StoreDash\Tests\Discounts;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StoreDash\Discounts\Engine\Bogo_Discount_Rule;
use WC_Product;

require_once __DIR__ . '/../../inc/Discounts/Engine/Bogo_Discount_Rule.php';

/**
 * @covers \StoreDash\Discounts\Engine\Bogo_Discount_Rule::set_free_item_price
 */
class Bogo_Discount_RuleTest extends TestCase {

	/**
	 * Build the rule without its DB-touching constructor.
	 */
	private function make_rule(): Bogo_Discount_Rule {
		return ( new ReflectionClass( Bogo_Discount_Rule::class ) )->newInstanceWithoutConstructor();
	}

	private function free_line( $product_id, $regular, $percent ) {
		$product                = new WC_Product( $product_id );
		$product->regular_price = (string) $regular;
		$product->price         = (string) $regular;
		return array(
			'product_id'                 => $product_id,
			'quantity'                   => 1,
			'data'                       => $product,
			'storedash_is_free_item'     => true,
			'storedash_discount_percent' => $percent,
		);
	}

	public function test_full_bogo_free_item_priced_at_zero() {
		$rule                = $this->make_rule();
		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'free1' => $this->free_line( 101, 100, 100 ) );

		$rule->set_free_item_price( $cart );

		$this->assertSame( '0', $cart->cart_contents['free1']['data']->get_price() );
	}

	public function test_partial_bogo_is_idempotent_across_repeated_firings() {
		// Regression: before_calculate_totals fires multiple times per request.
		// Deriving from get_price() compounded a partial discount (50 → 25 → …);
		// deriving from the regular price keeps it fixed at 50% off every firing.
		$rule                = $this->make_rule();
		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'free1' => $this->free_line( 101, 100, 50 ) );

		$rule->set_free_item_price( $cart );
		$this->assertSame( '50', $cart->cart_contents['free1']['data']->get_price() );

		// Second + third firing must NOT compound the reduction.
		$rule->set_free_item_price( $cart );
		$rule->set_free_item_price( $cart );
		$this->assertSame( '50', $cart->cart_contents['free1']['data']->get_price() );
	}

	public function test_sale_priced_free_item_uses_sale_as_base() {
		$rule                     = $this->make_rule();
		$line                     = $this->free_line( 101, 100, 50 );
		$line['data']->sale_price = '60';
		$cart                     = new \Fake_WC_Cart();
		$cart->cart_contents      = array( 'free1' => $line );

		$rule->set_free_item_price( $cart );

		// 50% off the 60 sale base = 30 (not off the 100 regular).
		$this->assertSame( '30', $cart->cart_contents['free1']['data']->get_price() );
	}

	/**
	 * Seed the rule's discount_cache so sync_free_item_quantity never touches the
	 * DB handler (which newInstanceWithoutConstructor leaves unset).
	 *
	 * @param Bogo_Discount_Rule $rule        Rule instance.
	 * @param int                $discount_id Discount ID keyed in the cache.
	 * @param int                $buy_qty     buy_quantity in rule_config.
	 * @param int                $get_qty     get_quantity in rule_config.
	 */
	private function seed_discount_cache( $rule, $discount_id, $buy_qty, $get_qty ) {
		$discount = (object) array(
			'rule_config' => json_encode(
				array(
					'buy_quantity' => $buy_qty,
					'get_quantity' => $get_qty,
				)
			),
		);

		$prop = ( new ReflectionClass( Bogo_Discount_Rule::class ) )->getProperty( 'discount_cache' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( $rule, array( $discount_id => $discount ) );
	}

	/**
	 * Build a free-item cart line tied to a parent key + BOGO rule.
	 *
	 * @param string $parent_key Parent cart-item key.
	 * @param int    $rule_id    BOGO discount ID.
	 * @param int    $quantity   Current free-item quantity.
	 * @return array
	 */
	private function free_child_line( $parent_key, $rule_id, $quantity = 1 ) {
		return array(
			'quantity'                => $quantity,
			'storedash_is_free_item'  => true,
			'storedash_bogo_parent'   => $parent_key,
			'storedash_bogo_rule_id'  => $rule_id,
		);
	}

	/**
	 * CI-46: above the buy threshold, the free line is re-quantified to
	 * floor(parent_qty / buy) * get via set_quantity, and not removed.
	 */
	public function test_sync_sets_free_quantity_from_floor_division() {
		$rule = $this->make_rule();
		$this->seed_discount_cache( $rule, 7, 2, 1 ); // buy 2, get 1.

		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'free1' => $this->free_child_line( 'parent1', 7, 1 ) );

		// Parent quantity now 4 → floor(4/2)*1 = 2 free items.
		$rule->sync_free_item_quantity( 'parent1', 4, 1, $cart );

		// floor() yields a float, so set_quantity receives 2.0 (loose-equals 2).
		$this->assertEquals( 2, $cart->cart_contents['free1']['quantity'] );
		$this->assertSame( array(), $cart->removed );
	}

	/**
	 * CI-46: when the recomputed free quantity drops to 0, the free line is
	 * removed rather than set to a zero quantity.
	 */
	public function test_sync_removes_free_item_when_quantity_drops_to_zero() {
		$rule = $this->make_rule();
		$this->seed_discount_cache( $rule, 7, 2, 1 ); // buy 2, get 1.

		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'free1' => $this->free_child_line( 'parent1', 7, 1 ) );

		// Parent quantity now 1 → floor(1/2)*1 = 0 free items → remove.
		$rule->sync_free_item_quantity( 'parent1', 1, 2, $cart );

		$this->assertSame( array( 'free1' ), $cart->removed );
		$this->assertArrayNotHasKey( 'free1', $cart->cart_contents );
	}

	/**
	 * CI-46: a free line whose parent key does not match is left untouched.
	 */
	public function test_sync_ignores_free_items_of_other_parents() {
		$rule = $this->make_rule();
		$this->seed_discount_cache( $rule, 7, 2, 1 );

		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'free1' => $this->free_child_line( 'parentX', 7, 1 ) );

		$rule->sync_free_item_quantity( 'parent1', 4, 1, $cart );

		$this->assertSame( 1, $cart->cart_contents['free1']['quantity'] );
		$this->assertSame( array(), $cart->removed );
	}
}
