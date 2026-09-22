<?php
/**
 * Unit tests for Cart_Discount_Orchestrator — single cart application point.
 *
 * @package StoreDash\Tests\Discounts
 */

namespace StoreDash\Tests\Discounts;

use PHPUnit\Framework\TestCase;
use StoreDash\Discounts\Engine\Cart_Discount_Orchestrator;
use WC_Product;

/**
 * Resolver double: returns queued results per product id (cart context).
 */
final class Fake_Resolver {

	/** @var array<int,object|null> keyed by product id */
	public $results = array();
	/** @var int */
	public $clear_calls = 0;
	/** @var array Captured resolve() calls. */
	public $calls = array();

	public function resolve( $product, $quantity = 1, $context = 'display' ) {
		$this->calls[] = compact( 'quantity', 'context' );
		$id            = $product->get_id();
		return isset( $this->results[ $id ] ) ? $this->results[ $id ] : null;
	}

	public function clear_cache() {
		$this->clear_calls++;
	}
}

/**
 * BOGO engine double: captures apply_bogo_line calls.
 */
final class Fake_Bogo_Engine {

	/** @var array */
	public $applied = array();

	public function apply_bogo_line( $cart, $cart_item_key, $cart_item, $result ) {
		$this->applied[ $cart_item_key ] = $result;
	}
}

/**
 * BOGO engine double that re-enters the orchestrator once — modelling how
 * apply_bogo_line → add_free_item_to_cart → WC_Cart::add_to_cart re-fires
 * woocommerce_before_calculate_totals inside the same run.
 */
final class Reentrant_Bogo_Engine {

	/** @var Cart_Discount_Orchestrator|null */
	public $orchestrator = null;
	/** @var int Number of re-entrant invocations attempted. */
	public $reenter_count = 0;

	public function apply_bogo_line( $cart, $cart_item_key, $cart_item, $result ) {
		// Fire exactly one re-entrant call; a working is_processing guard makes it
		// a no-op, a broken one would recurse infinitely without this counter.
		if ( $this->orchestrator && 0 === $this->reenter_count ) {
			$this->reenter_count++;
			$this->orchestrator->apply_discounts_to_cart( $cart );
		}
	}
}

/**
 * BOGO engine double that throws, modelling a failure mid-application
 * (e.g. add_to_cart raising) so we can assert the re-entrancy guard resets.
 */
final class Throwing_Bogo_Engine {

	public function apply_bogo_line( $cart, $cart_item_key, $cart_item, $result ) {
		throw new \RuntimeException( 'boom' );
	}
}

/**
 * @covers \StoreDash\Discounts\Engine\Cart_Discount_Orchestrator
 */
class Cart_Discount_OrchestratorTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['__test_wc_cart'] = null;
		parent::tearDown();
	}

	private function line( $product_id, $regular, $qty = 1, array $extra = array() ) {
		$product                = new WC_Product( $product_id );
		$product->regular_price = (string) $regular;
		$product->price         = (string) $regular;
		return array_merge(
			array(
				'product_id' => $product_id,
				'quantity'   => $qty,
				'data'       => $product,
			),
			$extra
		);
	}

	private function price_result( $discount_id, $unit, $base ) {
		return (object) array(
			'discount'   => (object) array( 'id' => $discount_id, 'name' => 'Test' ),
			'kind'       => 'price',
			'unit_price' => (float) $unit,
			'base_price' => (float) $base,
			'savings'    => (float) ( $base - $unit ),
			'bogo'       => null,
		);
	}

	private function make( array $results = array() ) {
		$resolver          = new Fake_Resolver();
		$resolver->results = $results;
		$bogo              = new Fake_Bogo_Engine();
		return array( new Cart_Discount_Orchestrator( $resolver, $bogo ), $resolver, $bogo );
	}

	public function test_price_winner_sets_line_price_and_marker() {
		list( $orchestrator ) = $this->make( array( 101 => $this->price_result( 1, 80.0, 100.0 ) ) );
		$cart                 = new \Fake_WC_Cart();
		$cart->cart_contents  = array( 'key1' => $this->line( 101, 100 ) );

		$orchestrator->apply_discounts_to_cart( $cart );

		$this->assertSame( '80', $cart->cart_contents['key1']['data']->get_price() );
		$this->assertSame( 1, $cart->cart_contents['key1']['storedash_discount_applied']['discount_id'] );
	}

	public function test_quantity_winner_also_writes_legacy_display_key() {
		$result       = $this->price_result( 2, 80.0, 100.0 );
		$result->kind = 'quantity';
		list( $orchestrator ) = $this->make( array( 101 => $result ) );
		$cart                 = new \Fake_WC_Cart();
		$cart->cart_contents  = array( 'key1' => $this->line( 101, 100, 3 ) );

		$orchestrator->apply_discounts_to_cart( $cart );

		$this->assertArrayHasKey( 'storedash_quantity_discount', $cart->cart_contents['key1'] );
	}

	public function test_no_winner_restores_previously_discounted_line() {
		list( $orchestrator ) = $this->make();
		$line                 = $this->line( 101, 100, 1, array( 'storedash_discount_applied' => array( 'discount_id' => 9 ) ) );
		$line['data']->set_price( 80 ); // Previously discounted this request.
		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'key1' => $line );

		$orchestrator->apply_discounts_to_cart( $cart );

		$this->assertSame( '100', $cart->cart_contents['key1']['data']->get_price() );
		$this->assertArrayNotHasKey( 'storedash_discount_applied', $cart->cart_contents['key1'] );
	}

	public function test_no_winner_leaves_untouched_lines_alone() {
		list( $orchestrator ) = $this->make();
		$line                 = $this->line( 101, 100 );
		$line['data']->set_price( 77 ); // Foreign price (another plugin) — no marker.
		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'key1' => $line );

		$orchestrator->apply_discounts_to_cart( $cart );

		$this->assertSame( '77', $cart->cart_contents['key1']['data']->get_price() );
	}

	public function test_bogo_winner_delegates_and_orphan_free_items_are_swept() {
		$bogo_result = (object) array(
			'discount'   => (object) array( 'id' => 5, 'name' => 'B2G1' ),
			'kind'       => 'bogo',
			'unit_price' => null,
			'base_price' => 100.0,
			'savings'    => 100.0,
			'bogo'       => array( 'buy_quantity' => 2, 'get_quantity' => 1, 'discount_percent' => 100.0, 'free_quantity' => 1 ),
		);
		list( $orchestrator, , $bogo_engine ) = $this->make( array( 101 => $bogo_result ) );

		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array(
			'parent1' => $this->line( 101, 100, 2 ),
			// Free item whose parent no longer wins any bogo (rule was disabled).
			'free_orphan' => $this->line(
				102,
				50,
				1,
				array(
					'storedash_is_free_item' => true,
					'storedash_bogo_parent'  => 'gone_parent',
				)
			),
		);

		$orchestrator->apply_discounts_to_cart( $cart );

		$this->assertArrayHasKey( 'parent1', $bogo_engine->applied );
		$this->assertContains( 'free_orphan', $cart->removed );
	}

	public function test_free_items_are_not_resolved() {
		// p1 wins a BOGO rule, so free1's parent is a legitimate bogo winner and
		// the orphan-sweep keeps it (free items only ever arise from bogo rules).
		$bogo_result = (object) array(
			'discount'   => (object) array( 'id' => 1, 'name' => 'B2G1' ),
			'kind'       => 'bogo',
			'unit_price' => null,
			'base_price' => 100.0,
			'savings'    => 100.0,
			'bogo'       => array( 'buy_quantity' => 2, 'get_quantity' => 1, 'discount_percent' => 100.0, 'free_quantity' => 1 ),
		);
		list( $orchestrator, $resolver ) = $this->make(
			array( 101 => $bogo_result )
		);
		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array(
			'free1' => $this->line( 101, 100, 1, array( 'storedash_is_free_item' => true, 'storedash_bogo_parent' => 'p1' ) ),
			'p1'    => $this->line( 101, 100, 2 ),
		);

		$orchestrator->apply_discounts_to_cart( $cart );

		// Free line is skipped by resolution (never gets a discount marker).
		$this->assertArrayNotHasKey( 'storedash_discount_applied', $cart->cart_contents['free1'] );
		// clear_cache called exactly once per (non-reentrant) invocation.
		$this->assertSame( 1, $resolver->clear_calls );
	}

	/**
	 * CI-47: the is_processing guard actually short-circuits a re-entrant call.
	 * The BOGO engine re-invokes apply_discounts_to_cart mid-run (as WC's
	 * add_to_cart re-fires before_calculate_totals); the guard must make that
	 * inner call return before it clears the cache or re-resolves any line.
	 */
	public function test_reentrant_apply_is_short_circuited_by_processing_guard() {
		$bogo_result = (object) array(
			'discount'   => (object) array( 'id' => 1, 'name' => 'B2G1' ),
			'kind'       => 'bogo',
			'unit_price' => null,
			'base_price' => 100.0,
			'savings'    => 100.0,
			'bogo'       => array( 'buy_quantity' => 2, 'get_quantity' => 1, 'discount_percent' => 100.0, 'free_quantity' => 1 ),
		);
		$resolver          = new Fake_Resolver();
		$resolver->results = array( 101 => $bogo_result );
		$engine            = new Reentrant_Bogo_Engine();
		$orchestrator      = new Cart_Discount_Orchestrator( $resolver, $engine );
		$engine->orchestrator = $orchestrator;

		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'p1' => $this->line( 101, 100, 2 ) );

		$orchestrator->apply_discounts_to_cart( $cart );

		// The engine attempted exactly one re-entrant call …
		$this->assertSame( 1, $engine->reenter_count );
		// … which the guard short-circuited: clear_cache ran only once. Without the
		// guard the re-entrant invocation would clear the cache a second time.
		$this->assertSame( 1, $resolver->clear_calls );
	}

	/**
	 * A throw mid-application must reset is_processing (try/finally). Otherwise the
	 * guard stays stuck true and every later calculate_totals silently skips ALL
	 * discounts, charging customers full price.
	 */
	public function test_guard_resets_after_exception_so_later_runs_still_apply() {
		$bogo_result = (object) array(
			'discount'   => (object) array( 'id' => 5, 'name' => 'B2G1' ),
			'kind'       => 'bogo',
			'unit_price' => null,
			'base_price' => 100.0,
			'savings'    => 100.0,
			'bogo'       => array( 'buy_quantity' => 2, 'get_quantity' => 1, 'discount_percent' => 100.0, 'free_quantity' => 1 ),
		);
		$resolver          = new Fake_Resolver();
		$resolver->results = array( 101 => $bogo_result );
		$orchestrator      = new Cart_Discount_Orchestrator( $resolver, new Throwing_Bogo_Engine() );

		$cart                = new \Fake_WC_Cart();
		$cart->cart_contents = array( 'p1' => $this->line( 101, 100, 2 ) );

		try {
			$orchestrator->apply_discounts_to_cart( $cart );
			$this->fail( 'Expected apply_discounts_to_cart to propagate the engine exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		// Guard reset: a subsequent normal run still resolves and applies a discount.
		$resolver->results = array( 202 => $this->price_result( 7, 80.0, 100.0 ) );
		$cart2             = new \Fake_WC_Cart();
		$cart2->cart_contents = array( 'k' => $this->line( 202, 100 ) );

		$orchestrator->apply_discounts_to_cart( $cart2 );

		$this->assertSame( '80', $cart2->cart_contents['k']['data']->get_price() );
	}

	/**
	 * Cart with the given lines installed as WC()->cart.
	 */
	private function install_cart( array $lines ) {
		$cart                      = new \Fake_WC_Cart();
		$cart->cart_contents       = $lines;
		$GLOBALS['__test_wc_cart'] = $cart;
		return $cart;
	}

	public function test_discounted_cart_line_reports_on_sale_for_coupon_validation() {
		list( $orchestrator, $resolver ) = $this->make( array( 101 => $this->price_result( 1, 80.0, 100.0 ) ) );
		$cart                            = $this->install_cart( array( 'key1' => $this->line( 101, 100, 3 ) ) );

		$this->assertTrue( $orchestrator->filter_cart_line_is_on_sale( false, $cart->cart_contents['key1']['data'] ) );
		// Resolved in cart context with the line's own quantity (tier rules).
		$this->assertSame( array( array( 'quantity' => 3, 'context' => 'cart' ) ), $resolver->calls );
	}

	public function test_quantity_tier_winner_counts_as_on_sale() {
		$result       = $this->price_result( 2, 80.0, 100.0 );
		$result->kind = 'quantity';
		list( $orchestrator ) = $this->make( array( 101 => $result ) );
		$cart                 = $this->install_cart( array( 'key1' => $this->line( 101, 100, 5 ) ) );

		$this->assertTrue( $orchestrator->filter_cart_line_is_on_sale( false, $cart->cart_contents['key1']['data'] ) );
	}

	public function test_undiscounted_cart_line_is_not_on_sale() {
		list( $orchestrator ) = $this->make();
		$cart                 = $this->install_cart( array( 'key1' => $this->line( 101, 100 ) ) );

		$this->assertFalse( $orchestrator->filter_cart_line_is_on_sale( false, $cart->cart_contents['key1']['data'] ) );
	}

	public function test_merchant_sale_short_circuits_without_resolving() {
		list( $orchestrator, $resolver ) = $this->make();
		$cart                            = $this->install_cart( array( 'key1' => $this->line( 101, 100 ) ) );

		$this->assertTrue( $orchestrator->filter_cart_line_is_on_sale( true, $cart->cart_contents['key1']['data'] ) );
		$this->assertSame( array(), $resolver->calls );
	}

	public function test_product_object_outside_the_cart_is_untouched() {
		// Same product id as a discounted cart line, but a different object — a
		// catalog/Store API product, whose on-sale state is not ours to decide here.
		list( $orchestrator, $resolver ) = $this->make( array( 101 => $this->price_result( 1, 80.0, 100.0 ) ) );
		$this->install_cart( array( 'key1' => $this->line( 101, 100 ) ) );

		$this->assertFalse( $orchestrator->filter_cart_line_is_on_sale( false, new WC_Product( 101 ) ) );
		$this->assertSame( array(), $resolver->calls );
	}

	public function test_no_cart_leaves_on_sale_unchanged() {
		list( $orchestrator ) = $this->make( array( 101 => $this->price_result( 1, 80.0, 100.0 ) ) );

		$this->assertFalse( $orchestrator->filter_cart_line_is_on_sale( false, new WC_Product( 101 ) ) );
	}

	public function test_bogo_paid_line_and_free_item_are_not_on_sale() {
		$bogo_result = (object) array(
			'discount'   => (object) array( 'id' => 5, 'name' => 'B2G1' ),
			'kind'       => 'bogo',
			'unit_price' => null,
			'base_price' => 100.0,
			'savings'    => 100.0,
			'bogo'       => array( 'buy_quantity' => 2, 'get_quantity' => 1, 'discount_percent' => 100.0, 'free_quantity' => 1 ),
		);
		list( $orchestrator ) = $this->make( array( 101 => $bogo_result, 102 => $this->price_result( 1, 80.0, 100.0 ) ) );
		$cart                 = $this->install_cart(
			array(
				'p1'    => $this->line( 101, 100, 2 ),
				'free1' => $this->line( 102, 100, 1, array( 'storedash_is_free_item' => true, 'storedash_bogo_parent' => 'p1' ) ),
			)
		);

		$this->assertFalse( $orchestrator->filter_cart_line_is_on_sale( false, $cart->cart_contents['p1']['data'] ) );
		$this->assertFalse( $orchestrator->filter_cart_line_is_on_sale( false, $cart->cart_contents['free1']['data'] ) );
	}
}
