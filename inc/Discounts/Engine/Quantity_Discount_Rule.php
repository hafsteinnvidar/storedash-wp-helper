<?php
/**
 * Quantity Discount Rule Engine
 *
 * Implements tiered pricing based on cart quantity.
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
 * Handles quantity-based tiered pricing calculations.
 *
 * @since 1.0.0
 */
class Quantity_Discount_Rule {

	/**
	 * DB handler instance.
	 *
	 * @since 1.0.0
	 * @var Discount_DB_Handler
	 */
	protected $db_handler;

	/**
	 * Shared priority resolver for disable_lower_priority suppression.
	 *
	 * @since 3.1.0
	 * @var Discount_Priority_Resolver
	 */
	protected $priority_resolver;

	/**
	 * Shared structural matcher (targets/taxonomies/exclusions).
	 *
	 * @since 3.3.0
	 * @var Discount_Matcher
	 */
	protected $matcher;

	/**
	 * Constructor with test-friendly injection (mirrors Discount_Resolver).
	 *
	 * @since 1.0.0
	 *
	 * @param Discount_Matcher|null $matcher Optional matcher to reuse.
	 */
	public function __construct( $matcher = null ) {
		$this->db_handler        = new Discount_DB_Handler();
		$this->matcher           = ( null !== $matcher ) ? $matcher : new Discount_Matcher();
		$this->priority_resolver = new Discount_Priority_Resolver( $this->matcher, $this->db_handler );
	}

	/**
	 * Register quantity discount hooks
	 *
	 * Cart-line price application now lives in Cart_Discount_Orchestrator (the
	 * single before_calculate_totals hook). This engine only registers its
	 * presentation surfaces: the cart-line price display, the product-page tier
	 * table, and the tier-pricing stylesheet.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		// Show sale price display in cart
		add_filter( 'woocommerce_cart_item_price', array( $this, 'display_cart_item_price' ), 10, 3 );

		// Show tier pricing on product pages
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_tier_pricing_table' ), 25 );

		// Enqueue tier pricing styles on frontend
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue tier pricing styles.
	 *
	 * @since 2.1.0
	 */
	public function enqueue_styles() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		if ( ! is_product() ) {
			return;
		}

		wp_enqueue_style(
			'storedash-quantity-tier-pricing',
			STOREDASH_URL . 'assets/css/quantity-tier-pricing.css',
			array(),
			STOREDASH_VERSION
		);
	}

	/**
	 * Calculate tiered price
	 *
	 * @since 1.0.0
	 *
	 * @param float  $original_price Original price.
	 * @param array  $tier           Tier configuration.
	 * @param object $discount       Discount object.
	 * @return float New price.
	 */
	protected function calculate_tiered_price( $original_price, $tier, $discount ) {
		$discount_value = (float) $tier['discount'];

		// Use discount type from tier if specified, otherwise from discount object
		$discount_type = isset( $tier['discount_type'] ) ? $tier['discount_type'] : $discount->discount_type;

		switch ( $discount_type ) {
			case 'percentage':
				return $original_price * ( 1 - ( $discount_value / 100 ) );

			case 'fixed_amount':
				return max( 0, $original_price - $discount_value );

			case 'fixed_price':
				return min( $discount_value, $original_price );

			default:
				return $original_price;
		}
	}

	/**
	 * Display sale price with strikethrough in cart
	 *
	 * @since 1.0.0
	 *
	 * @param string $price Price HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function display_cart_item_price( $price, $cart_item, $cart_item_key ) {
		if ( isset( $cart_item['storedash_quantity_discount'] ) ) {
			$discount_info = $cart_item['storedash_quantity_discount'];

			// Show original price with strikethrough and new price (like regular sale prices)
			$price = sprintf(
				'<del>%s</del> <ins>%s</ins>',
				wp_kses_post( wc_price( $discount_info['original_price'] ) ),
				wp_kses_post( wc_price( $discount_info['discounted_price'] ) )
			);
		}

		return $price;
	}

	/**
	 * Display tier pricing table on product pages
	 *
	 * @since 1.0.0
	 */
	public function display_tier_pricing_table() {
		// Prevent running during REST API requests, admin, or AJAX (unless frontend AJAX)
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Prevent running during JSON requests (REST API discovery)
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}

		global $product;

		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();

		// Get active quantity discounts for this product
		$quantity_discounts = $this->db_handler->get_active_discounts( 'quantity' );

		if ( empty( $quantity_discounts ) ) {
			return;
		}

		// Cross-engine priority ceiling for this product (PHP_INT_MAX = none).
		$ceiling = $this->priority_resolver->get_priority_ceiling( $product );

		foreach ( $quantity_discounts as $discount ) {
			if ( ! $this->discount_display_eligible( $product, $discount ) ) {
				continue;
			}

			// Don't advertise a tier table for a discount suppressed by a
			// higher-priority disable_lower_priority rule. Inert when unset.
			if ( $this->priority_resolver->is_suppressed( $discount, $ceiling ) ) {
				continue;
			}

			$config = json_decode( $discount->rule_config, true );
			$tiers  = isset( $config['tiers'] ) ? $config['tiers'] : array();

			if ( empty( $tiers ) ) {
				continue;
			}

			// Display tier table
			$this->render_tier_table( $tiers, $product, $discount );

			// Only show first matching discount
			break;
		}
	}

	/**
	 * Render tier pricing table HTML
	 *
	 * @since 1.0.0
	 *
	 * @param array       $tiers    Tier configuration.
	 * @param \WC_Product $product Product object.
	 * @param object      $discount Discount object.
	 */
	protected function render_tier_table( $tiers, $product, $discount ) {
		$regular_price = $product->get_regular_price();

		if ( empty( $regular_price ) ) {
			return;
		}

		// Mirror the cart engine's price basis (Discount_Resolver::compute_quantity):
		// when apply_to_sale_price is set and the product carries a merchant sale
		// price, tiers price from the sale price; otherwise from regular price.
		$sale        = (string) $product->get_sale_price();
		$sale        = ( '' !== $sale && (float) $sale > 0 ) ? (float) $sale : 0.0;
		$basis_price = ( ! empty( $discount->apply_to_sale_price ) && $sale > 0 ) ? $sale : (float) $regular_price;

		?>
		<div class="storedash-quantity-pricing-table">
			<h3><?php esc_html_e( 'Bulk Pricing', 'storedash' ); ?></h3>
			<table class="storedash-tier-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Quantity', 'storedash' ); ?></th>
						<th><?php esc_html_e( 'Price Per Item', 'storedash' ); ?></th>
						<th><?php esc_html_e( 'Discount', 'storedash' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tiers as $tier ) : ?>
						<?php
						$min = isset( $tier['min_quantity'] ) ? (int) $tier['min_quantity'] : 0;
						$max = isset( $tier['max_quantity'] ) && ! is_null( $tier['max_quantity'] ) && $tier['max_quantity'] !== ''
							? (int) $tier['max_quantity']
							: null;

						// Calculate price for this tier
						$tier_price     = $this->calculate_tiered_price( $basis_price, $tier, $discount );
						$discount_value = (float) $tier['discount'];
						$discount_type  = isset( $tier['discount_type'] ) ? $tier['discount_type'] : $discount->discount_type;
						?>
						<tr>
							<td>
								<?php
								if ( $max ) {
									echo esc_html( sprintf( '%d - %d', $min, $max ) );
								} else {
									echo esc_html( sprintf( '%d+', $min ) );
								}
								?>
							</td>
							<td><?php echo wp_kses_post( wc_price( $tier_price ) ); ?></td>
							<td>
								<?php
								if ( $discount_type === 'percentage' ) {
									echo esc_html( sprintf( '%d%%', $discount_value ) );
								} else {
									echo wp_kses_post( wc_price( $basis_price - $tier_price ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Whether a quantity discount may be advertised for a product on the PDP.
	 *
	 * Mirrors the cart engine's gating (Discount_Matcher): target/exclusion
	 * match plus the disable_on_sale check, so the tier table never advertises
	 * tiers the cart will refuse to apply.
	 *
	 * @since 3.2.0
	 *
	 * @param \WC_Product $product  Product object.
	 * @param object      $discount Discount object.
	 * @return bool True if the tier table may show this discount.
	 */
	protected function discount_display_eligible( $product, $discount ) {
		// Delegate to the shared matcher so the tier table honours the exact
		// same targeting, exclusions (product + taxonomy), and disable_on_sale
		// gates the cart engine applies. A local re-implementation here once
		// drifted (it had no taxonomy awareness at all).
		return $this->matcher->discount_applies_to_product( $discount, $product );
	}
}

