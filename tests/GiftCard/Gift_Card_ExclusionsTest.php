<?php
/**
 * Gift card products are excluded from Storedash discounts, Rewards credit
 * (earn + spend) and WooCommerce coupons, and are forced virtual + tax-free.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Engine\Eligibility;
use StoreDash\Discounts\Engine\Discount_Matcher;
use StoreDash\GiftCard\Product\Gift_Card_Product;

require_once __DIR__ . '/gift-card-stubs.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Eligibility.php';

/**
 * Matcher without its DB handler.
 */
class Gift_Card_Test_Discount_Matcher extends Discount_Matcher {
	public function __construct() {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedFunction
}

/**
 * @covers \StoreDash\GiftCard\Product\Gift_Card_Product
 */
class Gift_Card_ExclusionsTest extends TestCase {

	const GIFT_PARENT = 9101;
	const PLAIN       = 9102;

	protected function setUp(): void {
		$GLOBALS['__test_post_meta'][ self::GIFT_PARENT ]['_storedash_gift_card'] = 'yes';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['__test_post_meta'][ self::GIFT_PARENT ] );
	}

	private function variation_of_gift_card(): WC_Product {
		$variation            = new WC_Product( 9103 );
		$variation->parent_id = self::GIFT_PARENT;
		return $variation;
	}

	public function test_flag_is_read_from_the_parent() {
		$this->assertTrue( Gift_Card_Product::is_gift_card( new WC_Product( self::GIFT_PARENT ) ) );
		$this->assertTrue( Gift_Card_Product::is_gift_card( $this->variation_of_gift_card() ) );
		$this->assertTrue( Gift_Card_Product::is_gift_card( self::GIFT_PARENT ) );
		$this->assertFalse( Gift_Card_Product::is_gift_card( new WC_Product( self::PLAIN ) ) );
		$this->assertFalse( Gift_Card_Product::is_gift_card( null ) );
	}

	public function test_storedash_discounts_skip_gift_cards() {
		$matcher  = new Gift_Card_Test_Discount_Matcher();
		$discount = (object) array(
			'rule_type'       => 'product',
			'target_ids'      => '',
			'exclude_ids'     => '',
			'disable_on_sale' => 0,
			'priority'        => 10,
		);
		$this->assertFalse( $matcher->discount_applies_to_product( $discount, $this->variation_of_gift_card() ) );
	}

	public function test_rewards_credit_treats_gift_cards_as_excluded() {
		$eligibility = new Eligibility();
		$this->assertTrue( $eligibility->product_excluded( $this->variation_of_gift_card(), array() ) );
		$this->assertFalse( $eligibility->product_excluded( new WC_Product( self::PLAIN ), array() ) );
	}

	public function test_coupons_and_runtime_enforcement() {
		$flag = new Gift_Card_Product();
		$gift = $this->variation_of_gift_card();

		$this->assertFalse( $flag->filter_coupon_valid_for_product( true, $gift ) );
		$this->assertTrue( $flag->filter_coupon_valid_for_product( true, new WC_Product( self::PLAIN ) ) );

		$items = $flag->filter_coupon_items(
			array(
				(object) array( 'product' => $gift ),
				(object) array( 'product' => new WC_Product( self::PLAIN ) ),
			)
		);
		$this->assertCount( 1, $items );

		$this->assertSame( 0, $flag->filter_coupon_discount( 500, 1000, array( 'data' => $gift ) ) );
		$this->assertSame( 500, $flag->filter_coupon_discount( 500, 1000, array( 'data' => new WC_Product( self::PLAIN ) ) ) );

		$this->assertTrue( $flag->filter_is_virtual( false, $gift ) );
		$this->assertSame( 'none', $flag->filter_tax_status( 'taxable', $gift ) );
		$this->assertSame( 'taxable', $flag->filter_tax_status( 'taxable', new WC_Product( self::PLAIN ) ) );
	}
}
