<?php
/**
 * Discount Priority Resolver
 *
 * Computes the cross-engine "priority ceiling" used to honour the
 * `disable_lower_priority` discount option. When a discount that applies to a
 * product has `disable_lower_priority = true`, it suppresses every lower
 * priority discount (greater priority NUMBER) for that product — across BOTH
 * the display engine and the cart engines, not just within one engine.
 *
 * @package StoreDash\Discounts\Engine
 * @since   3.1.0
 */

namespace StoreDash\Discounts\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Discounts\Sync\Discount_DB_Handler;
use WC_Product;

/**
 * Resolves the effective priority ceiling for a product from the full active
 * discount set, so display and cart engines honour the same suppression rule.
 *
 * Lower priority NUMBER = higher priority (applied first). A discount with
 * `disable_lower_priority` raises the ceiling to its own priority number; any
 * discount whose priority number is strictly greater is suppressed.
 *
 * @since 3.1.0
 */
class Discount_Priority_Resolver {

	/**
	 * DB handler instance.
	 *
	 * @since 3.1.0
	 * @var Discount_DB_Handler
	 */
	protected $db_handler;

	/**
	 * Matcher used to test whether a discount applies to a product.
	 *
	 * @since 3.1.0
	 * @var Discount_Matcher
	 */
	protected $matcher;

	/**
	 * Per-request cache of computed ceilings, keyed by product ID.
	 *
	 * @since 3.1.0
	 * @var array<int,int>
	 */
	protected $ceiling_cache = array();

	/**
	 * Constructor.
	 *
	 * @since 3.1.0
	 *
	 * @param Discount_Matcher|null    $matcher    Optional matcher to reuse. The
	 *                                             display engine passes its own
	 *                                             Discount_Matcher to avoid
	 *                                             building a second one; cart
	 *                                             engines let the resolver build
	 *                                             its own.
	 * @param Discount_DB_Handler|null $db_handler Optional DB handler (injected
	 *                                             in tests; defaults to a real
	 *                                             one in production).
	 */
	public function __construct( $matcher = null, $db_handler = null ) {
		$this->db_handler = ( null !== $db_handler ) ? $db_handler : new Discount_DB_Handler();
		$this->matcher    = ( null !== $matcher ) ? $matcher : new Discount_Matcher();
	}

	/**
	 * Get the priority ceiling for a product.
	 *
	 * Iterates every active discount (any rule type, display or cart). For each
	 * one that has `disable_lower_priority` set AND applies to the product
	 * (target/condition match), the ceiling is lowered to the smallest such
	 * priority number. Returns PHP_INT_MAX when no flagged discount applies,
	 * which means "no suppression" — behaviour is then identical to a build
	 * without this option.
	 *
	 * @since 3.1.0
	 *
	 * @param WC_Product $product Product object.
	 * @return int Ceiling priority number (PHP_INT_MAX = no ceiling).
	 */
	public function get_priority_ceiling( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return PHP_INT_MAX;
		}

		$product_id = $product->get_id();

		if ( array_key_exists( $product_id, $this->ceiling_cache ) ) {
			return $this->ceiling_cache[ $product_id ];
		}

		$ceiling   = PHP_INT_MAX;
		$discounts = $this->db_handler->get_active_discounts();

		foreach ( $discounts as $discount ) {
			// Only flagged discounts can raise a ceiling. Stored as TINYINT, so
			// '0'/0/false are all empty() and leave behaviour unchanged.
			if ( empty( $discount->disable_lower_priority ) ) {
				continue;
			}

			if ( ! $this->matcher->discount_applies_to_product( $discount, $product ) ) {
				continue;
			}

			// Parity with the apply-time gate Discount_Resolver::is_eligible: a
			// flagged discount that would be dropped at cart-calc time (cart-total /
			// cart-quantity condition unmet, or suppressed by an applied coupon)
			// must not raise a ceiling and over-suppress a lower-priority discount —
			// otherwise the flagged discount is dropped by is_eligible while its
			// ceiling still kills the lower-priority winner, so the customer pays
			// full price. The shared Discount_Matcher deliberately omits these cart
			// conditions (it runs at display time with no cart), so the check lives
			// here, cart-presence-guarded — see eligible_at_cart_calc().
			if ( ! $this->eligible_at_cart_calc( $discount ) ) {
				continue;
			}

			$priority = isset( $discount->priority ) ? (int) $discount->priority : 10;
			if ( $priority < $ceiling ) {
				$ceiling = $priority;
			}
		}

		$this->ceiling_cache[ $product_id ] = $ceiling;
		return $ceiling;
	}

	/**
	 * Whether a flagged discount would actually be eligible at cart-calc time.
	 *
	 * Mirrors the cart branch of Discount_Resolver::is_eligible (min/max_cart_total
	 * and min/max_quantity via cart_conditions_met, plus disable_with_coupons), so
	 * the ceiling the resolver raises agrees with what the apply-time gate lets
	 * through. It is CART-PRESENCE-GUARDED: when there is no cart (product-display /
	 * REST / feed time) the conditions are not-evaluable and treated as MET —
	 * exactly like Discount_Resolver, which only enforces them for the 'cart'
	 * context with a live cart. This keeps the computed ceiling (and therefore
	 * display pricing) byte-for-byte unchanged whenever no cart exists; the gate can
	 * only ever REDUCE suppression, and only at cart-calc time.
	 *
	 * @since 3.1.0
	 *
	 * @param object $discount Discount object (may carry a `conditions` JSON blob).
	 * @return bool True if the discount is eligible or the conditions are not evaluable.
	 */
	protected function eligible_at_cart_calc( $discount ) {
		// No cart → not evaluable → treat as eligible (display-time invariance).
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return true;
		}

		if ( ! $this->cart_conditions_met( $discount ) ) {
			return false;
		}

		if ( ! empty( $discount->disable_with_coupons ) && count( WC()->cart->get_applied_coupons() ) > 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Cart conditions gate (min/max cart total, min/max total cart quantity).
	 *
	 * Mirrors Discount_Resolver::cart_conditions_met exactly so the ceiling agrees
	 * with the apply-time eligibility check. Assumes a live cart (callers guard).
	 *
	 * @since 3.1.0
	 *
	 * @param object $discount Discount object (may carry a `conditions` JSON blob).
	 * @return bool True if all present conditions are satisfied.
	 */
	protected function cart_conditions_met( $discount ) {
		$raw        = isset( $discount->conditions ) ? $discount->conditions : '{}';
		$conditions = json_decode( $raw ?? '{}', true );
		if ( ! is_array( $conditions ) ) {
			// Genuinely empty (''/null/whitespace) → no conditions → conditions met.
			if ( '' === trim( (string) ( $raw ?? '' ) ) ) {
				return true;
			}
			// Non-empty but not a JSON object → corrupt/unexpected. Fail closed so a
			// broken rule can't raise a suppression ceiling it shouldn't.
			\StoreDash_Helpers::log_message(
				'Discount conditions failed to parse; discount will not apply',
				'error',
				array(
					'discount_id' => isset( $discount->id ) ? (int) $discount->id : null,
					'json_error'  => json_last_error_msg(),
				)
			);
			return false;
		}

		$cart = WC()->cart;

		// WC_Cart::calculate_totals() resets totals BEFORE firing
		// woocommerce_before_calculate_totals, so get_subtotal() is unreliable
		// inside the hook — derive the pre-discount subtotal from cart contents,
		// mirroring Discount_Resolver::cart_base_subtotal.
		if ( ! empty( $conditions['min_cart_total'] ) || ! empty( $conditions['max_cart_total'] ) ) {
			$cart_subtotal = $this->cart_base_subtotal( $cart );

			if ( ! empty( $conditions['min_cart_total'] ) && $cart_subtotal < (float) $conditions['min_cart_total'] ) {
				return false;
			}
			if ( ! empty( $conditions['max_cart_total'] ) && $cart_subtotal > (float) $conditions['max_cart_total'] ) {
				return false;
			}
		}

		if ( ! empty( $conditions['min_quantity'] ) || ! empty( $conditions['max_quantity'] ) ) {
			// get_cart_contents_count() would include BOGO free-item lines;
			// quantity gates must count only what the shopper chose to buy.
			$paid_quantity = $this->cart_paid_quantity( $cart );

			if ( ! empty( $conditions['min_quantity'] ) && $paid_quantity < (int) $conditions['min_quantity'] ) {
				return false;
			}
			if ( ! empty( $conditions['max_quantity'] ) && $paid_quantity > (int) $conditions['max_quantity'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Pre-discount cart subtotal, derived from cart contents.
	 *
	 * Mirrors Discount_Resolver::cart_base_subtotal: each paid line counts at the
	 * shopper-facing base price (sale price when set, else regular price); BOGO
	 * free-item lines are outputs of discount application and are excluded.
	 *
	 * @since 3.1.0
	 *
	 * @param object $cart Cart object.
	 * @return float
	 */
	protected function cart_base_subtotal( $cart ) {
		$subtotal = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['storedash_is_free_item'] ) ) {
				continue;
			}

			$line_product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $line_product ) {
				continue;
			}

			$sale = (string) $line_product->get_sale_price();
			$base = ( '' !== $sale && (float) $sale > 0 ) ? (float) $sale : (float) $line_product->get_regular_price();
			if ( $base <= 0 ) {
				$base = (float) $line_product->get_price();
			}

			$subtotal += $base * (float) $cart_item['quantity'];
		}

		return $subtotal;
	}

	/**
	 * Total PAID cart quantity, derived from cart contents.
	 *
	 * Mirrors Discount_Resolver::cart_paid_quantity — same exclusion as
	 * cart_base_subtotal: BOGO free-item lines (storedash_is_free_item) are
	 * outputs of discount application and must not count toward min/max
	 * quantity gates.
	 *
	 * @since 3.1.0
	 *
	 * @param object $cart Cart object.
	 * @return int
	 */
	protected function cart_paid_quantity( $cart ) {
		$quantity = 0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['storedash_is_free_item'] ) ) {
				continue;
			}

			$quantity += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
		}

		return $quantity;
	}

	/**
	 * Whether a discount is suppressed by a ceiling.
	 *
	 * Only discounts with a priority number STRICTLY GREATER than the ceiling
	 * are suppressed; a tie (equal priority number) or a higher priority
	 * (smaller number) is never suppressed.
	 *
	 * @since 3.1.0
	 *
	 * @param object $discount Discount object.
	 * @param int    $ceiling  Ceiling from get_priority_ceiling().
	 * @return bool True if the discount must not apply.
	 */
	public function is_suppressed( $discount, $ceiling ) {
		if ( PHP_INT_MAX === $ceiling ) {
			return false;
		}

		$priority = isset( $discount->priority ) ? (int) $discount->priority : 10;
		return $priority > $ceiling;
	}

	/**
	 * Clear the per-request ceiling cache.
	 *
	 * @since 3.1.0
	 */
	public function clear_cache() {
		$this->ceiling_cache = array();
	}
}
