<?php
/**
 * Dynamic Price Display
 *
 * Handles dynamic discount price display on product pages without modifying sale_price.
 * Uses WooCommerce hooks to filter price HTML and trigger sale badges.
 *
 * @package StoreDash\Discounts\Engine
 * @since   2.0.0
 */

namespace StoreDash\Discounts\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Product;

/**
 * Dynamic price display handler.
 *
 * Follows YITH pattern with enhancements for sale badge support.
 *
 * @since 2.0.0
 */
class Dynamic_Price_Display {

	/**
	 * Discount resolver (single decision-maker).
	 *
	 * @since 3.2.0
	 * @var Discount_Resolver
	 */
	protected $resolver;

	/**
	 * Discount matcher instance.
	 *
	 * @var Discount_Matcher
	 */
	protected $matcher;

	/**
	 * Price HTML cache (product_id => html).
	 *
	 * @var array
	 */
	protected $html_cache = array();


	/**
	 * Flag to prevent infinite loops when removing/adding filters.
	 *
	 * @var bool
	 */
	protected $processing = false;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param Discount_Matcher|null $matcher Shared matcher, or null to build one.
	 */
	public function __construct( $matcher = null ) {
		$this->resolver = new Discount_Resolver( $matcher instanceof Discount_Matcher ? $matcher : null );
		$this->matcher  = $this->resolver->get_matcher();
	}

	/**
	 * Register all WooCommerce hooks for price display.
	 *
	 * @since 2.0.0
	 */
	public function register_hooks() {
		// Price HTML display (shop pages, product pages, widgets)
		add_filter( 'woocommerce_get_price_html', array( $this, 'get_product_price_html' ), 10, 2 );
		add_filter( 'woocommerce_variable_price_html', array( $this, 'get_variable_price_html' ), 10, 2 );

		// Sale detection (triggers sale badge)
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'product_is_on_sale' ), 100, 2 );

		// Customize sale badge to show discount percentage
		add_filter( 'woocommerce_sale_flash', array( $this, 'get_sale_flash' ), 10, 3 );

		// Variation-specific prices for variable products
		add_filter( 'woocommerce_available_variation', array( $this, 'modify_variation_data' ), 10, 3 );
		add_filter( 'woocommerce_show_variation_price', array( $this, 'show_variation_price' ), 99, 3 );

		// Enqueue badge styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Check if we should process price for this context.
	 *
	 * Skip cart/checkout (handled by cart hooks).
	 *
	 * @since 2.0.0
	 *
	 * @param WC_Product $product Product object.
	 * @return bool True if should process.
	 */
	protected function should_process( $product ) {
		// Skip REST API requests — price display is frontend-only
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// Skip if already processing (prevent infinite loops)
		if ( $this->processing ) {
			return false;
		}

		// Skip cart and checkout (handled by existing cart hooks)
		if ( is_cart() || is_checkout() ) {
			return false;
		}

		// Skip if no product or no price
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		// Skip if no active discounts
		if ( ! $this->matcher->has_active_discounts() ) {
			return false;
		}

		return true;
	}

	/**
	 * Filter product price HTML to show discounted price.
	 *
	 * @since 2.0.0
	 *
	 * @param string     $price_html Original price HTML.
	 * @param WC_Product $product    Product object.
	 * @return string Modified price HTML.
	 */
	public function get_product_price_html( $price_html, $product ) {
		if ( ! $this->should_process( $product ) ) {
			return $price_html;
		}

		// Skip variable products (handled by get_variable_price_html)
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			return $price_html;
		}

		$product_id = $product->get_id();

		// Check cache
		if ( isset( $this->html_cache[ $product_id ] ) ) {
			return $this->html_cache[ $product_id ];
		}

		// Prevent infinite loops
		$this->processing = true;

		try {
			$result = $this->resolver->resolve( $product, 1, 'display' );

			if ( ! $result || null === $result->unit_price ) {
				return $price_html;
			}

			// Generate sale-style HTML: base is the price the customer would
			// otherwise pay (sale price when on sale), per the resolution rulebook.
			$price_html = $this->format_sale_price_html( $result->base_price, $result->unit_price, $product );

			// Cache result
			$this->html_cache[ $product_id ] = $price_html;

			return $price_html;
		} finally {
			$this->processing = false;
		}
	}

	/**
	 * Filter variable product price HTML.
	 *
	 * @since 2.0.0
	 *
	 * @param string     $price_html Original price HTML.
	 * @param WC_Product $product    Variable product object.
	 * @return string Modified price HTML.
	 */
	public function get_variable_price_html( $price_html, $product ) {
		if ( ! $this->should_process( $product ) ) {
			return $price_html;
		}

		$product_id = $product->get_id();

		// Check cache
		if ( isset( $this->html_cache[ $product_id ] ) ) {
			return $this->html_cache[ $product_id ];
		}

		// Prevent infinite loops
		$this->processing = true;

		try {
			// Get variation prices
			$prices    = $product->get_variation_prices();
			$min_price = current( $prices['price'] );
			$max_price = end( $prices['price'] );

			// Calculate discounted min/max
			$min_discounted = $this->get_variation_discounted_price( $product, 'min' );
			$max_discounted = $this->get_variation_discounted_price( $product, 'max' );

			// Check if any discount was applied
			$has_discount = ( $min_discounted !== null && $min_discounted < $min_price ) ||
							( $max_discounted !== null && $max_discounted < $max_price );

			if ( ! $has_discount ) {
				return $price_html;
			}

			// Use original prices if no discount found for that variation
			$min_discounted = $min_discounted ?? $min_price;
			$max_discounted = $max_discounted ?? $max_price;

			// Format price range
			$price_html = $this->format_variable_price_html(
				$min_price,
				$max_price,
				$min_discounted,
				$max_discounted,
				$product
			);

			// Cache result
			$this->html_cache[ $product_id ] = $price_html;

			return $price_html;
		} finally {
			$this->processing = false;
		}
	}

	/**
	 * Get discounted price for variation (min or max).
	 *
	 * @since 2.0.0
	 *
	 * @param WC_Product $variable Variable product.
	 * @param string     $min_max  'min' or 'max'.
	 * @return float|null Discounted price or null if no discount.
	 */
	protected function get_variation_discounted_price( $variable, $min_max = 'min' ) {
		$result = $this->resolve_variation( $variable, $min_max );

		return $result ? $result->unit_price : null;
	}

	/**
	 * Resolve the winning discount for a variable product's min- or max-priced
	 * variation.
	 *
	 * @since 1.9.1
	 *
	 * @param WC_Product $variable Variable product.
	 * @param string     $min_max  'min' or 'max'.
	 * @return object|null Resolver result with a unit price, or null.
	 */
	protected function resolve_variation( $variable, $min_max = 'min' ) {
		return $this->resolver->resolve_variation( $variable, $min_max );
	}

	/**
	 * Resolve the discount that should drive a product's badge.
	 *
	 * Variable products follow their cheapest variation — see
	 * Discount_Resolver::resolve_for_display(), which the Store API price
	 * filters share so both surfaces agree on what a product costs.
	 *
	 * @since 1.9.1
	 *
	 * @param WC_Product $product Product object.
	 * @return object|null Resolver result, or null when nothing applies.
	 */
	protected function resolve_for_badge( $product ) {
		return $this->resolver->resolve_for_display( $product );
	}

	/**
	 * Filter to make WooCommerce think product is on sale.
	 *
	 * This triggers the sale badge display in themes.
	 *
	 * @since 2.0.0
	 *
	 * @param bool       $on_sale Original on_sale status.
	 * @param WC_Product $product Product object.
	 * @return bool True if on sale (including dynamic discounts).
	 */
	public function product_is_on_sale( $on_sale, $product ) {
		// If already on sale (merchant sale), keep it
		if ( $on_sale ) {
			return true;
		}

		// Skip REST API requests
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $on_sale;
		}

		// Skip cart/checkout
		if ( is_cart() || is_checkout() ) {
			return $on_sale;
		}

		// Skip if no active discounts
		if ( ! $this->matcher->has_active_discounts() ) {
			return $on_sale;
		}

		// Check if our discount applies
		return null !== $this->resolve_for_badge( $product );
	}

	/**
	 * Customize sale badge to show discount percentage.
	 *
	 * @since 2.0.0
	 *
	 * @param string     $html    Original badge HTML.
	 * @param WP_Post    $post    Post object.
	 * @param WC_Product $product Product object.
	 * @return string Modified badge HTML.
	 */
	public function get_sale_flash( $html, $post, $product ) {
		// Skip REST API requests
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $html;
		}

		// Skip cart/checkout
		if ( is_cart() || is_checkout() ) {
			return $html;
		}

		// No discounts configured: leave merchant sale badges entirely alone.
		if ( ! $this->matcher->has_active_discounts() ) {
			return $html;
		}

		$result = $this->resolve_for_badge( $product );

		if ( ! $result || null === $result->unit_price || $result->base_price <= 0 ) {
			return $html; // Use default badge for merchant sales.
		}

		$percentage = (int) round( ( ( $result->base_price - $result->unit_price ) / $result->base_price ) * 100 );

		if ( $percentage <= 0 ) {
			return $html;
		}

		return sprintf(
			'<span class="onsale storedash-discount-badge">-%d%%</span>',
			$percentage
		);
	}

	/**
	 * Add discount info to variation data for JavaScript.
	 *
	 * @since 2.0.0
	 *
	 * @param array      $variation_data Variation data.
	 * @param WC_Product $variable       Variable product.
	 * @param WC_Product $variation      Variation product.
	 * @return array Modified variation data.
	 */
	public function modify_variation_data( $variation_data, $variable, $variation ) {
		// Skip REST API requests
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $variation_data;
		}

		$result = $this->resolver->resolve( $variation, 1, 'display' );

		if ( $result && null !== $result->unit_price ) {
			$variation_data['storedash_discount'] = array(
				'original_price'   => $result->base_price,
				'discounted_price' => $result->unit_price,
				'discount_name'    => $result->discount->name,
				'discount_amount'  => (float) $result->discount->amount,
				'discount_type'    => $result->discount->discount_type,
			);

			$variation_data['price_html'] = $this->format_sale_price_html(
				$result->base_price,
				$result->unit_price,
				$variation
			);
		}

		return $variation_data;
	}

	/**
	 * Force show variation price when discount exists.
	 *
	 * @since 2.0.0
	 *
	 * @param bool       $show      Original show value.
	 * @param WC_Product $variable  Variable product.
	 * @param WC_Product $variation Variation product.
	 * @return bool True to show price.
	 */
	public function show_variation_price( $show, $variable, $variation ) {
		// Skip REST API requests
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $show;
		}

		if ( $show ) {
			return $show;
		}

		return null !== $this->resolver->resolve( $variation, 1, 'display' );
	}

	/**
	 * Format sale price HTML with strikethrough.
	 *
	 * @since 2.0.0
	 *
	 * @param float      $original_price Original price.
	 * @param float      $new_price      Discounted price.
	 * @param WC_Product $product        Product object.
	 * @return string Price HTML.
	 */
	protected function format_sale_price_html( $original_price, $new_price, $product ) {
		$original_display = wc_get_price_to_display( $product, array( 'price' => $original_price ) );
		$new_display      = wc_get_price_to_display( $product, array( 'price' => $new_price ) );

		return wc_format_sale_price(
			wc_price( $original_display ),
			wc_price( $new_display )
		) . $product->get_price_suffix();
	}

	/**
	 * Format variable product price range HTML.
	 *
	 * @since 2.0.0
	 *
	 * @param float      $min_price      Min original price.
	 * @param float      $max_price      Max original price.
	 * @param float      $min_discounted Min discounted price.
	 * @param float      $max_discounted Max discounted price.
	 * @param WC_Product $product        Product object.
	 * @return string Price HTML.
	 */
	protected function format_variable_price_html( $min_price, $max_price, $min_discounted, $max_discounted, $product ) {
		// Get display prices
		$min_display      = wc_get_price_to_display( $product, array( 'price' => $min_price ) );
		$max_display      = wc_get_price_to_display( $product, array( 'price' => $max_price ) );
		$min_disc_display = wc_get_price_to_display( $product, array( 'price' => $min_discounted ) );
		$max_disc_display = wc_get_price_to_display( $product, array( 'price' => $max_discounted ) );

		// Format original price range
		if ( $min_display !== $max_display ) {
			$original_html = wc_format_price_range( $min_display, $max_display );
		} else {
			$original_html = wc_price( $min_display );
		}

		// Format discounted price range
		if ( $min_disc_display !== $max_disc_display ) {
			$discounted_html = wc_format_price_range( $min_disc_display, $max_disc_display );
		} else {
			$discounted_html = wc_price( $min_disc_display );
		}

		return '<del>' . $original_html . '</del> <ins>' . $discounted_html . '</ins>' . $product->get_price_suffix();
	}

	/**
	 * Enqueue badge styles.
	 *
	 * @since 2.0.0
	 */
	public function enqueue_styles() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Only on frontend, not admin
		if ( is_admin() ) {
			return;
		}

		// Inline styles for badge (minimal footprint)
		$css = '
			.storedash-discount-badge {
				background-color: #e74c3c !important;
				color: #fff !important;
				font-weight: bold;
				padding: 3px 8px;
				border-radius: 3px;
				font-size: 0.9em;
			}
		';

		wp_register_style( 'storedash-discount-badges', false, array(), STOREDASH_VERSION );
		wp_enqueue_style( 'storedash-discount-badges' );
		wp_add_inline_style( 'storedash-discount-badges', $css );
	}

	/**
	 * Get the matcher instance.
	 *
	 * @since 2.0.0
	 *
	 * @return Discount_Matcher
	 */
	public function get_matcher() {
		return $this->matcher;
	}
}
