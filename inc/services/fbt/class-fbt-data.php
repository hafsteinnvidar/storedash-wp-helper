<?php
/**
 * FBT Bundle Data
 *
 * The single reader of per-product FBT bundle meta, shared by the renderer
 * (product page) and the cart handler (AJAX validation). Ported from the
 * storedash-essentials `FBTRenderer` so the two plugins read the identical
 * wire format.
 *
 * Meta key families, in fallback order:
 *   - `storedash_fbt_*` — StoreDash's own keys, written by the dashboard's
 *     product editor (primary).
 *   - `woobt_*`         — WP Clever "WPC Frequently Bought Together" keys,
 *     dual-written by the dashboard for plugin compatibility, and the only
 *     keys present on stores configured with WP Clever before StoreDash.
 *
 * Values may be PHP arrays (normal) or JSON strings (a REST save that the
 * decode hook in `inc/utilities/setup.php` has not yet unwrapped) — both are
 * handled here rather than trusting the hook ran.
 *
 * @package StoreDash\Services\FBT
 * @since   1.12.0
 */

namespace StoreDash\Services\FBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FBT Data Class
 */
class FBT_Data {

	/**
	 * Per-request cache for bundle data (the same product can render more than
	 * once in responsive layouts, and the cart handler re-reads on submit).
	 *
	 * @var array<int, array|null>
	 */
	private static $cache = array();

	/**
	 * Read a meta value with fallback from storedash_fbt_ to woobt_ prefix.
	 *
	 * Uses `$single = false` + `end()` deliberately: legacy stores can carry
	 * duplicate meta rows for the same key, and `end()` returns the most
	 * recently written one (same behaviour as the essentials widget).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $base_name  Meta key suffix (e.g. 'ids', 'discount').
	 * @return mixed Meta value or empty string.
	 */
	public static function meta( int $product_id, string $base_name ) {
		$values = get_post_meta( $product_id, 'storedash_fbt_' . $base_name, false );
		if ( ! empty( $values ) ) {
			return end( $values );
		}

		$values = get_post_meta( $product_id, 'woobt_' . $base_name, false );
		if ( ! empty( $values ) ) {
			return end( $values );
		}

		return '';
	}

	/**
	 * Get the bundle for a product, or null when it has none.
	 *
	 * Shape:
	 *   items[]     — { product: WC_Product, product_id, variation_id, qty,
	 *                   checked }
	 *   before_text — per-product title override.
	 *   checked_all — whether companions start ticked.
	 *   discount    — bundle discount percent (0–100).
	 *
	 * @param int $product_id Main product ID.
	 * @return array|null
	 */
	public static function bundle( int $product_id ): ?array {
		if ( array_key_exists( $product_id, self::$cache ) ) {
			return self::$cache[ $product_id ];
		}

		// Prime all postmeta for this product in a single query.
		update_postmeta_cache( array( $product_id ) );

		$fbt_ids = self::decode_ids( self::meta( $product_id, 'ids' ) );
		if ( null === $fbt_ids ) {
			self::$cache[ $product_id ] = null;

			return null;
		}

		$checked_all = 'off' !== self::meta( $product_id, 'checked_all' );
		$discount    = self::discount( $product_id );

		// Batch-load companion products to avoid N+1 queries.
		$all_ids = array();
		foreach ( $fbt_ids as $item_data ) {
			$item_data    = (array) $item_data;
			$item_id      = isset( $item_data['id'] ) ? (int) $item_data['id'] : 0;
			$variation_id = isset( $item_data['variation_id'] ) ? (int) $item_data['variation_id'] : 0;
			$actual_id    = $variation_id > 0 ? $variation_id : $item_id;

			if ( $actual_id > 0 && $actual_id !== $product_id ) {
				$all_ids[] = $actual_id;
			}
		}

		if ( ! empty( $all_ids ) ) {
			_prime_post_caches( $all_ids, false, false );
			update_postmeta_cache( $all_ids );
			update_object_term_cache( $all_ids, 'product' );
		}

		$items = array();
		foreach ( $fbt_ids as $item_data ) {
			if ( ! is_array( $item_data ) && ! is_object( $item_data ) ) {
				continue;
			}

			$item_data    = (array) $item_data;
			$item_id      = isset( $item_data['id'] ) ? (int) $item_data['id'] : 0;
			$variation_id = isset( $item_data['variation_id'] ) ? (int) $item_data['variation_id'] : 0;

			if ( ! $item_id || $item_id === $product_id ) {
				continue;
			}

			$actual_id    = $variation_id > 0 ? $variation_id : $item_id;
			$item_product = wc_get_product( $actual_id );
			if ( ! $item_product || ! $item_product->is_purchasable() ) {
				continue;
			}

			$items[] = array(
				'product'      => $item_product,
				'product_id'   => $item_id,
				'variation_id' => $variation_id,
				'qty'          => isset( $item_data['qty'] ) && (int) $item_data['qty'] > 0 ? (int) $item_data['qty'] : 1,
				'checked'      => $checked_all,
			);
		}

		if ( empty( $items ) ) {
			self::$cache[ $product_id ] = null;

			return null;
		}

		$out = array(
			'items'       => $items,
			'before_text' => (string) self::meta( $product_id, 'before_text' ),
			'checked_all' => $checked_all,
			'discount'    => $discount,
		);

		self::$cache[ $product_id ] = $out;

		return $out;
	}

	/**
	 * The bundle discount percent for a product, clamped to (0, 100].
	 *
	 * Read server-side ONLY — the cart handler must never trust a
	 * client-supplied discount.
	 *
	 * @param int $product_id Main product ID.
	 * @return float
	 */
	public static function discount( int $product_id ): float {
		$raw = self::meta( $product_id, 'discount' );
		if ( '' === $raw ) {
			return 0.0;
		}

		$value = (float) $raw;

		return ( $value > 0 && $value <= 100 ) ? $value : 0.0;
	}

	/**
	 * Product IDs a customer is allowed to add as companions of a product.
	 *
	 * Includes both the base product ID and the variation ID of each entry, so
	 * the cart handler accepts whichever one the storefront submitted.
	 *
	 * @param int $product_id Main product ID.
	 * @return int[]
	 */
	public static function allowed_companion_ids( int $product_id ): array {
		$fbt_ids = self::decode_ids( self::meta( $product_id, 'ids' ) );
		if ( null === $fbt_ids ) {
			return array();
		}

		$allowed = array();
		foreach ( $fbt_ids as $item_data ) {
			$item_data    = (array) $item_data;
			$item_id      = isset( $item_data['id'] ) ? (int) $item_data['id'] : 0;
			$variation_id = isset( $item_data['variation_id'] ) ? (int) $item_data['variation_id'] : 0;

			if ( $item_id > 0 ) {
				$allowed[] = $item_id;
			}
			if ( $variation_id > 0 ) {
				$allowed[] = $variation_id;
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Whether a product has a renderable bundle. Cheap-ish (meta only, no
	 * product hydration) — used by placement eligibility.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function has_bundle_meta( int $product_id ): bool {
		return null !== self::decode_ids( self::meta( $product_id, 'ids' ) );
	}

	/**
	 * Normalise a raw `*_ids` value to an array of entries, or null.
	 *
	 * Handles the JSON-string form a REST save can leave behind, WP Clever's
	 * keyed-object form, and rejects the CSV form (`"12/100%/1"`) it cannot
	 * decode — same conservatism as the dashboard's fbt-meta module.
	 *
	 * @param mixed $raw Raw meta value.
	 * @return array|null
	 */
	private static function decode_ids( $raw ): ?array {
		if ( empty( $raw ) ) {
			return null;
		}

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		if ( is_object( $raw ) ) {
			$raw = (array) $raw;
		}

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Reset the in-request cache. Test seam.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$cache = array();
	}
}
