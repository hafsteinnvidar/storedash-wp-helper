<?php
/**
 * Unit tests for Quantity_Discount_Rule PDP tier-table gating.
 *
 * Locks CI-3 (helper audit 2026-07-31): the tier table must honor
 * `disable_on_sale` the same way the cart engine (Discount_Matcher) does,
 * so the PDP never advertises tiers the cart will refuse to apply.
 *
 * Tests target discount_display_eligible() directly — the full
 * display_tier_pricing_table() bails under REST_REQUEST, which another suite
 * defines process-wide.
 *
 * @package StoreDash\Tests\Discounts
 */

namespace StoreDash\Tests\Discounts;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StoreDash\Discounts\Engine\Discount_Matcher;
use StoreDash\Discounts\Engine\Quantity_Discount_Rule;
use WC_Product;

require_once __DIR__ . '/../../inc/Discounts/Engine/Discount_Matcher.php';
require_once __DIR__ . '/../../inc/Discounts/Engine/Quantity_Discount_Rule.php';

/**
 * Matcher with no DB handler — the structural match never touches it, and
 * instantiating the real one would need $wpdb.
 */
class Quantity_Test_Matcher extends Discount_Matcher {

	public function __construct() {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedFunction
}

/**
 * @covers \StoreDash\Discounts\Engine\Quantity_Discount_Rule
 */
class Quantity_Discount_RuleTest extends TestCase {

	/**
	 * Invoke the protected display-eligibility gate.
	 *
	 * The rule delegates to the shared Discount_Matcher; inject a DB-less one
	 * via reflection since the real constructor needs $wpdb.
	 *
	 * @return bool
	 */
	private function eligible( WC_Product $product, $discount ) {
		$reflection = new ReflectionClass( Quantity_Discount_Rule::class );
		$rule       = $reflection->newInstanceWithoutConstructor();

		$matcher_prop = $reflection->getProperty( 'matcher' );
		if ( PHP_VERSION_ID < 80100 ) {
			$matcher_prop->setAccessible( true );
		}
		$matcher_prop->setValue( $rule, new Quantity_Test_Matcher() );

		$method = $reflection->getMethod( 'discount_display_eligible' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( $rule, $product, $discount );
	}

	/**
	 * Quantity discount row shaped like wp_storedash_discounts.
	 *
	 * @return object
	 */
	private function discount( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'id'              => 1,
				'rule_type'       => 'quantity',
				'target_ids'      => '',
				'exclude_ids'     => '',
				'disable_on_sale' => 0,
				'rule_config'     => '{"tiers":[]}',
				'discount_type'   => 'percentage',
			),
			$overrides
		);
	}

	private function product( $sale_price = '' ) {
		$product                = new WC_Product( 10 );
		$product->regular_price = '20';
		$product->sale_price    = $sale_price;
		$product->price         = '' !== $sale_price ? $sale_price : '20';
		return $product;
	}

	public function test_non_sale_product_eligible_even_with_disable_on_sale() {
		$this->assertTrue(
			$this->eligible( $this->product(), $this->discount( array( 'disable_on_sale' => 1 ) ) )
		);
	}

	public function test_sale_product_ineligible_when_disable_on_sale_set() {
		// Mirrors Discount_Matcher: a merchant-set sale price disqualifies the
		// product, so the PDP must not advertise the tiers.
		$this->assertFalse(
			$this->eligible( $this->product( '15' ), $this->discount( array( 'disable_on_sale' => 1 ) ) )
		);
	}

	public function test_sale_product_still_eligible_when_flag_unset() {
		$this->assertTrue(
			$this->eligible( $this->product( '15' ), $this->discount( array( 'disable_on_sale' => 0 ) ) )
		);
	}

	public function test_excluded_product_ineligible_regardless_of_sale_state() {
		$this->assertFalse(
			$this->eligible(
				$this->product(),
				$this->discount(
					array(
						'exclude_ids'     => '10',
						'disable_on_sale' => 1,
					)
				)
			)
		);
	}

	public function test_targeted_discount_only_matches_target_products() {
		$this->assertFalse(
			$this->eligible( $this->product(), $this->discount( array( 'target_ids' => '999' ) ) )
		);
		$this->assertTrue(
			$this->eligible( $this->product(), $this->discount( array( 'target_ids' => '10,999' ) ) )
		);
	}

	public function test_empty_targets_match_store_wide() {
		$this->assertTrue(
			$this->eligible( $this->product(), $this->discount() )
		);
	}

	/**
	 * The tier table now honours taxonomy exclusions through the shared
	 * matcher — the old local matcher silently ignored them.
	 */
	public function test_product_in_excluded_category_ineligible() {
		$GLOBALS['__test_product_terms'][10]['product_cat'] = array( 300 );

		$this->assertFalse(
			$this->eligible(
				$this->product(),
				$this->discount( array( 'exclude_category_ids' => '300' ) )
			)
		);

		unset( $GLOBALS['__test_product_terms'][10] );
	}
}
