<?php
/**
 * Discount Resolver — the discount engine's single decision-maker.
 *
 * Applies the resolution rulebook (spec: woo-dash
 * docs/superpowers/specs/2026-07-03-discount-engine-production-readiness-design.md):
 *
 *  1. Eligibility — active window (DB query), structural targeting
 *     (Discount_Matcher), disable_on_sale, and in cart context: conditions +
 *     disable_with_coupons.
 *  2. Suppression ceiling — Discount_Priority_Resolver (ties survive).
 *  3. Winner — lowest priority number → biggest savings → lowest id.
 *  4. ONE winner per product / cart line; all rule types compete in cart.
 *  5. Price basis per apply_to_sale_price; final <= no-discount price, >= 0;
 *     a "discount" that doesn't lower the price does not win.
 *  6. Rounding via wc_get_price_decimals() so display == cart to the cent.
 *  7. "Cheapest in cart" BOGO (rule_config.mode = 'cheapest', 1.25.0): every
 *     qualifying paid unit in the cart counts together; the cheapest
 *     floor(units / (buy + get)) × get units get the % off, and each line's
 *     share is applied as an averaged unit price so line totals stay exact.
 *
 * @package StoreDash\Discounts\Engine
 * @since   3.2.0
 */

namespace StoreDash\Discounts\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Discounts\Sync\Discount_DB_Handler;
use WC_Product;

/**
 * Resolves the single winning discount (and its price) for a product.
 *
 * @since 3.2.0
 */
class Discount_Resolver {

	/**
	 * Rule types eligible on catalog/display surfaces.
	 *
	 * @var string[]
	 */
	const DISPLAY_TYPES = array( 'store_wide', 'product', 'category', 'tag', 'brand' );

	/**
	 * DB handler instance.
	 *
	 * @var Discount_DB_Handler
	 */
	protected $db_handler;

	/**
	 * Structural matcher (targets/taxonomies/exclusions).
	 *
	 * @var Discount_Matcher
	 */
	protected $matcher;

	/**
	 * Cross-engine suppression-ceiling resolver.
	 *
	 * @var Discount_Priority_Resolver
	 */
	protected $priority_resolver;

	/**
	 * Per-request result cache, keyed "context:product_id:qty[:paid_qty:line]".
	 *
	 * @var array<string,object|null>
	 */
	protected $cache = array();

	/**
	 * Per-request "cheapest in cart" BOGO allocations, keyed by discount id:
	 * { pool_units, free_units, lines: { cart_item_key => allocation } }.
	 *
	 * @since 1.25.0
	 * @var array<int,object>
	 */
	protected $cheapest_allocations = array();

	/**
	 * Constructor with test-friendly injection (mirrors Discount_Priority_Resolver).
	 *
	 * @param Discount_Matcher|null           $matcher           Optional matcher.
	 * @param Discount_DB_Handler|null        $db_handler        Optional DB handler.
	 * @param Discount_Priority_Resolver|null $priority_resolver Optional resolver.
	 */
	public function __construct( $matcher = null, $db_handler = null, $priority_resolver = null ) {
		$this->db_handler        = ( null !== $db_handler ) ? $db_handler : new Discount_DB_Handler();
		$this->matcher           = ( null !== $matcher ) ? $matcher : new Discount_Matcher();
		$this->priority_resolver = ( null !== $priority_resolver )
			? $priority_resolver
			: new Discount_Priority_Resolver( $this->matcher, $this->db_handler );
	}

	/**
	 * Resolve the ONE winning discount for a product.
	 *
	 * @param WC_Product  $product       Product (or variation) object.
	 * @param int         $quantity      Cart-line quantity (1 on display surfaces).
	 * @param string      $context       'display' (catalog) or 'cart'.
	 * @param string|null $cart_item_key Cart line key (cart context). "Cheapest
	 *                                   in cart" BOGO allocates per line, so
	 *                                   callers that have the key pass it.
	 * @return object|null Winner: {discount, kind, unit_price, base_price, savings, bogo} or null.
	 */
	public function resolve( $product, $quantity = 1, $context = 'display', $cart_item_key = null ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$quantity  = max( 1, (int) $quantity );
		$cache_key = $context . ':' . $product->get_id() . ':' . $quantity;
		if ( 'cart' === $context && function_exists( 'WC' ) && WC()->cart ) {
			// Combined-count quantity rules depend on the rest of the cart, not
			// just this line — don't replay a winner across a cart change.
			$cache_key .= ':' . $this->cart_paid_quantity( WC()->cart );
			if ( null !== $cart_item_key ) {
				$cache_key .= ':' . $cart_item_key;
			}
		}
		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		$ceiling = $this->priority_resolver->get_priority_ceiling( $product );
		$best    = null;

		foreach ( $this->get_candidates( $context ) as $discount ) {
			if ( $this->priority_resolver->is_suppressed( $discount, $ceiling ) ) {
				continue;
			}
			if ( ! $this->is_eligible( $discount, $product, $context ) ) {
				continue;
			}
			$application = $this->compute_application( $discount, $product, $quantity, $context, $cart_item_key );
			if ( null === $application ) {
				continue;
			}
			if ( null === $best || $this->beats( $application, $best ) ) {
				$best = $application;
			}
		}

		$this->cache[ $cache_key ] = $best;
		return $best;
	}

	/**
	 * Resolve the discount that represents a product on a display surface.
	 *
	 * A variable product has no price of its own — its regular price is empty
	 * and the variations carry the money — so resolve() always comes back null
	 * for the parent. Display surfaces (badges, catalog price, Store API) must
	 * therefore ask a representative variation: the cheapest, which is the price
	 * a listing leads with. Everything else resolves against itself.
	 *
	 * @since 1.9.1
	 *
	 * @param WC_Product $product Product object.
	 * @return object|null Winning application, or null.
	 */
	public function resolve_for_display( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		if ( $product->is_type( 'variable' ) ) {
			return $this->resolve_variation( $product, 'min' );
		}

		return $this->resolve( $product, 1, 'display' );
	}

	/**
	 * Resolve the winning discount for a variable product's min- or max-priced
	 * variation.
	 *
	 * @since 1.9.1
	 *
	 * @param WC_Product $variable Variable product.
	 * @param string     $min_max  'min' or 'max'.
	 * @return object|null Winning application with a unit price, or null.
	 */
	public function resolve_variation( $variable, $min_max = 'min' ) {
		if ( ! $variable instanceof WC_Product || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$cache_key = 'variation:' . $variable->get_id() . ':' . $min_max;
		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		// Cache the miss up front: every early return below is a definitive
		// "no discount for this variable product", and the badge, price-HTML and
		// is-on-sale filters all ask again within the same loop iteration.
		$this->cache[ $cache_key ] = null;

		$prices = $variable->get_variation_prices();
		if ( empty( $prices['price'] ) ) {
			return null;
		}

		$current_price = ( 'min' === $min_max ) ? current( $prices['price'] ) : end( $prices['price'] );
		$variation_id  = array_search( $current_price, $prices['price'], false );
		if ( ! $variation_id ) {
			return null;
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return null;
		}

		$result = $this->resolve( $variation, 1, 'display' );
		if ( ! $result || null === $result->unit_price ) {
			return null;
		}

		$this->cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Signature of the currently active discounts.
	 *
	 * Consumers that cache a computed price across requests (WooCommerce's
	 * variation-price transients) must fold this into their cache key, or an
	 * edited or expired discount keeps serving its old prices indefinitely.
	 *
	 * @since 1.9.1
	 *
	 * @return string
	 */
	public function get_discount_signature() {
		return md5( (string) wp_json_encode( $this->db_handler->get_active_discounts() ) );
	}

	/**
	 * Clear the per-request cache (cart state changed, discounts mutated).
	 */
	public function clear_cache() {
		$this->cache                = array();
		$this->cheapest_allocations = array();
		$this->priority_resolver->clear_cache();
		if ( method_exists( $this->matcher, 'clear_cache' ) ) {
			$this->matcher->clear_cache();
		}
	}

	/**
	 * Expose the matcher (Dynamic_Price_Display reuses it for early bailout).
	 *
	 * @return Discount_Matcher
	 */
	public function get_matcher() {
		return $this->matcher;
	}

	/**
	 * Candidate discounts for a context.
	 *
	 * Display surfaces exclude cart-only rule types AND any rule with cart
	 * conditions — a struck-through catalog price must always be honoured in
	 * the cart (charging less later is fine; more is not).
	 *
	 * @param string $context 'display' or 'cart'.
	 * @return array
	 */
	protected function get_candidates( $context ) {
		$all = $this->db_handler->get_active_discounts();

		if ( 'cart' === $context ) {
			return $all;
		}

		$display = array();
		foreach ( $all as $discount ) {
			if ( ! in_array( $discount->rule_type, self::DISPLAY_TYPES, true ) ) {
				continue;
			}
			if ( $this->has_cart_conditions( $discount ) ) {
				continue;
			}
			$display[] = $discount;
		}
		return $display;
	}

	/**
	 * Whether a discount carries any cart-context condition.
	 *
	 * @param object $discount Discount row.
	 * @return bool
	 */
	protected function has_cart_conditions( $discount ) {
		$conditions = json_decode( $discount->conditions ?? '{}', true );
		if ( ! is_array( $conditions ) ) {
			return false;
		}
		foreach ( array( 'min_cart_total', 'max_cart_total', 'min_quantity', 'max_quantity' ) as $key ) {
			if ( ! empty( $conditions[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Full eligibility gate for one discount against one product.
	 *
	 * @param object     $discount Discount row.
	 * @param WC_Product $product  Product object.
	 * @param string     $context  'display' or 'cart'.
	 * @return bool
	 */
	protected function is_eligible( $discount, $product, $context ) {
		if ( ! $this->matcher->discount_applies_to_product( $discount, $product ) ) {
			return false;
		}

		if ( ! empty( $discount->disable_on_sale ) && $this->is_on_merchant_sale( $product ) ) {
			return false;
		}

		if ( 'cart' === $context && function_exists( 'WC' ) && WC()->cart ) {
			if ( ! $this->cart_conditions_met( $discount ) ) {
				return false;
			}
			if ( ! empty( $discount->disable_with_coupons ) && count( WC()->cart->get_applied_coupons() ) > 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Merchant-sale check: a sale price is present on the product itself.
	 *
	 * (The old tracking-table exception was dead code — the table never had
	 * writers — so presence of a sale price IS a merchant sale.)
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	protected function is_on_merchant_sale( $product ) {
		$sale = $product->get_sale_price();
		return '' !== (string) $sale && (float) $sale > 0;
	}

	/**
	 * Cart conditions gate (min/max cart total, min/max total cart quantity).
	 * Mirrors the former Bogo/Quantity check_conditions() semantics.
	 *
	 * @param object $discount Discount row.
	 * @return bool
	 */
	protected function cart_conditions_met( $discount ) {
		$raw        = $discount->conditions ?? '{}';
		$conditions = json_decode( $raw, true );
		if ( ! is_array( $conditions ) ) {
			// Genuinely empty (''/null/whitespace) → no conditions → applies.
			if ( '' === trim( (string) $raw ) ) {
				return true;
			}
			// Non-empty but not a JSON object → corrupt/unexpected. Fail closed
			// so a broken rule can't silently apply to every cart.
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
		// woocommerce_before_calculate_totals, so get_subtotal() is always 0
		// inside the hook — derive the pre-discount subtotal from cart contents.
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
	 * Each paid line counts at the shopper-facing base price (sale price when
	 * set, else regular price); BOGO free-item lines are outputs of discount
	 * application and are excluded.
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
	 * Same exclusion as cart_base_subtotal: BOGO free-item lines
	 * (storedash_is_free_item) are outputs of discount application and must
	 * not count toward min/max quantity gates.
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
	 * Compute what applying this discount would do (kind, price, savings).
	 * Returns null when the rule yields no real price reduction.
	 *
	 * @param object      $discount      Discount row.
	 * @param WC_Product  $product       Product object.
	 * @param int         $quantity      Line quantity.
	 * @param string      $context       'display' or 'cart'.
	 * @param string|null $cart_item_key Cart line key when known (cart context).
	 * @return object|null
	 */
	protected function compute_application( $discount, $product, $quantity, $context = 'display', $cart_item_key = null ) {
		$regular = (float) $product->get_regular_price();
		if ( $regular <= 0 ) {
			return null;
		}
		$sale    = $this->is_on_merchant_sale( $product ) ? (float) $product->get_sale_price() : 0.0;
		$current = ( $sale > 0 ) ? $sale : $regular; // Price without our discount.

		switch ( $discount->rule_type ) {
			case 'bogo':
				if ( 'cheapest' === $this->bogo_mode( $discount ) ) {
					return $this->compute_bogo_cheapest( $discount, $product, $quantity, $current, $context, $cart_item_key );
				}
				return $this->compute_bogo( $discount, $quantity, $current );

			case 'quantity':
				return $this->compute_quantity( $discount, $quantity, $regular, $current, $context );

			default:
				return $this->compute_price_rule( $discount, $quantity, $regular, $current );
		}
	}

	/**
	 * Simple price rule (store_wide/product/category/tag/brand).
	 *
	 * @param object $discount Discount row.
	 * @param int    $quantity Line quantity.
	 * @param float  $regular  Regular price.
	 * @param float  $current  No-discount price (sale ?: regular).
	 * @return object|null
	 */
	protected function compute_price_rule( $discount, $quantity, $regular, $current ) {
		$basis = ! empty( $discount->apply_to_sale_price ) ? $current : $regular;
		$unit  = $this->apply_amount( $basis, $discount->discount_type, (float) $discount->amount );
		if ( null === $unit ) {
			return null;
		}

		// Invariants: never above the no-discount price, never below zero.
		$unit = $this->round_price( min( $unit, $current ) );
		if ( $unit >= $current ) {
			return null;
		}

		return (object) array(
			'discount'   => $discount,
			'kind'       => 'price',
			'unit_price' => $unit,
			'base_price' => $current,
			'savings'    => ( $current - $unit ) * $quantity,
			'bogo'       => null,
		);
	}

	/**
	 * Quantity (tiered) rule.
	 *
	 * @param object $discount Discount row.
	 * @param int    $quantity Line quantity.
	 * @param float  $regular  Regular price.
	 * @param float  $current  No-discount price.
	 * @param string $context  'display' or 'cart'.
	 * @return object|null
	 */
	protected function compute_quantity( $discount, $quantity, $regular, $current, $context = 'display' ) {
		$config = json_decode( $discount->rule_config ?? '{}', true );
		$tiers  = ( isset( $config['tiers'] ) && is_array( $config['tiers'] ) ) ? $config['tiers'] : array();
		if ( empty( $tiers ) ) {
			return null;
		}

		// Mix and match: the tier is picked by the quantity of EVERY qualifying
		// cart line together, not this line alone. Savings stay per line.
		$tier_quantity = $quantity;
		if ( 'cart' === $context && isset( $config['count_mode'] ) && 'combined' === $config['count_mode'] ) {
			$tier_quantity = max( $quantity, $this->combined_quantity( $discount ) );
		}

		$tier = $this->find_matching_tier( $tier_quantity, $tiers );
		if ( ! $tier || empty( $tier['discount'] ) ) {
			return null;
		}

		$type  = isset( $tier['discount_type'] ) ? $tier['discount_type'] : $discount->discount_type;
		$basis = ! empty( $discount->apply_to_sale_price ) ? $current : $regular;
		$unit  = $this->apply_amount( $basis, $type, (float) $tier['discount'] );
		if ( null === $unit ) {
			return null;
		}

		$unit = $this->round_price( min( $unit, $current ) );
		if ( $unit >= $current ) {
			return null;
		}

		return (object) array(
			'discount'   => $discount,
			'kind'       => 'quantity',
			'unit_price' => $unit,
			'base_price' => $current,
			'savings'    => ( $current - $unit ) * $quantity,
			'bogo'       => null,
			'tier'       => array(
				'min_quantity'  => isset( $tier['min_quantity'] ) ? (int) $tier['min_quantity'] : 0,
				'max_quantity'  => ( isset( $tier['max_quantity'] ) && '' !== $tier['max_quantity'] ) ? (int) $tier['max_quantity'] : null,
				'discount'      => (float) $tier['discount'],
				'discount_type' => $type,
			),
		);
	}

	/**
	 * Total quantity of the paid cart lines a discount is eligible for.
	 *
	 * Used by combined-count quantity rules. A line counts when the rule could
	 * apply to it (same gate as resolve(): targeting, exclusions,
	 * disable_on_sale, cart conditions, coupons). BOGO free-item lines are
	 * outputs of discount application and never count.
	 *
	 * @since 1.21.0
	 *
	 * @param object $discount Discount row.
	 * @return int
	 */
	public function combined_quantity( $discount ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		$quantity = 0;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['storedash_is_free_item'] ) ) {
				continue;
			}

			$line_product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $line_product instanceof WC_Product ) {
				continue;
			}
			if ( ! $this->is_eligible( $discount, $line_product, 'cart' ) ) {
				continue;
			}

			$quantity += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
		}

		return $quantity;
	}

	/**
	 * BOGO rule: savings = free items × current price × discount percent.
	 *
	 * @param object $discount Discount row.
	 * @param int    $quantity Line quantity.
	 * @param float  $current  No-discount price.
	 * @return object|null
	 */
	protected function compute_bogo( $discount, $quantity, $current ) {
		$config = json_decode( $discount->rule_config ?? '{}', true );
		$buy    = isset( $config['buy_quantity'] ) ? max( 1, (int) $config['buy_quantity'] ) : 1;
		$get    = isset( $config['get_quantity'] ) ? max( 1, (int) $config['get_quantity'] ) : 1;
		$pct    = isset( $config['discount'] ) ? min( 100.0, max( 0.0, (float) $config['discount'] ) ) : 100.0;

		$free = (int) floor( $quantity / $buy ) * $get;
		if ( $free <= 0 || $pct <= 0 ) {
			return null;
		}

		return (object) array(
			'discount'   => $discount,
			'kind'       => 'bogo',
			'unit_price' => null,
			'base_price' => $current,
			'savings'    => $free * $current * ( $pct / 100 ),
			'bogo'       => array(
				'buy_quantity'     => $buy,
				'get_quantity'     => $get,
				'discount_percent' => $pct,
				'free_quantity'    => $free,
			),
		);
	}

	/**
	 * BOGO mechanic a rule uses.
	 *
	 * 'same_product' (default, rows synced before 1.25.0 carry no mode): buy X
	 * of a product and X extra units of the SAME product are added free.
	 * 'cheapest': every qualifying unit in the cart counts together and the
	 * cheapest ones are discounted — "3 for 2" across products.
	 *
	 * @since 1.25.0
	 *
	 * @param object $discount Discount row.
	 * @return string 'same_product' | 'cheapest'
	 */
	public function bogo_mode( $discount ) {
		$config = json_decode( $discount->rule_config ?? '{}', true );
		return ( is_array( $config ) && isset( $config['mode'] ) && 'cheapest' === $config['mode'] )
			? 'cheapest'
			: 'same_product';
	}

	/**
	 * "Cheapest in cart" BOGO: this line's share of the cart-wide allocation.
	 *
	 * The allocation (allocate_cheapest) decides which units are free; here the
	 * line's saving is spread over its quantity as an averaged unit price. The
	 * unit price is deliberately NOT rounded: WooCommerce multiplies it by the
	 * quantity before applying price precision (WC_Cart_Totals), so the LINE
	 * total lands exactly on qty × price − savings (3 × 1000 kr with one free
	 * = 2000 kr, never 2001). Lines without a free unit return null so another
	 * rule may win them.
	 *
	 * @since 1.25.0
	 *
	 * @param object      $discount      Discount row.
	 * @param WC_Product  $product       Product object.
	 * @param int         $quantity      Line quantity.
	 * @param float       $current       No-discount price.
	 * @param string      $context       'display' or 'cart'.
	 * @param string|null $cart_item_key Cart line key when known.
	 * @return object|null
	 */
	protected function compute_bogo_cheapest( $discount, $product, $quantity, $current, $context, $cart_item_key = null ) {
		if ( 'cart' !== $context || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}

		$allocation = $this->allocate_cheapest( $discount );
		if ( null === $cart_item_key ) {
			$cart_item_key = $this->line_key_for_product( $product );
		}
		if ( null === $cart_item_key || empty( $allocation->lines[ $cart_item_key ] ) ) {
			return null;
		}

		$line = $allocation->lines[ $cart_item_key ];
		if ( $line['free_units'] <= 0 || $line['savings'] <= 0 ) {
			return null;
		}

		$quantity   = max( 1, (int) $quantity );
		$unit_price = ( ( $current * $quantity ) - $line['savings'] ) / $quantity;
		$unit_price = max( 0.0, min( $unit_price, $current ) );
		if ( $unit_price >= $current ) {
			return null;
		}

		return (object) array(
			'discount'   => $discount,
			'kind'       => 'bogo_cheapest',
			'unit_price' => $unit_price,
			'base_price' => $current,
			'savings'    => $line['savings'],
			'bogo'       => array(
				'buy_quantity'     => $allocation->buy_quantity,
				'get_quantity'     => $allocation->get_quantity,
				'discount_percent' => $allocation->discount_percent,
				'free_quantity'    => $line['free_units'],
				'pool_units'       => $allocation->pool_units,
				'free_units_total' => $allocation->free_units,
			),
		);
	}

	/**
	 * Allocate a "cheapest in cart" BOGO rule across the current cart.
	 *
	 * Pool = every paid unit on a line the rule is eligible for (targets,
	 * exclusions, disable_on_sale, cart conditions, coupon gate, priority
	 * ceiling), minus lines a higher-priority rule already claims. Free units =
	 * floor(pool / (buy + get)) × get, handed out cheapest-first. Per unit the
	 * price basis follows apply_to_sale_price and the customer never pays more
	 * than the current price. Computed once per request (clear_cache resets).
	 *
	 * @since 1.25.0
	 *
	 * @param object $discount Discount row.
	 * @return object {buy_quantity, get_quantity, discount_percent, pool_units, free_units, lines}
	 */
	public function allocate_cheapest( $discount ) {
		$id = (int) $discount->id;
		if ( isset( $this->cheapest_allocations[ $id ] ) ) {
			return $this->cheapest_allocations[ $id ];
		}

		$config = json_decode( $discount->rule_config ?? '{}', true );
		$config = is_array( $config ) ? $config : array();
		$buy    = isset( $config['buy_quantity'] ) ? max( 1, (int) $config['buy_quantity'] ) : 1;
		$get    = isset( $config['get_quantity'] ) ? max( 1, (int) $config['get_quantity'] ) : 1;
		$pct    = isset( $config['discount'] ) ? min( 100.0, max( 0.0, (float) $config['discount'] ) ) : 100.0;

		$allocation                        = (object) array(
			'buy_quantity'     => $buy,
			'get_quantity'     => $get,
			'discount_percent' => $pct,
			'pool_units'       => 0,
			'free_units'       => 0,
			'lines'            => array(),
		);
		$this->cheapest_allocations[ $id ] = $allocation;

		if ( $pct <= 0 || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $allocation;
		}

		$pool = array();
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['storedash_is_free_item'] ) ) {
				continue;
			}
			$line_product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $line_product instanceof WC_Product ) {
				continue;
			}
			$line_quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
			if ( $line_quantity <= 0 ) {
				continue;
			}
			if ( ! $this->is_eligible( $discount, $line_product, 'cart' ) ) {
				continue;
			}
			$ceiling = $this->priority_resolver->get_priority_ceiling( $line_product );
			if ( $this->priority_resolver->is_suppressed( $discount, $ceiling ) ) {
				continue;
			}

			$regular = (float) $line_product->get_regular_price();
			if ( $regular <= 0 ) {
				continue;
			}
			$sale    = $this->is_on_merchant_sale( $line_product ) ? (float) $line_product->get_sale_price() : 0.0;
			$current = ( $sale > 0 ) ? $sale : $regular;

			if ( $this->line_claimed_by_higher_priority( $discount, $line_product, $line_quantity, $ceiling ) ) {
				continue;
			}

			$basis = ! empty( $discount->apply_to_sale_price ) ? $current : $regular;
			$unit  = $this->round_price( min( $basis * ( 1 - $pct / 100 ), $current ) );
			$unit  = max( 0.0, $unit );
			if ( $unit >= $current ) {
				continue; // Would not lower the price (e.g. sale already deeper).
			}

			$pool[] = array(
				'key'         => $cart_item_key,
				'quantity'    => $line_quantity,
				'current'     => $current,
				'unit_saving' => $current - $unit,
			);
		}

		$pool_units = 0;
		foreach ( $pool as $entry ) {
			$pool_units += $entry['quantity'];
		}
		$allocation->pool_units = $pool_units;
		$allocation->free_units = (int) floor( $pool_units / ( $buy + $get ) ) * $get;
		if ( $allocation->free_units <= 0 ) {
			return $allocation;
		}

		// Cheapest unit first; equal prices by line order so the result is stable
		// across the multiple firings of before_calculate_totals.
		usort(
			$pool,
			function ( $a, $b ) {
				if ( abs( $a['current'] - $b['current'] ) > 0.000001 ) {
					return ( $a['current'] < $b['current'] ) ? -1 : 1;
				}
				return strcmp( (string) $a['key'], (string) $b['key'] );
			}
		);

		$remaining = $allocation->free_units;
		foreach ( $pool as $entry ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$units      = min( $remaining, $entry['quantity'] );
			$remaining -= $units;

			$allocation->lines[ $entry['key'] ] = array(
				'free_units'  => $units,
				'unit_saving' => $entry['unit_saving'],
				'savings'     => $units * $entry['unit_saving'],
				'current'     => $entry['current'],
				'quantity'    => $entry['quantity'],
			);
		}

		return $allocation;
	}

	/**
	 * Whether a rule with a LOWER priority number (= higher priority) than the
	 * cheapest-BOGO rule would win this line on its own. Such lines never enter
	 * the BOGO pool: per-line resolution would hand them to that rule anyway,
	 * and counting them would silently "spend" free units on lines the offer
	 * can't touch.
	 *
	 * Only non-BOGO rules are considered (BOGO rules never claim a line
	 * outright; their own pools are resolved independently).
	 *
	 * @since 1.25.0
	 *
	 * @param object     $discount Cheapest-BOGO rule.
	 * @param WC_Product $product  Line product.
	 * @param int        $quantity Line quantity.
	 * @param int|null   $ceiling  Priority ceiling for the product.
	 * @return bool
	 */
	protected function line_claimed_by_higher_priority( $discount, $product, $quantity, $ceiling ) {
		$own_priority = ( isset( $discount->priority ) && null !== $discount->priority ) ? (int) $discount->priority : 10;

		foreach ( $this->get_candidates( 'cart' ) as $other ) {
			if ( (int) $other->id === (int) $discount->id || 'bogo' === $other->rule_type ) {
				continue;
			}
			$other_priority = ( isset( $other->priority ) && null !== $other->priority ) ? (int) $other->priority : 10;
			if ( $other_priority >= $own_priority ) {
				continue;
			}
			if ( $this->priority_resolver->is_suppressed( $other, $ceiling ) ) {
				continue;
			}
			if ( ! $this->is_eligible( $other, $product, 'cart' ) ) {
				continue;
			}
			if ( null !== $this->compute_application( $other, $product, $quantity, 'cart' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * First paid cart line carrying this product (callers without a line key).
	 *
	 * @since 1.25.0
	 *
	 * @param WC_Product $product Product object.
	 * @return string|null
	 */
	protected function line_key_for_product( $product ) {
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['storedash_is_free_item'] ) || ! isset( $cart_item['data'] ) ) {
				continue;
			}
			if ( $cart_item['data'] === $product ) {
				return (string) $cart_item_key;
			}
		}
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['storedash_is_free_item'] ) || ! isset( $cart_item['data'] ) ) {
				continue;
			}
			if ( $cart_item['data'] instanceof WC_Product && $cart_item['data']->get_id() === $product->get_id() ) {
				return (string) $cart_item_key;
			}
		}
		return null;
	}

	/**
	 * Apply a discount amount to a basis price.
	 * Returns null when the result would not be a discount (fixed_price >= basis).
	 *
	 * @param float  $basis  Basis price.
	 * @param string $type   percentage|fixed_amount|fixed_price.
	 * @param float  $amount Amount.
	 * @return float|null
	 */
	protected function apply_amount( $basis, $type, $amount ) {
		switch ( $type ) {
			case 'percentage':
				$amount = min( 100.0, max( 0.0, $amount ) ); // Defensive clamp.
				return $basis * ( 1 - $amount / 100 );

			case 'fixed_amount':
				return max( 0.0, $basis - $amount );

			case 'fixed_price':
				return ( $amount < $basis ) ? max( 0.0, $amount ) : null;

			default:
				return null;
		}
	}

	/**
	 * Tie-break comparator: priority asc → savings desc → id asc.
	 *
	 * @param object $a Candidate application.
	 * @param object $b Current best application.
	 * @return bool True when $a beats $b.
	 */
	protected function beats( $a, $b ) {
		$pa = ( isset( $a->discount->priority ) && null !== $a->discount->priority ) ? (int) $a->discount->priority : 10;
		$pb = ( isset( $b->discount->priority ) && null !== $b->discount->priority ) ? (int) $b->discount->priority : 10;
		if ( $pa !== $pb ) {
			return $pa < $pb;
		}
		if ( abs( $a->savings - $b->savings ) > 0.001 ) {
			return $a->savings > $b->savings;
		}
		return (int) $a->discount->id < (int) $b->discount->id;
	}

	/**
	 * First tier whose [min_quantity, max_quantity] contains $quantity.
	 * (Same semantics as the former Quantity_Discount_Rule::find_matching_tier.)
	 *
	 * @param int   $quantity Line quantity.
	 * @param array $tiers    Tier config.
	 * @return array|null
	 */
	protected function find_matching_tier( $quantity, $tiers ) {
		foreach ( $tiers as $tier ) {
			$min = isset( $tier['min_quantity'] ) ? (int) $tier['min_quantity'] : 0;
			$max = ( ! isset( $tier['max_quantity'] ) || null === $tier['max_quantity'] || '' === $tier['max_quantity'] )
				? PHP_INT_MAX
				: (int) $tier['max_quantity'];

			if ( $quantity >= $min && $quantity <= $max ) {
				return $tier;
			}
		}
		return null;
	}

	/**
	 * Round to the store's configured price decimals so display and cart agree.
	 *
	 * @param float $price Price.
	 * @return float
	 */
	protected function round_price( $price ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return round( (float) $price, $decimals );
	}
}
