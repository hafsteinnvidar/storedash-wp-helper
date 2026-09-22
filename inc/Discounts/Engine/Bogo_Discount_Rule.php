<?php
/**
 * BOGO Discount Rule Engine
 *
 * Implements Buy X Get Y (BOGO) discount logic.
 * Based on YITH's proven pattern of adding duplicate cart items with zero price.
 *
 * @package StoreDash\Discounts\Engine
 * @since   1.0.0
 */

namespace StoreDash\Discounts\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Discounts\Sync\Discount_DB_Handler;

/**
 * Handles Buy X Get Y discount calculations and cart modifications.
 *
 * @since 1.0.0
 */
class Bogo_Discount_Rule {

	/**
	 * DB handler instance.
	 *
	 * @since 1.0.0
	 * @var Discount_DB_Handler
	 */
	protected $db_handler;

	/**
	 * Local cache of fetched discounts by ID.
	 *
	 * Prevents repeated DB lookups for the same discount within a request
	 * (e.g., sync_free_item_quantity with multiple free items from one rule).
	 *
	 * @since 2.1.0
	 * @var array
	 */
	protected $discount_cache = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->db_handler = new Discount_DB_Handler();
	}

	/**
	 * Register BOGO hooks
	 *
	 * Priority 102 on woocommerce_before_calculate_totals ensures discounts apply
	 * in ALL contexts: cart, mini-cart, checkout, and AJAX updates.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		// Set free item price to 0 (priority 103)
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_free_item_price' ), 103 );

		// Hide quantity field for free items
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'hide_quantity_for_free_items' ), 10, 3 );

		// Hide remove link for free items
		add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'hide_remove_link_for_free_items' ), 10, 2 );

		// Sync free item quantity with parent when parent quantity changes
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'sync_free_item_quantity' ), 10, 4 );

		// Remove free item when parent is removed
		add_action( 'woocommerce_cart_item_removed', array( $this, 'remove_free_item_with_parent' ), 10, 2 );

		// Add "FREE" label to cart item name
		add_filter( 'woocommerce_cart_item_name', array( $this, 'add_free_label_to_cart_item' ), 10, 3 );
	}

	/**
	 * Apply a resolver-selected BOGO win to one cart line.
	 *
	 * Eligibility/winner selection already happened in Discount_Resolver; this
	 * method only performs the free-item mechanics (add or update quantity).
	 *
	 * @since 3.2.0
	 *
	 * @param \WC_Cart $cart          Cart object.
	 * @param string   $cart_item_key Paid (parent) cart line key.
	 * @param array    $cart_item     Paid cart line.
	 * @param object   $result        Resolver application ({discount, bogo:{…, free_quantity}}).
	 */
	public function apply_bogo_line( $cart, $cart_item_key, $cart_item, $result ) {
		$free_quantity    = (int) $result->bogo['free_quantity'];
		$discount_percent = (float) $result->bogo['discount_percent'];

		if ( $free_quantity <= 0 ) {
			return;
		}

		$this->add_free_item_to_cart( $cart, $cart_item_key, $cart_item, $free_quantity, $result->discount, $discount_percent );
	}

	/**
	 * Set free item price to 0
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function set_free_item_price( $cart ) {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['storedash_is_free_item'] ) ) {
				$discount_percent = isset( $cart_item['storedash_discount_percent'] ) ? $cart_item['storedash_discount_percent'] : 100;

				// Derive the base from the product's regular/sale price, NOT
				// get_price(). before_calculate_totals fires multiple times per
				// request and set_price() mutates get_price(), so reading
				// get_price() here would compound the reduction on every firing
				// for a partial (<100%) BOGO (e.g. 50% → 50 → 25 → …). Regular/
				// sale price is never mutated by set_price(), so this is idempotent.
				$product = $cart_item['data'];
				$sale    = (string) $product->get_sale_price();
				$base    = ( '' !== $sale && (float) $sale > 0 ) ? (float) $sale : (float) $product->get_regular_price();
				if ( $base <= 0 ) {
					$base = (float) $product->get_price();
				}

				// Calculate discounted price
				$new_price = $base * ( 1 - ( $discount_percent / 100 ) );

				$product->set_price( $new_price );
			}
		}
	}

	/**
	 * Add free item to cart
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Cart $cart          Cart object.
	 * @param string   $parent_key    Parent cart item key.
	 * @param array    $parent_item   Parent cart item data.
	 * @param int      $free_quantity Quantity of free items.
	 * @param object   $discount      Discount object.
	 * @param float    $discount_percent Discount percentage.
	 */
	protected function add_free_item_to_cart( $cart, $parent_key, $parent_item, $free_quantity, $discount, $discount_percent ) {
		// Check if free item already exists for this parent
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['storedash_bogo_parent'] ) && $cart_item['storedash_bogo_parent'] === $parent_key ) {
				// Update quantity if different
				if ( $cart_item['quantity'] !== $free_quantity ) {
					$cart->set_quantity( $cart_item_key, $free_quantity, false );
				}
				return;
			}
		}

		// Create cart item data for free item
		$cart_item_data = array(
			'storedash_is_free_item'     => true,
			'storedash_bogo_parent'      => $parent_key,
			'storedash_bogo_rule_id'     => $discount->id,
			'storedash_discount_percent' => $discount_percent,
		);

		// Add variation data if parent is a variation
		if ( isset( $parent_item['variation_id'] ) && $parent_item['variation_id'] ) {
			$cart_item_data['variation_id'] = $parent_item['variation_id'];
			$cart_item_data['variation']    = $parent_item['variation'];
		}

		// Add free item to cart
		$cart->add_to_cart(
			$parent_item['product_id'],
			$free_quantity,
			isset( $parent_item['variation_id'] ) ? $parent_item['variation_id'] : 0,
			isset( $parent_item['variation'] ) ? $parent_item['variation'] : array(),
			$cart_item_data
		);
	}

	/**
	 * Hide quantity field for free items
	 *
	 * @since 1.0.0
	 *
	 * @param string $product_quantity HTML for quantity input.
	 * @param string $cart_item_key    Cart item key.
	 * @param array  $cart_item        Cart item data.
	 * @return string Modified HTML.
	 */
	public function hide_quantity_for_free_items( $product_quantity, $cart_item_key, $cart_item ) {
		if ( isset( $cart_item['storedash_is_free_item'] ) ) {
			return sprintf( '<span class="quantity">%d</span>', $cart_item['quantity'] );
		}
		return $product_quantity;
	}

	/**
	 * Hide remove link for free items
	 *
	 * @since 1.0.0
	 *
	 * @param string $link      Remove link HTML.
	 * @param string $cart_item_key Cart item key.
	 * @return string Modified HTML.
	 */
	public function hide_remove_link_for_free_items( $link, $cart_item_key ) {
		$cart      = WC()->cart;
		$cart_item = $cart->get_cart_item( $cart_item_key );

		if ( $cart_item && isset( $cart_item['storedash_is_free_item'] ) ) {
			return '';
		}

		return $link;
	}

	/**
	 * Sync free item quantity with parent
	 *
	 * @since 1.0.0
	 *
	 * @param string   $cart_item_key Cart item key.
	 * @param int      $quantity      New quantity.
	 * @param int      $old_quantity  Old quantity.
	 * @param \WC_Cart $cart        Cart object.
	 */
	public function sync_free_item_quantity( $cart_item_key, $quantity, $old_quantity, $cart ) {
		// Find free items that belong to this parent
		foreach ( $cart->get_cart() as $free_item_key => $free_item ) {
			if ( isset( $free_item['storedash_bogo_parent'] ) && $free_item['storedash_bogo_parent'] === $cart_item_key ) {
				// Get discount config (use cache to avoid repeated DB lookups)
				$discount_id = $free_item['storedash_bogo_rule_id'];
				if ( ! isset( $this->discount_cache[ $discount_id ] ) ) {
					$this->discount_cache[ $discount_id ] = $this->db_handler->get_discount( $discount_id );
				}
				$discount = $this->discount_cache[ $discount_id ];

				if ( $discount ) {
					$config  = json_decode( $discount->rule_config, true );
					$buy_qty = isset( $config['buy_quantity'] ) ? (int) $config['buy_quantity'] : 1;
					$get_qty = isset( $config['get_quantity'] ) ? (int) $config['get_quantity'] : 1;

					// Calculate new free quantity
					$free_quantity = floor( $quantity / $buy_qty ) * $get_qty;

					if ( $free_quantity > 0 ) {
						$cart->set_quantity( $free_item_key, $free_quantity, false );
					} else {
						$cart->remove_cart_item( $free_item_key );
					}
				}
			}
		}
	}

	/**
	 * Remove free item when parent is removed
	 *
	 * @since 1.0.0
	 *
	 * @param string   $cart_item_key Cart item key.
	 * @param \WC_Cart $cart          Cart object.
	 */
	public function remove_free_item_with_parent( $cart_item_key, $cart ) {
		// Find and remove free items that belong to this parent
		foreach ( $cart->get_cart() as $free_item_key => $free_item ) {
			if ( isset( $free_item['storedash_bogo_parent'] ) && $free_item['storedash_bogo_parent'] === $cart_item_key ) {
				$cart->remove_cart_item( $free_item_key );
			}
		}
	}

	/**
	 * Add "FREE" label to cart item name
	 *
	 * @since 1.0.0
	 *
	 * @param string $product_name  Product name HTML.
	 * @param array  $cart_item     Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string Modified product name.
	 */
	public function add_free_label_to_cart_item( $product_name, $cart_item, $cart_item_key ) {
		if ( isset( $cart_item['storedash_is_free_item'] ) ) {
			$discount_percent = isset( $cart_item['storedash_discount_percent'] ) ? $cart_item['storedash_discount_percent'] : 100;

			if ( $discount_percent >= 100 ) {
				$label = '<span class="storedash-free-label" style="color: #4CAF50; font-weight: bold; margin-left: 10px;">FREE</span>';
			} else {
				$label = sprintf(
					'<span class="storedash-discount-label" style="color: #4CAF50; font-weight: bold; margin-left: 10px;">%d%% OFF</span>',
					$discount_percent
				);
			}
			$product_name .= ' ' . $label;
		}
		return $product_name;
	}
}
