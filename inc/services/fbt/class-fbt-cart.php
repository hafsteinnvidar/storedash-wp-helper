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
	 * Hard cap on companions accepted in one submission. Bundle meta rarely
	 * holds more than a handful; this only bounds a hand-crafted request.
	 */
	const MAX_ITEMS = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_add_bundle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle_add_bundle' ) );

		// Priority 20 matches the legacy storedash-wp hook; both are idempotent
		// (absolute price from DB), so double-registration cannot compound.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_fbt_discount' ), 20 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_session_data' ), 10, 2 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'remove_companion_items' ), 10, 2 );
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
		check_ajax_referer( self::ACTION, 'nonce' );

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
			if ( empty( $cart_item['storedash_fbt_price_item'] ) ) {
				continue;
			}

			$price_pct = (float) $cart_item['storedash_fbt_price_item'];

			// 100% means no discount.
			if ( $price_pct >= 100 || $price_pct <= 0 ) {
				continue;
			}

			$item_product = wc_get_product( $cart_item['variation_id'] ?: $cart_item['product_id'] );
			if ( ! $item_product ) {
				continue;
			}

			$original_price = (float) $item_product->get_price();
			if ( $original_price > 0 ) {
				$cart_item['data']->set_price( $original_price * $price_pct / 100 );
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
