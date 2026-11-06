<?php
/**
 * FBT Cart Handler
 *
 * Two responsibilities:
 *
 *   1. The AJAX endpoint behind the hook-mode "Add selected to cart" button
 *      (`storedash_fbt_add_bundle`): adds the main product plus the selected
 *      companions in one server-side request, validating everything against
 *      the product's own bundle meta.
 *   2. The cart-price machinery that makes the bundle discount real:
 *      `woocommerce_before_calculate_totals` reprices companion lines,
 *      session-restore keeps the FBT cart-item meta alive, and removing the
 *      main product removes its companions.
 *
 * The discount contract is inherited from WP Clever via the legacy
 * storedash-wp plugin: each companion cart line carries
 * `storedash_fbt_price_item` (the percent of the original price the customer
 * pays, e.g. `90` for a 10%-off bundle), and repricing always recomputes from
 * the product's DATABASE price — never from `$cart_item['data']->get_price()` —
 * so the hook firing multiple times can never compound the discount. That
 * idempotence is also what makes it safe if a legacy storedash-wp install is
 * active alongside this plugin: both hooks set the same absolute price.
 *
 * Registered unconditionally (not gated on `FBT_Config::is_active()`): lines
 * added by the storedash-essentials REST endpoint carry the same meta and
 * previously depended on the legacy plugin for repricing. With this class
 * loaded, the helper covers them too.
 *
 * @package StoreDash\Services\FBT
 * @since   1.12.0
 */

namespace StoreDash\Services\FBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FBT Cart Class
 */
class FBT_Cart {

	/**
	 * AJAX action name (mirrors the nonce action in FBT_Placement).
	 */
	const ACTION = 'storedash_fbt_add_bundle';

	/**
	 * AJAX action that hands out a fresh ACTION nonce (cached pages).
	 */
	const REFRESH_ACTION = 'storedash_fbt_refresh';

	/**
	 * Hard cap on companions accepted in one submission. Bundle meta rarely
	 * holds more than a handful; this only bounds a hand-crafted request.
	 */
	const MAX_ITEMS = 20;

	/**
	 * Store API add-item param a headless storefront sends on a companion
	 * line: the main product's id. The discount itself is never taken from
	 * the request — it is read from the main product's bundle meta.
	 */
	const STORE_API_PARAM = 'storedash_fbt_parent';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_add_bundle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle_add_bundle' ) );

		// Nonce refresh for cached pages — see handle_refresh().
		add_action( 'wp_ajax_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
		add_action( 'wp_ajax_nopriv_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );

		// Priority 20 matches the legacy storedash-wp hook; both are idempotent
		// (absolute price from DB), so double-registration cannot compound.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_fbt_discount' ), 20 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_session_data' ), 10, 2 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'remove_companion_items' ), 10, 2 );

		// Headless storefronts add through the Store API (`/cart/add-item`),
		// one line at a time. Without these the companion lines carried no FBT
		// meta and were charged full price.
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'store_api_cart_item_data' ), 20, 2 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'link_companion_to_parent' ), 20, 6 );
	}

	/**
	 * Store API add-item: tag a companion line with the FBT meta.
	 *
	 * Only when the request names a main product (`storedash_fbt_parent`)
	 * that is already in the cart and whose bundle meta lists this product.
	 * Anything else is added as a normal, full-price line — never an error.
	 *
	 * @param array            $data    { id, quantity, variation, cart_item_data }.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function store_api_cart_item_data( $data, $request ) {
		if ( ! is_array( $data ) || ! $request instanceof \WP_REST_Request ) {
			return $data;
		}

		$parent_id = absint( $request->get_param( self::STORE_API_PARAM ) );
		$item_id   = absint( $data['id'] ?? 0 );
		if ( ! $parent_id || ! $item_id || $parent_id === $item_id ) {
			return $data;
		}

		$discount = FBT_Data::discount( $parent_id );
		if ( $discount <= 0 ) {
			return $data;
		}

		$item_product = wc_get_product( $item_id );
		if ( ! $item_product ) {
			return $data;
		}
		$allowed = FBT_Data::allowed_companion_ids( $parent_id );
		if ( ! in_array( $item_id, $allowed, true ) && ! in_array( (int) $item_product->get_parent_id(), $allowed, true ) ) {
			return $data;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart ) {
			return $data;
		}
		$parent_key = self::find_main_line_key( $cart, $parent_id );
		if ( null === $parent_key ) {
			return $data;
		}

		$data['cart_item_data'] = isset( $data['cart_item_data'] ) && is_array( $data['cart_item_data'] ) ? $data['cart_item_data'] : array();

		$data['cart_item_data']['storedash_fbt_parent_id']  = $parent_id;
		$data['cart_item_data']['storedash_fbt_parent_key'] = $parent_key;
		$data['cart_item_data']['storedash_fbt_price_item'] = (string) ( 100 - $discount );

		return $data;
	}

	/**
	 * Record a Store API companion line on its main line, so removing the
	 * main product removes it too (same as the AJAX bundle path).
	 *
	 * @param string $cart_item_key  New line's key.
	 * @param int    $product_id     Product id.
	 * @param int    $quantity       Quantity.
	 * @param int    $variation_id   Variation id.
	 * @param array  $variation      Variation attributes.
	 * @param array  $cart_item_data Cart item data.
	 * @return void
	 */
	public function link_companion_to_parent( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
		$parent_key = is_array( $cart_item_data ) ? ( $cart_item_data['storedash_fbt_parent_key'] ?? '' ) : '';
		if ( '' === $parent_key || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		if ( ! isset( $cart->cart_contents[ $parent_key ] ) ) {
			return;
		}

		$keys = (array) ( $cart->cart_contents[ $parent_key ]['storedash_fbt_keys'] ?? array() );
		if ( ! in_array( $cart_item_key, $keys, true ) ) {
			$keys[] = $cart_item_key;
			$cart->cart_contents[ $parent_key ]['storedash_fbt_keys'] = $keys;
		}
	}

	/**
	 * Key of the main product's own line (not an FBT companion of something
	 * else), or null when it is not in the cart.
	 *
	 * @param \WC_Cart $cart      Cart.
	 * @param int      $parent_id Main product id (parent id for variables).
	 * @return string|null
	 */
	private static function find_main_line_key( $cart, int $parent_id ): ?string {
		foreach ( $cart->get_cart() as $key => $line ) {
			if ( ! empty( $line['storedash_fbt_parent_id'] ) ) {
				continue;
			}
			if ( (int) ( $line['product_id'] ?? 0 ) === $parent_id || (int) ( $line['variation_id'] ?? 0 ) === $parent_id ) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * The FBT bundle unit price for a cart line, or null when the line is not
	 * a discounted FBT companion.
	 *
	 * Computed from the product's DATABASE price (sale price when set), never
	 * from `$cart_item['data']->get_price()`, so it cannot compound. Public so
	 * the discount engine can compare it against its own winner — the two
	 * never stack; the cheaper one wins (see Cart_Discount_Orchestrator).
	 *
	 * @param array $cart_item Cart line.
	 * @return float|null
	 */
	public static function fbt_unit_price( $cart_item ): ?float {
		if ( ! is_array( $cart_item ) || empty( $cart_item['storedash_fbt_price_item'] ) ) {
			return null;
		}

		$price_pct = (float) $cart_item['storedash_fbt_price_item'];
		if ( $price_pct >= 100 || $price_pct <= 0 ) {
			return null;
		}

		$item_product = wc_get_product( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'] );
		if ( ! $item_product ) {
			return null;
		}

		$original_price = (float) $item_product->get_price();

		return $original_price > 0 ? $original_price * $price_pct / 100 : null;
	}

	/**
	 * AJAX: hand a fresh bundle nonce to the page's own JavaScript.
	 *
	 * Full-page caching freezes the nonce FBT_Placement localized into the
	 * HTML; past the nonce lifetime (~12–24h) every "Add bundle" click from
	 * that cached product page fails. The widget script only calls this after
	 * an add comes back 403, then retries once, so uncached pages never pay
	 * for it. The add button is usually the shopper's first interaction, so a
	 * refresh-on-interaction (as the enquiry form does) would race the click.
	 *
	 * Deliberately unauthenticated and NOT nonce-checked (it is the nonce
	 * bootstrap); same-origin policy keeps the response unreadable to other
	 * origins. Own rate-limit counter.
	 *
	 * @return void
	 */
	public function handle_refresh(): void {
		$refresh_key   = 'storedash_fbt_rf_' . md5( \StoreDash_Helpers::get_client_ip() );
		$refresh_count = (int) get_transient( $refresh_key );

		if ( $refresh_count >= 100 ) {
			wp_send_json_error( array( 'message' => 'rate_limited' ), 429 );
		}
		set_transient( $refresh_key, $refresh_count + 1, HOUR_IN_SECONDS );

		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::ACTION ) ) );
	}

	/**
	 * AJAX: add main product + selected companions.
	 *
	 * POST params:
	 *   nonce      — `storedash_fbt_add_bundle` nonce (from FBT_Placement).
	 *   product_id — main product id.
	 *   items      — JSON array of { id, qty }, ids validated against the
	 *                product's own bundle meta. An empty selection is valid:
	 *                the button then behaves as a plain add-to-cart.
	 *
	 * The discount is read SERVER-SIDE from the product meta — a client cannot
	 * submit its own percentage.
	 *
	 * @return void
	 */
	public function handle_add_bundle(): void {
		// JSON 403 (not check_ajax_referer's bare "-1" die) so the script can
		// tell a stale cached-page nonce apart and retry with a fresh one.
		if ( ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security verification failed. Please refresh the page and try again.', 'storedash' ) ),
				403
			);
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$raw_items  = isset( $_POST['items'] ) ? (string) wp_unslash( $_POST['items'] ) : '[]';

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing product.', 'storedash' ) ), 400 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			wp_send_json_error( array( 'message' => __( 'This product cannot be purchased.', 'storedash' ) ), 400 );
		}

		$items = json_decode( $raw_items, true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		$items = array_slice( $items, 0, self::MAX_ITEMS );

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		$discount   = FBT_Data::discount( $product_id );
		$price_item = $discount > 0 ? (string) ( 100 - $discount ) : '100';
		$allowed    = FBT_Data::allowed_companion_ids( $product_id );

		$main_key = WC()->cart->add_to_cart( $product_id, 1 );
		if ( ! $main_key ) {
			// WooCommerce queued the reason (out of stock, purchase limit…) as
			// a notice; surface a generic message and drop the notices so they
			// don't leak onto the next page load.
			wc_clear_notices();
			wp_send_json_error( array( 'message' => __( 'Could not add to cart. Please try again.', 'storedash' ) ), 400 );
		}

		$companion_keys = array();
		$failed         = array();

		foreach ( $items as $item ) {
			$item = (array) $item;
			$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$qty  = isset( $item['qty'] ) ? max( 1, absint( $item['qty'] ) ) : 1;

			if ( ! $id ) {
				continue;
			}

			if ( ! in_array( $id, $allowed, true ) ) {
				$failed[] = $id;
				continue;
			}

			$key = WC()->cart->add_to_cart(
				$id,
				$qty,
				0,
				array(),
				array(
					'storedash_fbt_parent_id'  => $product_id,
					'storedash_fbt_parent_key' => $main_key,
					'storedash_fbt_price_item' => $price_item,
				)
			);

			if ( $key ) {
				$companion_keys[] = $key;
			} else {
				$failed[] = $id;
			}
		}

		if ( ! empty( $companion_keys ) ) {
			WC()->cart->cart_contents[ $main_key ]['storedash_fbt_keys'] = $companion_keys;
		}

		wc_clear_notices();
		WC()->cart->calculate_totals();

		wp_send_json_success(
			array(
				'main_key'       => $main_key,
				'companion_keys' => $companion_keys,
				'failed'         => $failed,
				'discount'       => $discount,
				'items_count'    => WC()->cart->get_cart_contents_count(),
				'cart_hash'      => WC()->cart->get_cart_hash(),
			)
		);
	}

	/**
	 * Reprice companion lines during totals calculation.
	 *
	 * Always recomputes from the product's DATABASE price so repeated firings
	 * (and a concurrently-active legacy storedash-wp install) can never
	 * compound the discount.
	 *
	 * @param \WC_Cart $cart Cart object.
	 * @return void
	 */
	public function apply_fbt_discount( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$price = self::fbt_unit_price( $cart_item );
			if ( null !== $price ) {
				$cart_item['data']->set_price( $price );
			}
		}
	}

	/**
	 * Restore FBT cart-item meta when the cart is loaded from session.
	 *
	 * Without this the meta is lost on session restore and discounts silently
	 * disappear from carts between visits.
	 *
	 * @param array $cart_item    Cart item being restored.
	 * @param array $session_data Raw session data for this item.
	 * @return array
	 */
	public function restore_session_data( $cart_item, $session_data ) {
		foreach ( array(
			'storedash_fbt_parent_id',
			'storedash_fbt_parent_key',
			'storedash_fbt_price_item',
			'storedash_fbt_keys',
		) as $key ) {
			if ( isset( $session_data[ $key ] ) ) {
				$cart_item[ $key ] = $session_data[ $key ];
			}
		}

		return $cart_item;
	}

	/**
	 * Remove companion lines when the main product is removed from the cart.
	 *
	 * @param string   $cart_item_key Removed item's cart key.
	 * @param \WC_Cart $cart          Cart object.
	 * @return void
	 */
	public function remove_companion_items( $cart_item_key, $cart ): void {
		$removed_item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;

		if ( ! $removed_item || empty( $removed_item['storedash_fbt_keys'] ) ) {
			return;
		}

		foreach ( (array) $removed_item['storedash_fbt_keys'] as $companion_key ) {
			if ( isset( $cart->cart_contents[ $companion_key ] ) ) {
				$cart->remove_cart_item( $companion_key );
			}
		}
	}
}

// Initialize
new FBT_Cart();
