<?php
/**
 * Discount Matcher
 *
 * Efficiently matches products to discount rules with request-level caching.
 *
 * @package StoreDash\Discounts\Engine
 * @since   2.0.0
 */

namespace StoreDash\Discounts\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Discounts\Sync\Discount_DB_Handler;
use WC_Product;

/**
 * Handles product-to-discount matching with caching.
 *
 * @since 2.0.0
 */
class Discount_Matcher {

	use Trait_Sale_Check;

	/**
	 * DB handler instance.
	 *
	 * @var Discount_DB_Handler
	 */
	protected $db_handler;

	/**
	 * Cached active display discounts (loaded once per request).
	 *
	 * @var array|null
	 */
	protected $discounts = null;

	/**
	 * Product category cache (product_id => array of term_ids).
	 *
	 * @var array
	 */
	protected $category_cache = array();

	/**
	 * Product tag cache (product_id => array of term_ids).
	 *
	 * @var array
	 */
	protected $tag_cache = array();

	/**
	 * Product brand cache (product_id => array of term_ids).
	 *
	 * @var array
	 */
	protected $brand_cache = array();

	/**
	 * Descendant-expanded target term IDs ("{taxonomy}:{ids}" => array of term IDs).
	 *
	 * @var array
	 */
	protected $term_expansion_cache = array();

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		$this->db_handler = new Discount_DB_Handler();
	}

	/**
	 * Get all active display discounts (cached per request).
	 *
	 * Display discounts are: product, category, tag, brand, store_wide.
	 * These affect price display on product pages (not cart-only like BOGO/quantity).
	 *
	 * @since 2.0.0
	 *
	 * @return array Array of discount objects.
	 */
	public function get_active_display_discounts() {
		if ( $this->discounts !== null ) {
			return $this->discounts;
		}

		$this->discounts = $this->db_handler->get_active_display_discounts();

		// Defensive: never operate on a non-array (a DB failure upstream must not
		// fatal usort()/count() inside a price-display filter).
		if ( ! is_array( $this->discounts ) ) {
			$this->discounts = array();
		}

		// Sort by priority (lower number = higher priority)
		usort(
			$this->discounts,
			function ( $a, $b ) {
				return ( $a->priority ?? 10 ) - ( $b->priority ?? 10 );
			}
		);

		return $this->discounts;
	}

	/**
	 * Check if there are any active display discounts.
	 *
	 * Use this for early bailout before processing products.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if discounts exist.
	 */
	public function has_active_discounts() {
		return count( $this->get_active_display_discounts() ) > 0;
	}

	/**
	 * Check if a discount matches a product.
	 *
	 * @since 2.0.0
	 *
	 * @param object     $discount Discount object.
	 * @param WC_Product $product  Product object.
	 * @return bool True if matches.
	 */
	protected function discount_matches_product( $discount, $product ) {
		$product_id = $product->get_id();

		// For variations, also check the parent product ID
		$parent_id = $product->get_parent_id();

		// Parse target IDs
		$target_ids = $this->parse_ids( $discount->target_ids );

		// Check exclusions first (products and taxonomy terms)
		if ( $this->product_excluded( $discount, $product, $product_id, $parent_id ) ) {
			return false;
		}

		// Check disable_on_sale option
		if ( $discount->disable_on_sale && $this->is_product_on_merchant_sale( $product ) ) {
			return false;
		}

		// NOTE: cart conditions (min/max cart total & quantity) are deliberately
		// NOT checked here. This is a structural product-match test; conditions
		// are context-dependent and owned by Discount_Resolver, which excludes
		// conditioned rules from catalog display and enforces them against the
		// real cart contents in cart context. (WC also resets totals before
		// woocommerce_before_calculate_totals, so reading cart totals here would
		// always see 0 inside the cart hook.)

		// Check by rule type
		switch ( $discount->rule_type ) {
			case 'store_wide':
				return true; // Applies to all (exclusions already checked)

			case 'product':
				return in_array( $product_id, $target_ids, true ) ||
						( $parent_id && in_array( $parent_id, $target_ids, true ) );

			case 'bogo':
			case 'quantity':
				// Cart rule types scope by product target_ids; an empty list
				// means store-wide, mirroring product_matches_discount() in the
				// cart engines. This case is only reached via the shared
				// applies-to-product test (the display path never loads these),
				// so it does not change display pricing.
				if ( empty( $target_ids ) ) {
					return true;
				}
				return in_array( $product_id, $target_ids, true ) ||
						( $parent_id && in_array( $parent_id, $target_ids, true ) );

			case 'category':
				return $this->product_in_terms( $product, $target_ids, 'product_cat' );

			case 'tag':
				return $this->product_in_terms( $product, $target_ids, 'product_tag' );

			case 'brand':
				return $this->product_in_terms( $product, $target_ids, 'product_brand' );

			default:
				return false;
		}
	}

	/**
	 * Public applicability test used by the shared priority resolver.
	 *
	 * Returns whether a discount of ANY rule type (display or cart) applies to
	 * a product — i.e. it passes exclusions, disable_on_sale, and the rule-type
	 * target/taxonomy match. Cart conditions are enforced separately by
	 * Discount_Resolver. Used to decide whether a disable_lower_priority
	 * discount imposes a ceiling on this product.
	 *
	 * @since 3.1.0
	 *
	 * @param object     $discount Discount object.
	 * @param WC_Product $product  Product object.
	 * @return bool True if the discount applies to the product.
	 */
	public function discount_applies_to_product( $discount, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		return $this->discount_matches_product( $discount, $product );
	}

	/**
	 * Whether a discount's exclusions rule the product out.
	 *
	 * Product-ID exclusions check both the product and (for variations) its
	 * parent. Taxonomy exclusions reuse product_in_terms(), so excluding a
	 * parent category also excludes its descendants — mirroring how targeting
	 * a parent category targets them. Columns may be absent on rows synced
	 * before the taxonomy-exclusion upgrade; treat missing as empty.
	 *
	 * @since 3.3.0
	 *
	 * @param object     $discount   Discount object.
	 * @param WC_Product $product    Product object.
	 * @param int        $product_id Product ID.
	 * @param int        $parent_id  Parent product ID (0 when not a variation).
	 * @return bool True when the product is excluded from this discount.
	 */
	protected function product_excluded( $discount, $product, $product_id, $parent_id ) {
		$exclude_ids = $this->parse_ids( $discount->exclude_ids ?? '' );

		if ( in_array( $product_id, $exclude_ids, true ) ) {
			return true;
		}
		if ( $parent_id && in_array( $parent_id, $exclude_ids, true ) ) {
			return true;
		}

		$taxonomy_exclusions = array(
			'product_cat'   => $discount->exclude_category_ids ?? '',
			'product_tag'   => $discount->exclude_tag_ids ?? '',
			'product_brand' => $discount->exclude_brand_ids ?? '',
		);

		foreach ( $taxonomy_exclusions as $taxonomy => $ids_string ) {
			$term_ids = $this->parse_ids( $ids_string );
			if ( ! empty( $term_ids ) && $this->product_in_terms( $product, $term_ids, $taxonomy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if product belongs to any of the given taxonomy terms.
	 *
	 * Targeting a term also targets its descendants, matching what the merchant
	 * sees: a WooCommerce category archive lists products from child categories,
	 * so a "Nuddvörur" discount that skipped a product filed only under its child
	 * "Nuddboltar" read as the discount randomly missing items on its own page.
	 *
	 * @since 2.0.0
	 *
	 * @param WC_Product $product  Product object.
	 * @param array      $term_ids Term IDs to check.
	 * @param string     $taxonomy Taxonomy name.
	 * @return bool True if product is in any of the terms.
	 */
	protected function product_in_terms( $product, $term_ids, $taxonomy ) {
		if ( empty( $term_ids ) ) {
			return false;
		}

		$term_ids = $this->expand_term_ids( $term_ids, $taxonomy );

		$product_id = $product->get_id();
		$parent_id  = $product->get_parent_id();

		// Get product terms (with caching)
		$product_terms = $this->get_product_terms( $product_id, $taxonomy );

		// For variations, also check parent terms
		if ( $parent_id ) {
			$parent_terms  = $this->get_product_terms( $parent_id, $taxonomy );
			$product_terms = array_unique( array_merge( $product_terms, $parent_terms ) );
		}

		return ! empty( array_intersect( $term_ids, $product_terms ) );
	}

	/**
	 * Expand a set of target term IDs to include their descendants.
	 *
	 * Flat taxonomies (product_tag, and product_brand on stores that register it
	 * flat) are returned untouched. Hierarchical ones resolve through
	 * get_term_children(), which reads the cached `{$taxonomy}_children` option
	 * rather than querying per call.
	 *
	 * @since 1.9.1
	 *
	 * @param array  $term_ids Target term IDs.
	 * @param string $taxonomy Taxonomy name.
	 * @return array Target term IDs plus every descendant.
	 */
	protected function expand_term_ids( $term_ids, $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) || ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return $term_ids;
		}

		$cache_key = $taxonomy . ':' . implode( ',', $term_ids );
		if ( isset( $this->term_expansion_cache[ $cache_key ] ) ) {
			return $this->term_expansion_cache[ $cache_key ];
		}

		$expanded = $term_ids;
		foreach ( $term_ids as $term_id ) {
			$children = get_term_children( $term_id, $taxonomy );
			if ( is_array( $children ) && ! empty( $children ) ) {
				$expanded = array_merge( $expanded, array_map( 'intval', $children ) );
			}
		}

		$expanded = array_values( array_unique( $expanded ) );

		$this->term_expansion_cache[ $cache_key ] = $expanded;
		return $expanded;
	}

	/**
	 * Get product taxonomy terms with caching.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy name.
	 * @return array Array of term IDs.
	 */
	protected function get_product_terms( $product_id, $taxonomy ) {
		// Select appropriate cache
		switch ( $taxonomy ) {
			case 'product_cat':
				$cache = &$this->category_cache;
				break;
			case 'product_tag':
				$cache = &$this->tag_cache;
				break;
			case 'product_brand':
				$cache = &$this->brand_cache;
				break;
			default:
				$cache = array();
		}

		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}

		// Use the WooCommerce helper: it resolves term IDs via get_the_terms()
		// (served from the object-term cache WP_Query already primed), avoiding an
		// uncached DB query per product per taxonomy on catalog pages. It returns
		// an empty array on failure and never a WP_Error.
		$terms = wc_get_product_term_ids( $product_id, $taxonomy );

		$cache[ $product_id ] = $terms;
		return $terms;
	}

	/**
	 * Parse comma-separated IDs string to array of integers.
	 *
	 * @since 2.0.0
	 *
	 * @param string $ids_string Comma-separated IDs.
	 * @return array Array of integers.
	 */
	protected function parse_ids( $ids_string ) {
		if ( empty( $ids_string ) ) {
			return array();
		}

		return array_map( 'intval', array_filter( explode( ',', $ids_string ) ) );
	}

	/**
	 * Clear all caches.
	 *
	 * Call this when discounts are updated.
	 *
	 * @since 2.0.0
	 */
	public function clear_cache() {
		$this->discounts            = null;
		$this->category_cache       = array();
		$this->tag_cache            = array();
		$this->brand_cache          = array();
		$this->term_expansion_cache = array();
	}
}
