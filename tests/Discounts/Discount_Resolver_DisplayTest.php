<?php
/**
 * Display-surface resolution tests for Discount_Resolver.
 *
 * A variable product's own regular price is empty (its variations hold the
 * money), so resolve() on the parent always comes back null. Every display
 * surface — the sale badge, the catalog price HTML, and the Store API prices a
 * headless storefront reads — must go through resolve_for_display(), which
 * follows the cheapest variation instead.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Discounts\Engine\Discount_Resolver;

/**
 * Variable-product double: no price of its own, variations carry the prices.
 */
class Fake_Variable_Product extends WC_Product {

	/** @var array Price data in get_variation_prices() shape. */
	public $variation_prices = array( 'price' => array() );

	public function __construct( $id = 0 ) {
		parent::__construct( $id );
		$this->type          = 'variable';
		$this->regular_price = '';
	}

	public function get_variation_prices( $for_display = false ) {
		return $this->variation_prices;
	}
}

/**
 * Resolver with resolve() stubbed, so these tests cover the display-surface
 * routing rather than re-testing the rulebook.
 */
class Test_Display_Resolver extends Discount_Resolver {

	/** @var array product_id => result object|null */
	public $results = array();

	/** @var int[] IDs resolve() was called with. */
	public $asked = array();

	public function __construct() {}

	public function resolve( $product, $quantity = 1, $context = 'display' ) {
		$this->asked[] = $product->get_id();
		return $this->results[ $product->get_id() ] ?? null;
	}
}

/**
 * @covers \StoreDash\Discounts\Engine\Discount_Resolver
 */
class Discount_Resolver_DisplayTest extends TestCase {

	/** @var Test_Display_Resolver */
	protected $resolver;

	protected function setUp(): void {
		parent::setUp();

		$this->resolver             = new Test_Display_Resolver();
		$GLOBALS['__test_products'] = array();
	}

	/**
	 * Build a resolver result double.
	 *
	 * @param float $unit Discounted unit price.
	 * @param float $base Pre-discount price.
	 * @return object
	 */
	protected function result( $unit, $base ) {
		return (object) array(
			'unit_price' => $unit,
			'base_price' => $base,
			'kind'       => 'price',
		);
	}

	/**
	 * Register a variable product whose variations sit at the given prices.
	 *
	 * @param int   $id     Variable product ID.
	 * @param array $prices variation_id => price string, cheapest first.
	 * @return Fake_Variable_Product
	 */
	protected function variable( $id, array $prices ) {
		$variable                          = new Fake_Variable_Product( $id );
		$variable->variation_prices        = array( 'price' => $prices );
		$GLOBALS['__test_products'][ $id ] = $variable;

		foreach ( array_keys( $prices ) as $variation_id ) {
			$variation                                   = new WC_Product( $variation_id );
			$variation->parent_id                        = $id;
			$GLOBALS['__test_products'][ $variation_id ] = $variation;
		}

		return $variable;
	}

	public function test_simple_product_resolves_against_itself() {
		$product                     = new WC_Product( 10 );
		$this->resolver->results[10] = $this->result( 100.0, 1000.0 );

		$this->assertSame(
			$this->resolver->results[10],
			$this->resolver->resolve_for_display( $product )
		);
		$this->assertSame( array( 10 ), $this->resolver->asked );
	}

	/**
	 * The regression: the parent resolves to null, so badges vanished and the
	 * Store API reported no discount at all.
	 */
	public function test_variable_product_resolves_against_its_cheapest_variation() {
		$variable = $this->variable( 20, array( 21 => '999.00', 22 => '5999.00' ) );

		$this->resolver->results[21] = $this->result( 99.9, 999.0 );

		$this->assertSame(
			$this->resolver->results[21],
			$this->resolver->resolve_for_display( $variable )
		);
		$this->assertNotContains( 20, $this->resolver->asked, 'The variable parent has no price to resolve.' );
	}

	public function test_variable_product_can_resolve_against_its_most_expensive_variation() {
		$variable                    = $this->variable( 25, array( 26 => '999.00', 27 => '5999.00' ) );
		$this->resolver->results[27] = $this->result( 599.9, 5999.0 );

		$this->assertSame(
			$this->resolver->results[27],
			$this->resolver->resolve_variation( $variable, 'max' )
		);
	}

	public function test_variable_product_without_a_discounted_variation_resolves_to_null() {
		$variable = $this->variable( 30, array( 31 => '999.00' ) );

		$this->assertNull( $this->resolver->resolve_for_display( $variable ) );
	}

	public function test_variable_product_with_no_variations_resolves_to_null() {
		$variable = $this->variable( 40, array() );

		$this->assertNull( $this->resolver->resolve_for_display( $variable ) );
	}

	public function test_non_product_input_resolves_to_null() {
		$this->assertNull( $this->resolver->resolve_for_display( null ) );
		$this->assertNull( $this->resolver->resolve_variation( 'not a product' ) );
	}

	/**
	 * Badge, price HTML and the Store API price filters all ask about the same
	 * product within one request; the representative variation is resolved once.
	 */
	public function test_repeated_lookups_resolve_the_variation_once() {
		$variable                    = $this->variable( 50, array( 51 => '999.00' ) );
		$this->resolver->results[51] = $this->result( 99.9, 999.0 );

		$this->resolver->resolve_for_display( $variable );
		$this->resolver->resolve_for_display( $variable );

		$this->assertSame( array( 51 ), $this->resolver->asked );
	}

	/**
	 * A miss is cached too — four filters asking about an undiscounted variable
	 * product must not each re-read its variations.
	 */
	public function test_repeated_lookups_cache_a_miss() {
		$variable = $this->variable( 60, array( 61 => '999.00' ) );

		$this->resolver->resolve_for_display( $variable );
		$this->resolver->resolve_for_display( $variable );

		$this->assertSame( array( 61 ), $this->resolver->asked );
	}
}
