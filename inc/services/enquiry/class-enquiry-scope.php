<?php
/**
 * Enquiry Scope Resolver
 *
 * Decides whether the enquiry form should render for a given product.
 *
 * The waitlist had a free eligibility gate — "is this product out of stock?" —
 * that both defined the feature and limited it to a small slice of traffic.
 * Enquiry has no such gate: left alone it renders on every product page. This
 * class is the merchant's control over that, scoping by category, tag and
 * brand.
 *
 * ## Why this costs nothing
 *
 * On a single product page WordPress has already loaded the product's term
 * relationships before any template hook fires. `WP_Query` defaults
 * `update_post_term_cache` to true (`class-wp-query.php`), which runs
 * `update_object_term_cache()` for EVERY taxonomy registered to the `product`
 * post type — `product_cat`, `product_tag`, `product_brand` and all `pa_*`
 * attributes — priming them in one query as part of the main query.
 * `has_term()` → `is_object_in_term()` → `get_object_term_cache()` is then a
 * pure cache hit. Scoping therefore adds ZERO database queries to a product
 * page render.
 *
 * The one thing that would break that property is calling something that is
 * not cache-backed, so keep it that way: no `get_terms()`, no meta queries, no
 * `wc_get_product()` calls in this path.
 *
 * ## Term IDs are WooCommerce term IDs
 *
 * The dashboard picker reads categories/tags/brands out of Supabase, where the
 * primary key is an internal surrogate and the WooCommerce term ID lives in
 * `external_id`. Only the latter means anything here. Sending internal IDs
 * produces a scope that silently matches nothing — the same class of bug as
 * the ClickHouse internal-vs-external category ID trap.
 *
 * @package StoreDash\Services\Enquiry
 * @since   1.9.0
 */

namespace StoreDash\Services\Enquiry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enquiry Scope Class
 */
class Enquiry_Scope {

	/**
	 * Memoized eligibility per product ID for this request.
	 *
	 * @var array<int,bool>
	 */
	private static $cache = array();

	/**
	 * Memoized taxonomy => term ID list, expanded to include descendants.
	 *
	 * @var array<string,int[]>|null
	 */
	private static $terms = null;

	/**
	 * Whether the enquiry form is eligible to render for a product.
	 *
	 * @param \WC_Product|null $product Product being viewed.
	 * @return bool
	 */
	public static function is_eligible( $product ): bool {
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		// Variations inherit their parent's terms; terms are never assigned to
		// a variation post itself.
		$product_id = $product->is_type( 'variation' )
			? $product->get_parent_id()
			: $product->get_id();

		if ( $product_id <= 0 ) {
			return false;
		}

		if ( isset( self::$cache[ $product_id ] ) ) {
			return self::$cache[ $product_id ];
		}

		$config = Enquiry_Config::get();
		$scope  = is_array( $config['scope'] ?? null ) ? $config['scope'] : array();
		$mode   = (string) ( $scope['mode'] ?? Enquiry_Config::SCOPE_ALL );

		if ( Enquiry_Config::SCOPE_ALL === $mode ) {
			self::$cache[ $product_id ] = true;

			return true;
		}

		$terms = self::terms_by_taxonomy();

		// A scope that names no terms is meaningless in either direction.
		// "Only these" with an empty list would hide the form everywhere, and
		// "all except" with an empty list excludes nothing — treat both as
		// "show everywhere" rather than letting a half-finished save take the
		// form off the whole storefront.
		if ( empty( $terms ) ) {
			self::$cache[ $product_id ] = true;

			return true;
		}

		$matches = self::matches_any( $product_id, $terms );

		$eligible = Enquiry_Config::SCOPE_ONLY === $mode ? $matches : ! $matches;

		self::$cache[ $product_id ] = $eligible;

		return $eligible;
	}

	/**
	 * Whether a product carries any of the configured terms.
	 *
	 * @param int                $product_id Product post ID.
	 * @param array<string,int[]> $terms     Taxonomy => term IDs.
	 * @return bool
	 */
	private static function matches_any( int $product_id, array $terms ): bool {
		foreach ( $terms as $taxonomy => $term_ids ) {
			if ( empty( $term_ids ) ) {
				continue;
			}

			if ( has_term( $term_ids, $taxonomy, $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the taxonomy => term ID map, expanded to include descendants.
	 *
	 * Descendant expansion is what makes the picker behave the way a merchant
	 * expects: choosing a parent category covers everything filed underneath
	 * it. `has_term()` on its own matches only the exact terms assigned to the
	 * product, so without this, scoping to "Kitchen" would miss every product
	 * filed solely under "Kitchen > Knives".
	 *
	 * `get_term_children()` reads the `{taxonomy}_children` option rather than
	 * querying, so this stays off the query path.
	 *
	 * @return array<string,int[]>
	 */
	private static function terms_by_taxonomy(): array {
		if ( null !== self::$terms ) {
			return self::$terms;
		}

		$config = Enquiry_Config::get();
		$scope  = is_array( $config['scope'] ?? null ) ? $config['scope'] : array();
		$raw    = is_array( $scope['terms'] ?? null ) ? $scope['terms'] : array();

		$map = array();

		foreach ( $raw as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}

			$taxonomy = isset( $term['taxonomy'] ) ? (string) $term['taxonomy'] : '';
			$term_id  = isset( $term['id'] ) ? (int) $term['id'] : 0;

			if ( $term_id <= 0 || ! in_array( $taxonomy, Enquiry_Config::SCOPE_TAXONOMIES, true ) ) {
				continue;
			}

			// `product_brand` only exists on WooCommerce 9.6+ (and stores that
			// never migrated off a third-party brands plugin will not have it).
			// Skipping rather than querying a non-existent taxonomy keeps this
			// silent instead of emitting WP_Errors on every render.
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			if ( ! isset( $map[ $taxonomy ] ) ) {
				$map[ $taxonomy ] = array();
			}

			$map[ $taxonomy ][] = $term_id;

			if ( is_taxonomy_hierarchical( $taxonomy ) ) {
				$children = get_term_children( $term_id, $taxonomy );
				if ( is_array( $children ) ) {
					foreach ( $children as $child_id ) {
						$map[ $taxonomy ][] = (int) $child_id;
					}
				}
			}
		}

		foreach ( $map as $taxonomy => $ids ) {
			$map[ $taxonomy ] = array_values( array_unique( $ids ) );
		}

		self::$terms = $map;

		return self::$terms;
	}

	/**
	 * Reset memoization. Test seam.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$cache = array();
		self::$terms = null;
	}
}
