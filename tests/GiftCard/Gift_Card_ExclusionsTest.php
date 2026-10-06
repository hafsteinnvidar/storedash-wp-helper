<?php
/**
 * Gift card products: excluded from Storedash automatic discounts and from
 * Rewards credit EARN; WooCommerce coupons and Rewards credit SPEND are allowed;
 * always virtual + tax-free.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Credit\Engine\Eligibility;
use StoreDash\Discounts\Engine\Discount_Matcher;
use StoreDash\GiftCard\Checkout\Gift_Card_Only_Checkout;
use StoreDash\GiftCard\Product\Gift_Card_Product;

require_once __DIR__ . '/gift-card-stubs.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Eligibility.php';
require_once __DIR__ . '/../../inc/GiftCard/Checkout/Gift_Card_Only_Checkout.php';

/**
 * Product double with the save-time setters/getters.
 */
class Gift_Card_Saving_Product extends WC_Product {
	public $meta        = array();
	public $virtual     = false;
	public $tax_status  = 'taxable';

	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		return $this->meta[ $key ] ?? '';
	}
	public function get_virtual( $context = 'view' ) {
		return $this->virtual;
	}
	public function set_virtual( $virtual ) {
		$this->virtual = (bool) $virtual;
	}
	public function get_tax_status( $context = 'view' ) {
		return $this->tax_status;
	}
	public function set_tax_status( $status ) {
		$this->tax_status = $status;
	}
}

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

	public function test_rewards_credit_earn_skips_gift_cards_but_spend_allows_them() {
		$eligibility = new Eligibility();
		$this->assertTrue( $eligibility->excluded_from_earn( $this->variation_of_gift_card(), array() ) );
		$this->assertFalse( $eligibility->product_excluded( $this->variation_of_gift_card(), array() ), 'credit may pay for a gift card' );
		$this->assertFalse( $eligibility->excluded_from_earn( new WC_Product( self::PLAIN ), array() ) );
	}

	public function test_coupons_allowed_and_runtime_enforcement() {
		$flag = new Gift_Card_Product();
		$gift = $this->variation_of_gift_card();

		$this->assertFalse( method_exists( $flag, 'filter_coupon_valid_for_product' ), 'coupons are allowed on gift cards' );

		$this->assertTrue( $flag->filter_is_virtual( false, $gift ) );
		$this->assertSame( 'none', $flag->filter_tax_status( 'taxable', $gift ) );
		$this->assertSame( 'taxable', $flag->filter_tax_status( 'taxable', new WC_Product( self::PLAIN ) ) );
	}

	public function test_gift_card_only_cart_detection_and_locale_relaxing() {
		$gift  = array( 'data' => $this->variation_of_gift_card() );
		$plain = array( 'data' => new WC_Product( self::PLAIN ) );
		$this->assertTrue( Gift_Card_Only_Checkout::is_gift_card_only( array( $gift, $gift ) ) );
		$this->assertFalse( Gift_Card_Only_Checkout::is_gift_card_only( array( $gift, $plain ) ) );
		$this->assertFalse( Gift_Card_Only_Checkout::is_gift_card_only( array() ) );

		$entry = Gift_Card_Only_Checkout::relax_entry(
			array(
				'first_name' => array( 'required' => true ),
				'postcode'   => array( 'required' => true, 'label' => 'Postcode' ),
				'country'    => array( 'required' => true ),
			)
		);
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode' ) as $field ) {
			$this->assertFalse( $entry[ $field ]['required'], $field );
			$this->assertTrue( $entry[ $field ]['hidden'], $field );
		}
		$this->assertSame( 'Postcode', $entry['postcode']['label'] );
		$this->assertTrue( $entry['first_name']['required'], 'name stays required' );
		$this->assertTrue( $entry['country']['required'], 'country stays required' );
	}

	public function test_any_save_forces_virtual_and_tax_none() {
		$flag = new Gift_Card_Product();

		// Parent created via WC REST with the flag in meta_data, not yet saved (id 0).
		$parent                                     = new Gift_Card_Saving_Product( 0 );
		$parent->meta['_storedash_gift_card']       = 'yes';
		$flag->enforce_on_save( $parent );
		$this->assertTrue( $parent->virtual );
		$this->assertSame( 'none', $parent->tax_status );

		// Variation of a flagged parent: virtual and stored untaxed too.
		$variation            = new Gift_Card_Saving_Product( 9104 );
		$variation->parent_id = self::GIFT_PARENT;
		$flag->enforce_on_save( $variation );
		$this->assertTrue( $variation->virtual );
		$this->assertSame( 'none', $variation->tax_status );

		// Plain product untouched.
		$plain = new Gift_Card_Saving_Product( self::PLAIN );
		$flag->enforce_on_save( $plain );
		$this->assertFalse( $plain->virtual );
		$this->assertSame( 'taxable', $plain->tax_status );
	}
}
