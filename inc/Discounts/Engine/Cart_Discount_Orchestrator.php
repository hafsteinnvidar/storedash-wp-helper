<?php
/**
 * Cart Discount Orchestrator — the ONLY place discounts touch cart prices.
 *
 * One woocommerce_before_calculate_totals hook (priority 102). For every cart
 * line it asks Discount_Resolver for the ONE winning discount (all rule types
 * compete) and applies it: price/quantity rules via set_price(), BOGO via the
 * Bogo engine's free-item mechanics. Idempotent across the multiple firings of
 * before_calculate_totals: prices always derive from regular/sale price (which
 * set_price() never mutates), and lines are only restored when they carry our
 * own marker.
 *
 * @package StoreDash\Discounts\Engine
 * @since   3.2.0
 */

namespace StoreDash\Discounts\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies resolved discounts to the cart.
 *
 * @since 3.2.0
 */
class Cart_Discount_Orchestrator {

	/**
	 * Resolver instance.
	 *
	 * @var Discount_Resolver
	 */
	protected $resolver;

	/**
	 * BOGO engine (free-item mechanics only).
	 *
	 * @var Bogo_Discount_Rule
	 */
	protected $bogo_engine;

	/**
	 * Re-entrancy guard (add_to_cart inside BOGO application re-fires the hook).
	 *
	 * @var bool
	 */
	protected $is_processing = false;

	/**
	 * Re-entrancy guard for the cart-line on-sale filter.
	 *
	 * @var bool
	 */
	protected $is_checking_sale = false;

	/**
	 * Constructor with test-friendly injection.
	 *
	 * @param Discount_Resolver|null  $resolver    Optional resolver.
	 * @param Bogo_Discount_Rule|null $bogo_engine Optional BOGO engine.
	 */
	public function __construct( $resolver = null, $bogo_engine = null ) {
		$this->resolver    = ( null !== $resolver ) ? $resolver : new Discount_Resolver();
		$this->bogo_engine = ( null !== $bogo_engine ) ? $bogo_engine : new Bogo_Discount_Rule();
	}

	/**
	 * Register the single cart hook.
	 */
	public function register_hooks() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discounts_to_cart' ), 102 );
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'filter_cart_line_is_on_sale' ), 100, 2 );
	}

	/**
	 * Report a cart line priced down by a StoreDash discount as on sale.
	 *
	 * Discounts are applied with set_price() and never write a sale_price, so
	 * WooCommerce saw every discounted line as full price and coupons set to
	 * "exclude sale items" stacked on top. Coupon validation asks each cart
	 * line's product is_on_sale() (WC_Coupon::is_valid_for_product,
	 * WC_Discounts::validate_coupon_sale_items), so answering here makes those
	 * coupons skip the line — or reject a fixed-cart coupon outright.
	 *
	 * Resolved live rather than read from the storedash_discount_applied marker:
	 * the Store API validates a coupon on apply before totals (and this
	 * orchestrator) run in that request, when the marker is missing or stale.
	 *
	 * Only product objects that ARE a cart line's `data` are considered, so
	 * catalog surfaces and wc/v3 (where these hooks are never registered) are
	 * untouched. BOGO lines are not price-cut and do not count as on sale.
	 *
	 * @since 1.17.1
	 *
	 * @param bool        $on_sale Whether WooCommerce considers it on sale.
	 * @param \WC_Product $product Product object.
	 * @return bool
	 */
	public function filter_cart_line_is_on_sale( $on_sale, $product ) {
		if ( $on_sale || $this->is_checking_sale || ! function_exists( 'WC' ) ) {
			return $on_sale;
		}

		$cart = WC()->cart;
		if ( ! $cart ) {
			return $on_sale;
		}

		$this->is_checking_sale = true;
		try {
			foreach ( $cart->get_cart() as $cart_item ) {
				if ( ! isset( $cart_item['data'] ) || $cart_item['data'] !== $product ) {
					continue;
				}
				if ( isset( $cart_item['storedash_is_free_item'] ) ) {
					return $on_sale;
				}

				$result = $this->resolver->resolve( $product, (int) $cart_item['quantity'], 'cart' );
				return ( $result && 'bogo' !== $result->kind && null !== $result->unit_price ) ? true : $on_sale;
			}
		} finally {
			$this->is_checking_sale = false;
		}

		return $on_sale;
	}

	/**
	 * Resolve and apply exactly one discount per cart line.
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function apply_discounts_to_cart( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( $this->is_processing ) {
			return;
		}
		$this->is_processing = true;

		try {
			// Cart state (totals, coupons, quantities) may have changed since the
			// last firing — resolved winners must be recomputed, not replayed.
			$this->resolver->clear_cache();

			$bogo_winners = array();

			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				// Free items are outputs of BOGO application, never inputs.
				if ( isset( $cart_item['storedash_is_free_item'] ) ) {
					continue;
				}

				$product = $cart_item['data'];
				$result  = $this->resolver->resolve( $product, (int) $cart_item['quantity'], 'cart' );

				if ( $result && 'bogo' === $result->kind ) {
					$this->bogo_engine->apply_bogo_line( $cart, $cart_item_key, $cart_item, $result );
					$bogo_winners[ $cart_item_key ] = true;
					// The paid line itself is not price-cut by BOGO; undo any
					// price we set in an earlier round.
					$this->restore_line( $cart, $cart_item_key, $cart_item );
					continue;
				}

				if ( $result && null !== $result->unit_price ) {
					$product->set_price( $result->unit_price );

					$applied = array(
						'discount_id'      => (int) $result->discount->id,
						'discount_name'    => $result->discount->name,
						'original_price'   => $result->base_price,
						'discounted_price' => $result->unit_price,
						'savings'          => ( $result->base_price - $result->unit_price ) * (int) $cart_item['quantity'],
						'kind'             => $result->kind,
					);

					$cart_item['storedash_discount_applied'] = $applied;
					if ( 'quantity' === $result->kind ) {
						// Legacy key consumed by Quantity_Discount_Rule's
						// cart-item price display filter.
						$cart_item['storedash_quantity_discount'] = $applied;
					} else {
						unset( $cart_item['storedash_quantity_discount'] );
					}
					$cart->cart_contents[ $cart_item_key ] = $cart_item;
					continue;
				}

				// No winner this round: restore only lines WE discounted earlier.
				$this->restore_line( $cart, $cart_item_key, $cart_item );
			}

			// Remove free items whose parent line no longer wins a BOGO rule
			// (rule disabled/expired mid-session, coupon gate, quantity drop).
			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( ! isset( $cart_item['storedash_is_free_item'] ) ) {
					continue;
				}
				$parent = isset( $cart_item['storedash_bogo_parent'] ) ? $cart_item['storedash_bogo_parent'] : '';
				if ( ! isset( $bogo_winners[ $parent ] ) ) {
					$cart->remove_cart_item( $cart_item_key );
				}
			}
		} finally {
			$this->is_processing = false;
		}
	}

	/**
	 * Restore a line's pristine price — only when it carries our marker.
	 *
	 * @param \WC_Cart $cart          Cart object.
	 * @param string   $cart_item_key Cart item key.
	 * @param array    $cart_item     Cart item data.
	 */
	protected function restore_line( $cart, $cart_item_key, $cart_item ) {
		if ( ! isset( $cart_item['storedash_discount_applied'] ) ) {
			return;
		}

		$product = $cart_item['data'];
		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();
		if ( $regular > 0 ) {
			$product->set_price( ( $sale > 0 ) ? $sale : $regular );
		}

		unset( $cart_item['storedash_discount_applied'], $cart_item['storedash_quantity_discount'] );
		$cart->cart_contents[ $cart_item_key ] = $cart_item;
	}
}
