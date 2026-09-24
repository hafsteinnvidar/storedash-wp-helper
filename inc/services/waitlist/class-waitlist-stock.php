<?php
/**
 * Waitlist Stock Resolution
 *
 * Answers "does this product need a waitlist form, and for which variations?"
 * without hydrating product objects.
 *
 * WHY THIS EXISTS: the Elementor widget previously called
 * `$product->get_available_variations()`, which instantiates every variation as
 * a full `WC_Product_Variation` — meta, prices, images, formatted price HTML.
 * On a 100-variation product that is hundreds of milliseconds. That cost was
 * tolerable while it only applied to pages where a merchant had deliberately
 * placed the widget; it is not tolerable now that automatic placement runs on
 * every variable product page in the store.
 *
 * WooCommerce already maintains the cheap answer in `wc_product_meta_lookup`
 * (`product_id` PK, `stock_status` indexed), so variation stock is one indexed
 * query. Variation attribute labels come from a second targeted postmeta query
 * covering only the out-of-stock variations, which is typically a handful.
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.8.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waitlist Stock Class
 */
class Waitlist_Stock {

	/**
	 * Per-request memo, keyed by product ID.
	 *
	 * The hook placement and the footer fallback can both ask about the same
	 * product in one request; this keeps that to a single pair of queries.
	 *
	 * @var array<int, array>
	 */
	private static $memo = array();

	/**
	 * Resolve waitlist eligibility for a product.
	 *
	 * @param \WC_Product $product Product (or variation).
	 * @return array{eligible:bool,is_variable:bool,all_oos:bool,variations:array,product_id:int,variation_id:int}
	 */
	public static function resolve( $product ): array {
		$empty = array(
			'eligible'     => false,
			'is_variable'  => false,
			'all_oos'      => false,
			'variations'   => array(),
			'product_id'   => 0,
			'variation_id' => 0,
		);

		if ( ! $product instanceof \WC_Product ) {
			return $empty;
		}

		$key = $product->get_id();
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$product_id   = $product->get_id();
		$variation_id = 0;

		if ( $product->is_type( 'variation' ) ) {
			$variation_id = $product->get_id();
			$product_id   = $product->get_parent_id();
		}

		if ( $product->is_type( 'variable' ) ) {
			$result = self::resolve_variable( $product, $product_id );
		} else {
			$result = array(
				'eligible'     => ! $product->is_in_stock(),
				'is_variable'  => false,
				'all_oos'      => ! $product->is_in_stock(),
				'variations'   => array(),
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			);
		}

		self::$memo[ $key ] = $result;

		return $result;
	}

	/**
	 * Resolve a variable product's out-of-stock variations.
	 *
	 * @param \WC_Product $product    Variable product.
	 * @param int         $product_id Parent product ID.
	 * @return array
	 */
	private static function resolve_variable( $product, int $product_id ): array {
		$children = array_map( 'intval', (array) $product->get_children() );
		$children = array_values( array_filter( $children ) );

		if ( empty( $children ) ) {
			return array(
				'eligible'     => false,
				'is_variable'  => true,
				'all_oos'      => false,
				'variations'   => array(),
				'product_id'   => $product_id,
				'variation_id' => 0,
			);
		}

		$statuses = self::lookup_stock_statuses( $children );

		$oos_ids = array();
		foreach ( $children as $child_id ) {
			// A child missing from the lookup table is resolved individually
			// rather than assumed in stock — the lookup table is normally kept
			// in sync by WooCommerce, but a partially-regenerated table must not
			// silently hide waitlist-eligible variations.
			if ( ! array_key_exists( $child_id, $statuses ) ) {
				$child = wc_get_product( $child_id );
				if ( $child && ! $child->is_in_stock() ) {
					$oos_ids[] = $child_id;
				}
				continue;
			}

			// Only 'outofstock' is waitlist-eligible. 'onbackorder' is purchasable
			// (WC's is_in_stock() is true), and create_entry rejects in-stock
			// variations — listing it would offer a choice that always errors.
			if ( 'outofstock' === $statuses[ $child_id ] ) {
				$oos_ids[] = $child_id;
			}
		}

		if ( empty( $oos_ids ) ) {
			return array(
				'eligible'     => false,
				'is_variable'  => true,
				'all_oos'      => false,
				'variations'   => array(),
				'product_id'   => $product_id,
				'variation_id' => 0,
			);
		}

		return array(
			'eligible'     => true,
			'is_variable'  => true,
			'all_oos'      => count( $oos_ids ) === count( $children ),
			'variations'   => self::build_variation_labels( $oos_ids ),
			'product_id'   => $product_id,
			'variation_id' => 0,
		);
	}

	/**
	 * Fetch stock statuses for a set of product IDs from the Woo lookup table.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int, string> Map of product ID => stock status.
	 */
	private static function lookup_stock_statuses( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'wc_product_meta_lookup';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, stock_status FROM {$table} WHERE product_id IN ({$placeholders})", // phpcs:ignore
				$ids
			)
		);
		// phpcs:enable

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->product_id ] = (string) $row->stock_status;
		}

		return $map;
	}

	/**
	 * Build human-readable labels for the given variation IDs.
	 *
	 * One postmeta query for all `attribute_*` rows across the out-of-stock
	 * variations, rather than hydrating each variation.
	 *
	 * @param int[] $variation_ids Variation IDs, in display order.
	 * @return array<int, array{variation_id:int,label:string}>
	 */
	private static function build_variation_labels( array $variation_ids ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value
				 FROM {$wpdb->postmeta}
				 WHERE post_id IN ({$placeholders})
				   AND meta_key LIKE 'attribute\_%'", // phpcs:ignore
				$variation_ids
			)
		);
		// phpcs:enable

		$attributes = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row->post_id;
			if ( ! isset( $attributes[ $post_id ] ) ) {
				$attributes[ $post_id ] = array();
			}
			$attributes[ $post_id ][ (string) $row->meta_key ] = (string) $row->meta_value;
		}

		$out = array();
		foreach ( $variation_ids as $variation_id ) {
			$out[] = array(
				'variation_id' => $variation_id,
				'label'        => self::format_label( $attributes[ $variation_id ] ?? array(), $variation_id ),
			);
		}

		return $out;
	}

	/**
	 * Format an attribute map into a display label ("Red / Large").
	 *
	 * @param array $attributes   Map of `attribute_*` meta key => value.
	 * @param int   $variation_id Variation ID, used for the fallback label.
	 * @return string
	 */
	private static function format_label( array $attributes, int $variation_id ): string {
		$parts = array();

		foreach ( $attributes as $meta_key => $value ) {
			if ( '' === $value ) {
				// An empty attribute value means "any" — it carries no
				// information for the shopper, so it is skipped rather than
				// rendered as a blank segment.
				continue;
			}

			$taxonomy = substr( $meta_key, strlen( 'attribute_' ) );

			if ( taxonomy_exists( $taxonomy ) ) {
				$term    = get_term_by( 'slug', $value, $taxonomy );
				$parts[] = ( $term && ! is_wp_error( $term ) ) ? $term->name : ucfirst( $value );
			} else {
				$parts[] = ucfirst( $value );
			}
		}

		if ( empty( $parts ) ) {
			$product = wc_get_product( $variation_id );

			return $product ? $product->get_name() : (string) $variation_id;
		}

		return implode( ' / ', $parts );
	}

	/**
	 * Reset the per-request memo. Test seam.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = array();
	}
}
