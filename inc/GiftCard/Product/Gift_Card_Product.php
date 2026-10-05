<?php
/**
 * Gift card product flag (contract A) + product-level rules.
 *
 * - Parent meta `_storedash_gift_card = yes` (simple or variable; variations
 *   inherit). Checkbox in the product General tab.
 * - Saving a flagged product forces virtual=yes and tax_status=none (VAT is
 *   charged at redemption, never at sale). The same is enforced at runtime
 *   through getter filters, so a later manual change cannot make a gift card
 *   taxable or shippable.
 * - Gift card products are excluded from WooCommerce coupons (Storedash
 *   discounts and Rewards credit check is_gift_card() themselves).
 *
 * @package StoreDash\GiftCard\Product
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product flag.
 *
 * @since 1.24.0
 */
class Gift_Card_Product {

	/**
	 * Parent product meta key.
	 */
	const META = '_storedash_gift_card';

	/**
	 * Per-request cache: parent product id => bool.
	 *
	 * @var array
	 */
	protected static $cache = array();

	/**
	 * Whether a product (or variation, via its parent) is a gift card.
	 *
	 * Safe to call anywhere — returns false when WordPress is not loaded.
	 *
	 * @param \WC_Product|int|null $product Product or product id.
	 * @return bool
	 */
	public static function is_gift_card( $product ): bool {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			$id        = $parent_id > 0 ? $parent_id : (int) $product->get_id();
		} else {
			$id = (int) $product;
		}
		return self::is_gift_card_id( $id );
	}

	/**
	 * Whether a PARENT product id carries the flag.
	 *
	 * @param int $parent_id Parent (or simple) product id.
	 * @return bool
	 */
	public static function is_gift_card_id( int $parent_id ): bool {
		if ( $parent_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		if ( ! isset( self::$cache[ $parent_id ] ) ) {
			self::$cache[ $parent_id ] = 'yes' === get_post_meta( $parent_id, self::META, true );
		}
		return self::$cache[ $parent_id ];
	}

	/**
	 * Whether any cart line is a gift card product.
	 *
	 * @param \WC_Cart|null $cart Cart.
	 * @return bool
	 */
	public static function cart_has_gift_card( $cart ): bool {
		if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) {
			return false;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['data'] ) && self::is_gift_card( $item['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		// Admin flag.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ), 20, 1 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation' ), 20, 2 );

		// Runtime enforcement: virtual + not taxable.
		add_filter( 'woocommerce_is_virtual', array( $this, 'filter_is_virtual' ), 20, 2 );
		add_filter( 'woocommerce_product_get_tax_status', array( $this, 'filter_tax_status' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_tax_status', array( $this, 'filter_tax_status' ), 20, 2 );

		// Coupons never touch gift card products.
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'filter_coupon_valid_for_product' ), 20, 2 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'filter_coupon_items' ), 20, 1 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'filter_coupon_discount' ), 20, 3 );
	}

	/**
	 * Checkbox in the General tab.
	 */
	public function render_field(): void {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}
		echo '<div class="options_group show_if_simple show_if_variable">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META,
				'label'       => __( 'Gift card', 'storedash' ),
				'description' => __( 'Sell this product as a Storedash gift card. Each unit bought issues its own code worth the price paid. Gift cards are virtual and not taxed at sale (VAT is charged when the card is used).', 'storedash' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Save the flag; flagged products become virtual + tax-free.
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function save_product( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// Nonce already verified by WC_Admin_Meta_Boxes before this action fires.
		$flag = isset( $_POST[ self::META ] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST[ self::META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		unset( self::$cache[ (int) $product->get_id() ] );

		if ( ! $flag ) {
			$product->delete_meta_data( self::META );
			return;
		}

		$product->update_meta_data( self::META, 'yes' );
		$product->set_virtual( true );
		$product->set_tax_status( 'none' );

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation && ! $variation->get_virtual( 'edit' ) ) {
					$variation->set_virtual( true );
					$variation->save();
				}
			}
		}
	}

	/**
	 * Variations of a gift card parent are always virtual.
	 *
	 * @param \WC_Product_Variation $variation Variation being saved.
	 * @param int                   $index     Loop index.
	 */
	public function save_variation( $variation, $index ): void {
		if ( $variation instanceof \WC_Product && self::is_gift_card_id( (int) $variation->get_parent_id() ) ) {
			$variation->set_virtual( true );
		}
	}

	/**
	 * Runtime: gift cards never need shipping.
	 *
	 * @param bool        $is_virtual WooCommerce's answer.
	 * @param \WC_Product $product    Product.
	 * @return bool
	 */
	public function filter_is_virtual( $is_virtual, $product ) {
		return ( ! $is_virtual && self::is_gift_card( $product ) ) ? true : $is_virtual;
	}

	/**
	 * Runtime: gift cards are never taxed at sale.
	 *
	 * @param string      $status  Tax status.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function filter_tax_status( $status, $product ) {
		return self::is_gift_card( $product ) ? 'none' : $status;
	}

	/**
	 * Product-restricted coupons never match gift cards.
	 *
	 * @param bool        $valid   Valid.
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function filter_coupon_valid_for_product( $valid, $product ) {
		return ( $valid && self::is_gift_card( $product ) ) ? false : $valid;
	}

	/**
	 * Cart-wide coupons skip gift card lines (WC 8.8+).
	 *
	 * @param array $items Items the coupon would apply to.
	 * @return array
	 */
	public function filter_coupon_items( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		return array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return ! ( isset( $item->product ) && self::is_gift_card( $item->product ) );
				}
			)
		);
	}

	/**
	 * Older WooCommerce: zero any coupon discount computed for a gift card line.
	 *
	 * @param float        $discount          Discount.
	 * @param float        $discounting_amount Amount being discounted.
	 * @param array|object $cart_item          Cart item array or order item.
	 * @return float
	 */
	public function filter_coupon_discount( $discount, $discounting_amount, $cart_item ) {
		$product = null;
		if ( is_array( $cart_item ) && isset( $cart_item['data'] ) ) {
			$product = $cart_item['data'];
		} elseif ( is_object( $cart_item ) && method_exists( $cart_item, 'get_product' ) ) {
			$product = $cart_item->get_product();
		}
		return ( $product && self::is_gift_card( $product ) ) ? 0 : $discount;
	}
}
