<?php
/**
 * Discount_Matcher tests.
 *
 * Focused on the structural product-match test, in particular taxonomy
 * targeting: a category discount must reach products filed only under a
 * descendant category, because that is what the merchant sees on the category
 * archive the discount is named after.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Discounts\Engine\Discount_Matcher;

/**
 * Matcher with no DB handler — the structural match never touches it, and
 * instantiating the real one would need $wpdb.
 */
class Test_Discount_Matcher extends Discount_Matcher {

	public function __construct() {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedFunction
}

/**
 * @covers \StoreDash\Discounts\Engine\Discount_Matcher
 */
class Discount_MatcherTest extends TestCase {

	/** @var Test_Discount_Matcher */
	protected $matcher;

	// Category tree mirroring the Hreysti store that surfaced the bug:
	// Nuddvörur (223) → Nuddboltar (255), Nuddbyssur (547), Nuddrúllur (224).
	const CAT_PARENT = 223;
	const CAT_CHILD  = 255;
	const CAT_OTHER  = 999;

	protected function setUp(): void {
		parent::setUp();

		$this->matcher = new Test_Discount_Matcher();

		$GLOBALS['__test_product_terms']          = array();
		$GLOBALS['__test_term_children']          = array(
			'product_cat' => array(
				self::CAT_PARENT => array( self::CAT_CHILD, 547, 224 ),
			),
		);
		$GLOBALS['__test_hierarchical_taxonomies'] = array( 'product_cat' );
	}

	/**
	 * Build a discount row double.
	 *
	 * @param array $overrides Column overrides.
	 * @return object
	 */
	protected function discount( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'rule_type'       => 'category',
				'target_ids'      => (string) self::CAT_PARENT,
				'exclude_ids'     => '',
				'disable_on_sale' => 0,
				'priority'        => 10,
			),
			$overrides
		);
	}

	/**
	 * Build a product double assigned to the given categories.
	 *
	 * @param int   $id         Product ID.
	 * @param array $categories Term IDs.
	 * @return WC_Product
	 */
	protected function product( $id, array $categories ) {
		$GLOBALS['__test_product_terms'][ $id ]['product_cat'] = $categories;
		return new WC_Product( $id );
	}

	public function test_matches_product_assigned_to_the_targeted_category() {
		$product = $this->product( 1, array( self::CAT_PARENT ) );

		$this->assertTrue(
			$this->matcher->discount_applies_to_product( $this->discount(), $product )
		);
	}

	/**
	 * The regression: "The Toe Spacer Eight Ball Nuddbolti" sat in Nuddboltar
	 * only, so a Nuddvörur discount skipped it while discounting every sibling
	 * that happened to also carry the parent term.
	 */
	public function test_matches_product_assigned_only_to_a_descendant_category() {
		$product = $this->product( 2, array( self::CAT_CHILD ) );

		$this->assertTrue(
			$this->matcher->discount_applies_to_product( $this->discount(), $product )
		);
	}

	public function test_does_not_match_product_outside_the_targeted_tree() {
		$product = $this->product( 3, array( self::CAT_OTHER ) );

		$this->assertFalse(
			$this->matcher->discount_applies_to_product( $this->discount(), $product )
		);
	}

	/**
	 * Expansion widens the target set, never the exclusion escape hatch: a
	 * product excluded by ID stays excluded even when its category qualifies.
	 */
	public function test_exclusion_still_wins_over_a_descendant_match() {
		$product = $this->product( 4, array( self::CAT_CHILD ) );

		$this->assertFalse(
			$this->matcher->discount_applies_to_product(
				$this->discount( array( 'exclude_ids' => '4' ) ),
				$product
			)
		);
	}

	/**
	 * Variations carry no terms of their own; the parent product's categories
	 * decide, including via descendants.
	 */
	public function test_variation_matches_through_its_parents_descendant_category() {
		$this->product( 5, array( self::CAT_CHILD ) );

		$variation            = new WC_Product( 6 );
		$variation->parent_id = 5;

		$this->assertTrue(
			$this->matcher->discount_applies_to_product( $this->discount(), $variation )
		);
	}

	/**
	 * Flat taxonomies have no descendants to expand; targeting must stay exact.
	 */
	public function test_flat_taxonomy_targeting_is_unchanged() {
		$GLOBALS['__test_product_terms'][7]['product_tag'] = array( 40 );
		$product = new WC_Product( 7 );

		$this->assertTrue(
			$this->matcher->discount_applies_to_product(
				$this->discount(
					array(
						'rule_type'  => 'tag',
						'target_ids' => '40',
					)
				),
				$product
			)
		);

		$this->assertFalse(
			$this->matcher->discount_applies_to_product(
				$this->discount(
					array(
						'rule_type'  => 'tag',
						'target_ids' => '41',
					)
				),
				$product
			)
		);
	}

	/**
	 * Taxonomy exclusions: a store-wide discount must skip products in an
	 * excluded category, including products filed only under a descendant of
	 * the excluded term (exclusion expands like targeting does).
	 */
	public function test_store_wide_discount_skips_excluded_category() {
		$product = $this->product( 10, array( self::CAT_PARENT ) );

		$this->assertFalse(
			$this->matcher->discount_applies_to_product(
				$this->discount(
					array(
						'rule_type'            => 'store_wide',
						'target_ids'           => '',
						'exclude_category_ids' => (string) self::CAT_PARENT,
					)
				),
				$product
			)
		);
	}

	public function test_store_wide_discount_skips_descendant_of_excluded_category() {
		$product = $this->product( 11, array( self::CAT_CHILD ) );

		$this->assertFalse(
			$this->matcher->discount_applies_to_product(
				$this->discount(
					array(
						'rule_type'            => 'store_wide',
						'target_ids'           => '',
						'exclude_category_ids' => (string) self::CAT_PARENT,
					)
				),
				$product
			)
		);
	}

	public function test_store_wide_discount_applies_outside_excluded_category() {
		$product = $this->product( 12, array( self::CAT_OTHER ) );

		$this->assertTrue(
			$this->matcher->discount_applies_to_product(
				$this->discount(
					array(
						'rule_type'            => 'store_wide',
						'target_ids'           => '',
						'exclude_category_ids' => (string) self::CAT_PARENT,
					)
				),
				$product
			)
		);
	}

	public function test_excluded_tag_rules_product_out_of_category_discount() {
		$product = $this->product( 13, array( self::CAT_PARENT ) );
		$GLOBALS['__test_product_terms'][13]['product_tag'] = array( 77 );

		$this->assertFalse(
			$this->matcher->discount_applies_to_product(
				$this->discount( array( 'exclude_tag_ids' => '77' ) ),
				$product
			)
		);
	}

	public function test_excluded_brand_rules_product_out_of_store_wide_discount() {
		$product = $this->product( 14, array( self::CAT_OTHER ) );
		$GLOBALS['__test_product_terms'][14]['product_brand'] = array( 88 );

		$this->assertFalse(
			$this->matcher->discount_applies_to_product(
				$this->discount(
					array(
						'rule_type'         => 'store_wide',
						'target_ids'        => '',
						'exclude_brand_ids' => '88',
					)
				),
				$product
			)
		);
	}

	/**
	 * Variations inherit the parent product's terms on the exclusion path too.
	 */
	public function test_variation_excluded_through_parents_category() {
		$this->product( 15, array( self::CAT_CHILD ) );

		$variation            = new WC_Product( 16 );
		$variation->parent_id = 15;

		$this->assertFalse(
			$this->matcher->discount_applies_to_product(
				$this->discount(
					array(
						'rule_type'            => 'store_wide',
						'target_ids'           => '',
						'exclude_category_ids' => (string) self::CAT_PARENT,
					)
				),
				$variation
			)
		);
	}

	/**
	 * Rows synced before the 1.5.0 upgrade have no taxonomy-exclusion columns
	 * at all; the matcher must treat the missing properties as empty.
	 */
	public function test_rows_without_taxonomy_exclusion_columns_still_match() {
		$product = $this->product( 17, array( self::CAT_PARENT ) );

		$legacy = (object) array(
			'rule_type'       => 'store_wide',
			'target_ids'      => '',
			'exclude_ids'     => '',
			'disable_on_sale' => 0,
			'priority'        => 10,
		);

		$this->assertTrue(
			$this->matcher->discount_applies_to_product( $legacy, $product )
		);
	}

	/**
	 * disable_on_sale is evaluated before the taxonomy test, so a descendant
	 * match must not resurrect a product the merchant already put on sale.
	 */
	public function test_disable_on_sale_still_skips_a_descendant_match() {
		$product             = $this->product( 8, array( self::CAT_CHILD ) );
		$product->sale_price = '1000';

		$this->assertFalse(
			$this->matcher->discount_applies_to_product(
				$this->discount( array( 'disable_on_sale' => 1 ) ),
				$product
			)
		);
	}
}
