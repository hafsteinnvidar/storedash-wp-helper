<?php
/**
 * Product eligibility for rewards credit.
 *
 * Mirrors Discount_Matcher::product_excluded(): product-id exclusions check the
 * product and (for variations) its parent; taxonomy exclusions include
 * descendants of the excluded term. Sale detection is the merchant-set sale
 * price only (Storedash dynamic discounts set `price`, never `sale_price`).
 *
 * @package StoreDash\Credit\Engine
 * @since   1.17.0
 */

namespace StoreDash\Credit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exclusion + sale checks with per-request caching.
 *
 * @since 1.17.0
 */
class Eligibility {

	/**
	 * Term cache: taxonomy => product_id => term ids.
	 *
	 * @var array
	 */
	protected $term_cache = array();

	/**
	 * Descendant-expanded term ids: "{taxonomy}:{ids}" => ids.
	 *
	 * @var array
	 */
	protected $expansion_cache = array();

	/**
	 * Whether a product is excluded by the settings' exclusion lists.
	 *
	 * @param \WC_Product $product  Product (or variation).
	 * @param array       $settings Normalized settings.
	 * @return bool
	 */
	public function product_excluded( $product, array $settings ): bool {
		if ( ! $product || ! is_object( $product ) ) {
			return true;
		}

		$product_id = (int) $product->get_id();
		$parent_id  = (int) $product->get_parent_id();

		$exclude_ids = isset( $settings['exclude_product_ids'] ) ? array_map( 'intval', (array) $settings['exclude_product_ids'] ) : array();
		if ( in_array( $product_id, $exclude_ids, true ) || ( $parent_id && in_array( $parent_id, $exclude_ids, true ) ) ) {
			return true;
		}

		$taxonomies = array(
			'product_cat'   => $settings['exclude_category_ids'] ?? array(),
			'product_tag'   => $settings['exclude_tag_ids'] ?? array(),
			'product_brand' => $settings['exclude_brand_ids'] ?? array(),
		);

		foreach ( $taxonomies as $taxonomy => $ids ) {
			$ids = array_map( 'intval', (array) $ids );
			if ( ! empty( $ids ) && $this->product_in_terms( $product, $ids, $taxonomy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the product carries a merchant-set sale price.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function is_on_sale( $product ): bool {
		if ( ! $product || ! is_object( $product ) ) {
			return false;
		}
		$sale = $product->get_sale_price();
		return ! ( '' === $sale || null === $sale || (float) $sale <= 0 );
	}

	/**
	 * Whether the product (or its parent) is in any of the terms (or descendants).
	 *
	 * @param \WC_Product $product  Product.
	 * @param int[]       $term_ids Term ids.
	 * @param string      $taxonomy Taxonomy.
	 * @return bool
	 */
	public function product_in_terms( $product, array $term_ids, string $taxonomy ): bool {
		if ( empty( $term_ids ) ) {
			return false;
		}

		$term_ids = $this->expand_term_ids( $term_ids, $taxonomy );
		$terms    = $this->get_product_terms( (int) $product->get_id(), $taxonomy );

		$parent_id = (int) $product->get_parent_id();
		if ( $parent_id ) {
			$terms = array_unique( array_merge( $terms, $this->get_product_terms( $parent_id, $taxonomy ) ) );
		}

		return ! empty( array_intersect( $term_ids, $terms ) );
	}

	/**
	 * Term ids of a product in a taxonomy (cached).
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @return int[]
	 */
	public function get_product_terms( int $product_id, string $taxonomy ): array {
		if ( isset( $this->term_cache[ $taxonomy ][ $product_id ] ) ) {
			return $this->term_cache[ $taxonomy ][ $product_id ];
		}
		$terms = function_exists( 'wc_get_product_term_ids' ) ? wc_get_product_term_ids( $product_id, $taxonomy ) : array();
		$terms = is_array( $terms ) ? array_map( 'intval', $terms ) : array();

		$this->term_cache[ $taxonomy ][ $product_id ] = $terms;
		return $terms;
	}

	/**
	 * Expand term ids with their descendants (hierarchical taxonomies only).
	 *
	 * @param int[]  $term_ids Term ids.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	protected function expand_term_ids( array $term_ids, string $taxonomy ): array {
		if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $taxonomy ) || ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return $term_ids;
		}

		$cache_key = $taxonomy . ':' . implode( ',', $term_ids );
		if ( isset( $this->expansion_cache[ $cache_key ] ) ) {
			return $this->expansion_cache[ $cache_key ];
		}

		$expanded = $term_ids;
		foreach ( $term_ids as $term_id ) {
			$children = get_term_children( $term_id, $taxonomy );
			if ( is_array( $children ) && ! empty( $children ) ) {
				$expanded = array_merge( $expanded, array_map( 'intval', $children ) );
			}
		}

		$expanded                            = array_values( array_unique( $expanded ) );
		$this->expansion_cache[ $cache_key ] = $expanded;
		return $expanded;
	}
}
