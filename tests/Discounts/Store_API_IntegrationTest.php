<?php
/**
 * Unit tests for Store_API_Integration cart extension callbacks.
 *
 * Locks MF-2 (helper audit 2026-07-31): price-kind winners written by
 * Cart_Discount_Orchestrator as `storedash_discount_applied` must surface in
 * the Store API extension data — and quantity winners, which carry BOTH the
 * canonical key and the legacy `storedash_quantity_discount` mirror, must not
 * double-count savings or duplicate summary entries.
 *
 * @package StoreDash\Tests\Discounts
 */

namespace StoreDash\Tests\Discounts;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StoreDash\Discounts\Blocks\Store_API_Integration;
use WC_Product;

require_once __DIR__ . '/../../inc/Discounts/Blocks/Store_API_Integration.php';

/**
 * @covers \StoreDash\Discounts\Blocks\Store_API_Integration
 */
class Store_API_IntegrationTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['__test_wc_cart'] = null;
		parent::tearDown();
	}

	/**
	 * Instance without the constructor (which registers Store API endpoint
	 * data and REST filters we don't want in unit tests).
	 *
	 * @return Store_API_Integration
	 */
	private function make_integration() {
		return ( new ReflectionClass( Store_API_Integration::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Orchestrator-shaped applied payload.
	 *
	 * @param string $kind 'price' or 'quantity'.
	 * @return array
	 */
	private function applied( $kind, $id = 7, $savings = 5.0 ) {
		return array(
			'discount_id'      => $id,
			'discount_name'    => 'Rule ' . $id,
			'original_price'   => 20.0,
			'discounted_price' => 15.0,
			'savings'          => $savings,
			'kind'             => $kind,
		);
	}

	private function set_cart( array $cart_contents ) {
		$cart                      = new \Fake_WC_Cart();
		$cart->cart_contents       = $cart_contents;
		$GLOBALS['__test_wc_cart'] = $cart;
	}

	// -----------------------------------------------------------------
	// cart_item_data_callback
	// -----------------------------------------------------------------

	public function test_price_kind_winner_surfaces_in_cart_item_data() {
		$integration = $this->make_integration();

		$data = $integration->cart_item_data_callback(
			array(
				'quantity'                   => 2,
				'storedash_discount_applied' => $this->applied( 'price' ),
			)
		);

		$this->assertSame( 7, $data['discount_rule_id'] );
		$this->assertSame( 'Rule 7', $data['discount_rule_name'] );
		$this->assertSame( 5.0, $data['savings'] );
		$this->assertSame(
			array(
				'original_price'   => 20.0,
				'discounted_price' => 15.0,
			),
			$data['price_discount']
		);
		$this->assertNull( $data['quantity_discount'] );
		$this->assertFalse( $data['is_bogo_free_item'] );
	}

	public function test_quantity_winner_with_both_keys_reports_once_via_quantity_branch() {
		$integration = $this->make_integration();
		$applied     = $this->applied( 'quantity', 9, 3.0 );
		$applied     = array_merge( $applied, array( 'tier' => array( 'min_quantity' => 3 ) ) );

		$data = $integration->cart_item_data_callback(
			array(
				'quantity'                     => 3,
				'storedash_discount_applied'   => $applied,
				'storedash_quantity_discount'  => $applied,
			)
		);

		// Quantity kind is owned by the quantity branch — the price branch
		// must skip it so nothing is reported twice.
		$this->assertNull( $data['price_discount'] );
		$this->assertNotNull( $data['quantity_discount'] );
		$this->assertSame( 9, $data['discount_rule_id'] );
		$this->assertSame( 3.0, $data['savings'] );
	}

	public function test_item_without_discount_returns_defaults() {
		$integration = $this->make_integration();

		$data = $integration->cart_item_data_callback( array( 'quantity' => 1 ) );

		$this->assertNull( $data['price_discount'] );
		$this->assertNull( $data['quantity_discount'] );
		$this->assertNull( $data['discount_rule_id'] );
		$this->assertSame( 0, $data['savings'] );
	}

	// -----------------------------------------------------------------
	// cart_data_callback (summary + total savings)
	// -----------------------------------------------------------------

	public function test_price_kind_winner_surfaces_in_cart_summary_and_savings() {
		$integration = $this->make_integration();
		$this->set_cart(
			array(
				'line_a' => array(
					'quantity'                   => 1,
					'storedash_discount_applied' => $this->applied( 'price', 7, 5.0 ),
				),
			)
		);

		$data = $integration->cart_data_callback();

		$this->assertTrue( $data['has_discounts'] );
		$this->assertSame( 5.0, $data['total_savings'] );
		$this->assertSame(
			array(
				array(
					'id'   => 7,
					'name' => 'Rule 7',
					'type' => 'price',
				),
			),
			$data['applied_discounts']
		);
	}

	public function test_quantity_winner_with_both_keys_does_not_double_count() {
		$integration = $this->make_integration();
		$applied     = $this->applied( 'quantity', 9, 3.0 );
		$this->set_cart(
			array(
				'line_a' => array(
					'quantity'                    => 3,
					'storedash_discount_applied'  => $applied,
					'storedash_quantity_discount' => $applied,
				),
			)
		);

		$data = $integration->cart_data_callback();

		// Savings counted once, summary listed once.
		$this->assertSame( 3.0, $data['total_savings'] );
		$this->assertCount( 1, $data['applied_discounts'] );
		$this->assertSame( 'quantity', $data['applied_discounts'][0]['type'] );
	}

	public function test_mixed_cart_sums_each_rule_once() {
		$integration = $this->make_integration();
		$qty_applied = $this->applied( 'quantity', 9, 3.0 );
		$this->set_cart(
			array(
				'line_price' => array(
					'quantity'                   => 1,
					'storedash_discount_applied' => $this->applied( 'price', 7, 5.0 ),
				),
				'line_qty'   => array(
					'quantity'                    => 3,
					'storedash_discount_applied'  => $qty_applied,
					'storedash_quantity_discount' => $qty_applied,
				),
			)
		);

		$data = $integration->cart_data_callback();

		$this->assertSame( 8.0, $data['total_savings'] );
		$this->assertCount( 2, $data['applied_discounts'] );
	}

	public function test_legacy_quantity_only_key_still_reported() {
		// Carts persisted before storedash_discount_applied existed carry only
		// the legacy mirror — the fallback path must keep reporting them.
		$integration = $this->make_integration();
		$this->set_cart(
			array(
				'line_a' => array(
					'quantity'                    => 3,
					'storedash_quantity_discount' => array(
						'discount_id'      => 4,
						'discount_name'    => 'Legacy tiers',
						'original_price'   => 10.0,
						'discounted_price' => 8.0,
						'savings'          => 6.0,
					),
				),
			)
		);

		$data = $integration->cart_data_callback();

		$this->assertTrue( $data['has_discounts'] );
		$this->assertSame( 6.0, $data['total_savings'] );
		$this->assertSame( 4, $data['applied_discounts'][0]['id'] );
	}

	public function test_bogo_free_item_savings_counted_alongside_price_rule() {
		$integration          = $this->make_integration();
		$free_product         = new WC_Product( 42 );
		$free_product->regular_price = '10';
		$free_product->price         = '0';
		$this->set_cart(
			array(
				'line_price' => array(
					'quantity'                   => 1,
					'storedash_discount_applied' => $this->applied( 'price', 7, 5.0 ),
				),
				'line_free'  => array(
					'quantity'               => 1,
					'storedash_is_free_item' => true,
					'data'                   => $free_product,
				),
			)
		);

		$data = $integration->cart_data_callback();

		$this->assertSame( 15.0, $data['total_savings'] );
	}

	public function test_no_cart_reports_no_discounts() {
		$integration               = $this->make_integration();
		$GLOBALS['__test_wc_cart'] = null;

		$data = $integration->cart_data_callback();

		$this->assertFalse( $data['has_discounts'] );
		$this->assertSame( 0, $data['total_savings'] );
		$this->assertSame( array(), $data['applied_discounts'] );
	}

	// -----------------------------------------------------------------
	// Quantity offers (product page + cart progress)
	// -----------------------------------------------------------------

	private function quantity_rule( array $overrides = array(), array $config = array() ) {
		return (object) array_merge(
			array(
				'id'                     => 3,
				'rule_type'              => 'quantity',
				'discount_type'          => 'percentage',
				'priority'               => 10,
				'disable_lower_priority' => '0',
				'apply_to_sale_price'    => '0',
				'rule_config'            => json_encode(
					array_merge(
						array(
							'count_mode'          => 'combined',
							'storefront_subtitle' => 'Öll fæðubótarefni telja saman',
							'storefront_note'     => '<b>Þú getur blandað saman</b>',
							'tiers'               => array(
								array( 'min_quantity' => 4, 'max_quantity' => null, 'discount' => 20 ),
								array( 'min_quantity' => 2, 'max_quantity' => 2, 'discount' => 10 ),
								array( 'min_quantity' => 3, 'max_quantity' => 3, 'discount' => 15, 'discount_type' => 'percentage' ),
							),
						),
						$config
					)
				),
			),
			$overrides
		);
	}

	private function with_fakes( $integration, array $rules, $applies = true, $combined_count = 0 ) {
		$db       = new class( $rules ) {
			public $rules;
			public function __construct( $rules ) {
				$this->rules = $rules;
			}
			public function get_active_discounts( $rule_type = null ) {
				return $this->rules;
			}
		};
		$matcher  = new class( $applies ) {
			public $applies;
			public function __construct( $applies ) {
				$this->applies = $applies;
			}
			public function discount_applies_to_product( $discount, $product ) {
				return is_callable( $this->applies ) ? ( $this->applies )( $discount ) : $this->applies;
			}
		};
		$priority = new class() {
			public function get_priority_ceiling( $product ) {
				return PHP_INT_MAX;
			}
			public function is_suppressed( $discount, $ceiling ) {
				return false;
			}
		};
		$resolver = new class( $combined_count ) {
			public $count;
			public function __construct( $count ) {
				$this->count = $count;
			}
			public function combined_quantity( $discount ) {
				return $this->count;
			}
		};

		$ref = new ReflectionClass( $integration );
		foreach ( array(
			'db_handler'        => $db,
			'matcher'           => $matcher,
			'priority_resolver' => $priority,
			'resolver'          => $resolver,
		) as $prop => $value ) {
			$ref->getProperty( $prop )->setValue( $integration, $value );
		}
		return $integration;
	}

	public function test_build_quantity_offer_sorts_tiers_and_strips_html_from_texts() {
		$offer = $this->make_integration()->build_quantity_offer( $this->quantity_rule() );

		$this->assertSame( 3, $offer['id'] );
		$this->assertSame( 'combined', $offer['count_mode'] );
		$this->assertSame( array( 2, 3, 4 ), array_column( $offer['tiers'], 'min_quantity' ) );
		$this->assertSame( 'percentage', $offer['tiers'][0]['discount_type'] ); // Inherited from the rule.
		$this->assertNull( $offer['tiers'][2]['max_quantity'] );
		$this->assertSame( 'Öll fæðubótarefni telja saman', $offer['subtitle'] );
		$this->assertSame( 'Þú getur blandað saman', $offer['note'] );
	}

	public function test_build_quantity_offer_defaults_to_per_product_and_rejects_empty_tiers() {
		$integration = $this->make_integration();

		$per_product = $integration->build_quantity_offer( $this->quantity_rule( array(), array( 'count_mode' => null ) ) );
		$this->assertSame( 'per_product', $per_product['count_mode'] );
		$this->assertNull( $integration->build_quantity_offer( $this->quantity_rule( array(), array( 'tiers' => array() ) ) ) );
		$this->assertNull( $integration->build_quantity_offer( $this->quantity_rule( array( 'rule_config' => '{' ) ) ) );
	}

	public function test_offer_progress_reports_current_and_next_tier() {
		$integration = $this->make_integration();
		$tiers       = $integration->build_quantity_offer( $this->quantity_rule() )['tiers'];

		$one = $integration->offer_progress( $tiers, 1 );
		$this->assertNull( $one['current_tier'] );
		$this->assertSame( 2, $one['next_tier']['min_quantity'] );

		$three = $integration->offer_progress( $tiers, 3 );
		$this->assertSame( 15.0, $three['current_tier']['discount'] );
		$this->assertSame( 4, $three['next_tier']['min_quantity'] );

		$nine = $integration->offer_progress( $tiers, 9 );
		$this->assertSame( 20.0, $nine['current_tier']['discount'] );
		$this->assertNull( $nine['next_tier'] );
	}

	public function test_product_gets_first_matching_quantity_offer() {
		$rules       = array(
			$this->quantity_rule( array( 'id' => 1 ) ),
			$this->quantity_rule( array( 'id' => 2 ) ),
		);
		$integration = $this->with_fakes(
			$this->make_integration(),
			$rules,
			function ( $discount ) {
				return 2 === (int) $discount->id;
			}
		);

		$this->assertSame( 2, $integration->get_quantity_offer( new WC_Product( 5 ) )['id'] );
	}

	public function test_product_outside_every_rule_gets_no_offer() {
		$integration = $this->with_fakes( $this->make_integration(), array( $this->quantity_rule() ), false );

		$this->assertNull( $integration->get_quantity_offer( new WC_Product( 5 ) ) );
	}

	public function test_cart_offers_list_combined_rules_with_qualifying_lines_only() {
		$this->set_cart( array() );
		$rules = array(
			$this->quantity_rule( array( 'id' => 1 ) ),
			$this->quantity_rule( array( 'id' => 2 ), array( 'count_mode' => 'per_product' ) ),
		);

		$offers = $this->with_fakes( $this->make_integration(), $rules, true, 3 )->get_cart_quantity_offers();
		$this->assertCount( 1, $offers );
		$this->assertSame( 1, $offers[0]['id'] );
		$this->assertSame( 3, $offers[0]['count'] );
		$this->assertSame( 15.0, $offers[0]['current_tier']['discount'] );
		$this->assertSame( 4, $offers[0]['next_tier']['min_quantity'] );

		$this->assertSame(
			array(),
			$this->with_fakes( $this->make_integration(), $rules, true, 0 )->get_cart_quantity_offers()
		);
	}

	public function test_cart_item_schema_declares_price_discount_field() {
		$integration = $this->make_integration();

		$schema = $integration->cart_item_schema_callback();

		$this->assertArrayHasKey( 'price_discount', $schema );
		$this->assertContains( 'object', (array) $schema['price_discount']['type'] );
	}
}
