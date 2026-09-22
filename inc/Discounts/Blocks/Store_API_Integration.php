<?php
declare(strict_types=1);

/**
 * Store API Integration for WooCommerce Blocks
 *
 * Extends the Store API cart and product schemas to expose discount information
 * for WooCommerce Blocks cart, checkout, and product listings.
 *
 * @package StoreDash\Discounts\Blocks
 * @since   1.0.0
 */

namespace StoreDash\Discounts\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema;
use StoreDash\Discounts\Engine\Discount_Matcher;

/**
 * Registers Store API endpoint data for discounts.
 *
 * @since 1.0.0
 */
class Store_API_Integration {

	/**
	 * Discount matcher instance.
	 *
	 * @since 2.0.0
	 * @var Discount_Matcher|null
	 */
	protected $matcher = null;

	/**
	 * Discount resolver instance.
	 *
	 * @since 3.2.0
	 * @var \StoreDash\Discounts\Engine\Discount_Resolver|null
	 */
	protected $resolver = null;

	/**
	 * Cache of discount names by ID.
	 *
	 * Prevents repeated DB queries when multiple cart items reference
	 * the same BOGO discount rule.
	 *
	 * @since 2.1.0
	 * @var array
	 */
	protected $discount_name_cache = array();

	/**
	 * Re-entrancy guard for the price filters.
	 *
	 * Resolving a variable product reads its variation prices, which fires the
	 * same filters again.
	 *
	 * @since 1.9.1
	 * @var bool
	 */
	protected $is_pricing = false;

	/**
	 * Constructor - registers endpoint data on woocommerce_blocks_loaded.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->register_endpoint_data();
		add_filter( 'rest_pre_dispatch', array( $this, 'maybe_filter_product_prices' ), 10, 3 );
	}

	/**
	 * Attach the discounted-price filters for Store API *product* requests only.
	 *
	 * Headless storefronts read catalog prices from `/wc/store/v1/products`, and
	 * the Store API reports whatever WooCommerce has stored — so a discounted
	 * product shows at full price no matter what the price-display filters do on
	 * the WordPress theme.
	 *
	 * Scope is deliberately narrow on two sides:
	 *
	 * - Product routes ONLY, never cart/checkout. Cart lines are priced by
	 *   Cart_Discount_Orchestrator with the full cart context (conditions,
	 *   disable_with_coupons, BOGO); a display-context filter layered on top
	 *   would overwrite a correct charge with the wrong number.
	 * - Store API ONLY, never wc/v3. storedash-sync reads products over wc/v3
	 *   and writes what it finds back to Supabase as the merchant's own price —
	 *   discounted values there would be re-imported as regular prices and
	 *   compound on the next discount.
	 *
	 * @since 1.9.1
	 *
	 * @param mixed            $result  Pre-dispatch short-circuit value.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Request being dispatched.
	 * @return mixed Untouched $result — this filter is used as a routing hook.
	 */
	public function maybe_filter_product_prices( $result, $server, $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		// Matches /wc/store/v1/products, /products/<id> and /products/collection-data,
		// and excludes /wc/store/v1/cart*.
		if ( ! preg_match( '#^/wc/store/v\d+/products#', (string) $request->get_route() ) ) {
			return $result;
		}

		if ( ! $this->get_matcher()->has_active_discounts() ) {
			return $result;
		}

		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_product_price' ), 100, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_product_price' ), 100, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_variation_prices_price' ), 100, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'filter_variation_prices_hash' ), 100 );
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'filter_product_is_on_sale' ), 100, 2 );

		return $result;
	}

	/**
	 * Report a discounted product as on sale in Store API responses.
	 *
	 * Storefronts decide whether to render a struck-through "was" price from
	 * `on_sale` together with `regular_price !== price`. Without this the price
	 * would drop but the comparison price would silently disappear, so the
	 * shopper sees a cheap product with no sign anything was discounted.
	 *
	 * Dynamic_Price_Display's own filter deliberately opts out of every REST
	 * request, which is what keeps wc/v3 honest; this re-enables the behaviour
	 * for the Store API product routes alone.
	 *
	 * @since 1.9.1
	 *
	 * @param bool        $on_sale Whether WooCommerce considers it on sale.
	 * @param \WC_Product $product Product object.
	 * @return bool
	 */
	public function filter_product_is_on_sale( $on_sale, $product ) {
		if ( $on_sale || $this->is_pricing ) {
			return $on_sale;
		}

		$this->is_pricing = true;
		try {
			$result = $this->get_resolver()->resolve_for_display( $product );
		} finally {
			$this->is_pricing = false;
		}

		return $result && null !== $result->unit_price;
	}

	/**
	 * Replace a product's price with the resolved discount price.
	 *
	 * @since 1.9.1
	 *
	 * @param string|float $price   Stored price.
	 * @param \WC_Product  $product Product object.
	 * @return string|float Discounted price, or the original when nothing applies.
	 */
	public function filter_product_price( $price, $product ) {
		if ( $this->is_pricing ) {
			return $price;
		}

		$this->is_pricing = true;
		try {
			$result = $this->get_resolver()->resolve_for_display( $product );
		} finally {
			$this->is_pricing = false;
		}

		if ( ! $result || null === $result->unit_price ) {
			return $price;
		}

		// Never raise a price: the stored value wins if it is already lower.
		if ( '' !== (string) $price && (float) $price <= (float) $result->unit_price ) {
			return $price;
		}

		return $result->unit_price;
	}

	/**
	 * Discount a single variation inside a variable product's price range.
	 *
	 * @since 1.9.1
	 *
	 * @param string|float $price     Variation price.
	 * @param \WC_Product  $variation Variation object.
	 * @return string|float Discounted price, or the original when nothing applies.
	 */
	public function filter_variation_prices_price( $price, $variation ) {
		if ( $this->is_pricing ) {
			return $price;
		}

		$this->is_pricing = true;
		try {
			$result = $this->get_resolver()->resolve( $variation, 1, 'display' );
		} finally {
			$this->is_pricing = false;
		}

		if ( ! $result || null === $result->unit_price ) {
			return $price;
		}

		if ( '' !== (string) $price && (float) $price <= (float) $result->unit_price ) {
			return $price;
		}

		return $result->unit_price;
	}

	/**
	 * Fold the active-discount signature into WooCommerce's variation-price
	 * cache key.
	 *
	 * WooCommerce caches computed variation prices in a transient. Without this,
	 * editing or expiring a discount would keep serving the prices calculated
	 * under the previous rules until something else invalidated the product's
	 * transients.
	 *
	 * @since 1.9.1
	 *
	 * @param array $price_hash Hash components.
	 * @return array
	 */
	public function filter_variation_prices_hash( $price_hash ) {
		if ( ! is_array( $price_hash ) ) {
			return $price_hash;
		}

		$price_hash['storedash_discounts'] = $this->get_resolver()->get_discount_signature();

		return $price_hash;
	}

	/**
	 * Get discount matcher instance (lazy loaded).
	 *
	 * @since 2.0.0
	 * @return Discount_Matcher
	 */
	protected function get_matcher() {
		if ( $this->matcher === null ) {
			$this->matcher = new Discount_Matcher();
		}
		return $this->matcher;
	}

	/**
	 * Get discount resolver instance (lazy loaded).
	 *
	 * @since 3.2.0
	 * @return \StoreDash\Discounts\Engine\Discount_Resolver
	 */
	protected function get_resolver() {
		if ( null === $this->resolver ) {
			$this->resolver = new \StoreDash\Discounts\Engine\Discount_Resolver();
		}
		return $this->resolver;
	}

	/**
	 * Register endpoint data for cart, cart items, and products.
	 *
	 * @since 1.0.0
	 */
	public function register_endpoint_data() {
		if ( ! function_exists( '\woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		// Extend Cart schema with discount summary
		\woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => 'storedash_discounts',
				'data_callback'   => array( $this, 'cart_data_callback' ),
				'schema_callback' => array( $this, 'cart_schema_callback' ),
				'schema_type'     => ARRAY_A,
			)
		);

		// Extend Cart Item schema with applied discount info
		\woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartItemSchema::IDENTIFIER,
				'namespace'       => 'storedash_discounts',
				'data_callback'   => array( $this, 'cart_item_data_callback' ),
				'schema_callback' => array( $this, 'cart_item_schema_callback' ),
				'schema_type'     => ARRAY_A,
			)
		);

		// Extend Product schema with dynamic discount info (for WooCommerce Blocks product grids)
		\woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => ProductSchema::IDENTIFIER,
				'namespace'       => 'storedash_discounts',
				'data_callback'   => array( $this, 'product_data_callback' ),
				'schema_callback' => array( $this, 'product_schema_callback' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Cart-level discount data callback.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function cart_data_callback() {
		$applied_discounts = $this->get_applied_discounts_summary();
		$total_savings     = $this->calculate_total_savings();

		return array(
			'applied_discounts'       => $applied_discounts,
			'total_savings'           => $total_savings,
			'total_savings_formatted' => \wc_price( $total_savings ),
			'has_discounts'           => ! empty( $applied_discounts ),
		);
	}

	/**
	 * Cart-level schema callback.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function cart_schema_callback() {
		return array(
			'applied_discounts'       => array(
				'description' => __( 'List of applied Storedash discounts.', 'storedash' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
						'type' => array( 'type' => 'string' ),
					),
				),
			),
			'total_savings'           => array(
				'description' => __( 'Total savings from Storedash discounts.', 'storedash' ),
				'type'        => 'number',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'total_savings_formatted' => array(
				'description' => __( 'Formatted total savings.', 'storedash' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'has_discounts'           => array(
				'description' => __( 'Whether any Storedash discounts are applied.', 'storedash' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Cart item-level discount data callback.
	 *
	 * @since 1.0.0
	 * @param array $cart_item Cart item data.
	 * @return array
	 */
	public function cart_item_data_callback( $cart_item ) {
		$data = array(
			'is_bogo_free_item'  => false,
			'bogo_discount_pct'  => null,
			'bogo_parent_key'    => null,
			'quantity_discount'  => null,
			'price_discount'     => null,
			'discount_rule_id'   => null,
			'discount_rule_name' => null,
			'savings'            => 0,
			'savings_formatted'  => '',
		);

		// BOGO free item info
		if ( ! empty( $cart_item['storedash_is_free_item'] ) ) {
			$data['is_bogo_free_item'] = true;
			$data['bogo_discount_pct'] = isset( $cart_item['storedash_discount_percent'] )
				? (float) $cart_item['storedash_discount_percent']
				: 100;
			$data['bogo_parent_key']   = $cart_item['storedash_bogo_parent'] ?? null;
			$data['discount_rule_id']  = $cart_item['storedash_bogo_rule_id'] ?? null;

			// Calculate savings for BOGO item
			if ( isset( $cart_item['data'] ) ) {
				$original                  = $cart_item['data']->get_regular_price();
				$current                   = $cart_item['data']->get_price();
				$savings                   = ( $original - $current ) * $cart_item['quantity'];
				$data['savings']           = $savings;
				$data['savings_formatted'] = \wc_price( $savings );
			}
		}

		// Price-rule discount info (store_wide/product/category/tag/brand →
		// kind 'price'). The orchestrator writes storedash_discount_applied for
		// EVERY non-BOGO winner and mirrors it into storedash_quantity_discount
		// for quantity kinds, so this branch skips quantity to avoid
		// double-reporting — the quantity branch below owns that kind.
		if ( ! empty( $cart_item['storedash_discount_applied'] )
			&& 'quantity' !== ( $cart_item['storedash_discount_applied']['kind'] ?? '' ) ) {
			$applied = $cart_item['storedash_discount_applied'];

			$data['price_discount']     = array(
				'original_price'   => $applied['original_price'] ?? 0,
				'discounted_price' => $applied['discounted_price'] ?? 0,
			);
			$data['discount_rule_id']   = $applied['discount_id'] ?? null;
			$data['discount_rule_name'] = $applied['discount_name'] ?? null;
			$data['savings']            = $applied['savings'] ?? 0;
			$data['savings_formatted']  = \wc_price( $data['savings'] );
		}

		// Quantity discount info
		if ( ! empty( $cart_item['storedash_quantity_discount'] ) ) {
			$qty_discount = $cart_item['storedash_quantity_discount'];

			$data['quantity_discount']  = array(
				'original_price'   => $qty_discount['original_price'] ?? 0,
				'discounted_price' => $qty_discount['discounted_price'] ?? 0,
				'tier'             => $qty_discount['tier'] ?? null,
			);
			$data['discount_rule_id']   = $qty_discount['discount_id'] ?? null;
			$data['discount_rule_name'] = $qty_discount['discount_name'] ?? null;
			$data['savings']            = $qty_discount['savings'] ?? 0;
			$data['savings_formatted']  = \wc_price( $data['savings'] );
		}

		return $data;
	}

	/**
	 * Cart item-level schema callback.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function cart_item_schema_callback() {
		return array(
			'is_bogo_free_item'  => array(
				'description' => __( 'Whether this is a BOGO free item.', 'storedash' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'bogo_discount_pct'  => array(
				'description' => __( 'BOGO discount percentage.', 'storedash' ),
				'type'        => array( 'number', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'bogo_parent_key'    => array(
				'description' => __( 'Parent cart item key for BOGO items.', 'storedash' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'quantity_discount'  => array(
				'description' => __( 'Quantity discount details.', 'storedash' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'price_discount'     => array(
				'description' => __( 'Price-rule discount details (store-wide/product/category/tag/brand).', 'storedash' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'discount_rule_id'   => array(
				'description' => __( 'Applied discount rule ID.', 'storedash' ),
				'type'        => array( 'integer', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'discount_rule_name' => array(
				'description' => __( 'Applied discount rule name.', 'storedash' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'savings'            => array(
				'description' => __( 'Total savings for this item.', 'storedash' ),
				'type'        => 'number',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'savings_formatted'  => array(
				'description' => __( 'Formatted savings for this item.', 'storedash' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Product-level discount data callback.
	 *
	 * Provides dynamic discount information for WooCommerce Blocks product grids.
	 *
	 * @since 2.0.0
	 * @param \WC_Product $product Product object.
	 * @return array
	 */
	public function product_data_callback( $product ) {
		$data = array(
			'has_discount'        => false,
			'discount_id'         => null,
			'discount_name'       => null,
			'discount_type'       => null,
			'discount_amount'     => null,
			'original_price'      => null,
			'discounted_price'    => null,
			'discount_percentage' => null,
			'savings'             => null,
			'savings_formatted'   => null,
		);

		// resolve_for_display(), not resolve(): a variable product has no price
		// of its own, so asking the parent reported has_discount=false for every
		// variable product in the catalog.
		$result = $this->get_resolver()->resolve_for_display( $product );

		if ( ! $result || null === $result->unit_price || $result->base_price <= 0 ) {
			return $data;
		}

		$original_price   = $result->base_price;
		$discounted_price = $result->unit_price;
		$savings          = $original_price - $discounted_price;
		$percentage       = round( ( $savings / $original_price ) * 100 );

		return array(
			'has_discount'        => true,
			'discount_id'         => (int) $result->discount->id,
			'discount_name'       => $result->discount->name,
			'discount_type'       => $result->discount->discount_type,
			'discount_amount'     => (float) $result->discount->amount,
			'original_price'      => $original_price,
			'discounted_price'    => $discounted_price,
			'discount_percentage' => $percentage,
			'savings'             => $savings,
			'savings_formatted'   => \wc_price( $savings ),
		);
	}

	/**
	 * Product-level schema callback.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public function product_schema_callback() {
		return array(
			'has_discount'        => array(
				'description' => __( 'Whether the product has a Storedash discount.', 'storedash' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'discount_id'         => array(
				'description' => __( 'Applied discount rule ID.', 'storedash' ),
				'type'        => array( 'integer', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'discount_name'       => array(
				'description' => __( 'Applied discount rule name.', 'storedash' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'discount_type'       => array(
				'description' => __( 'Discount type (percentage, fixed_amount, fixed_price).', 'storedash' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'discount_amount'     => array(
				'description' => __( 'Discount amount value.', 'storedash' ),
				'type'        => array( 'number', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'original_price'      => array(
				'description' => __( 'Original regular price.', 'storedash' ),
				'type'        => array( 'number', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'discounted_price'    => array(
				'description' => __( 'Calculated discounted price.', 'storedash' ),
				'type'        => array( 'number', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'discount_percentage' => array(
				'description' => __( 'Discount percentage (0-100).', 'storedash' ),
				'type'        => array( 'integer', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'savings'             => array(
				'description' => __( 'Savings amount (original - discounted).', 'storedash' ),
				'type'        => array( 'number', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'savings_formatted'   => array(
				'description' => __( 'Formatted savings amount.', 'storedash' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Get summary of applied discounts from cart.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_applied_discounts_summary() {
		$discounts = array();
		$seen_ids  = array();

		if ( ! \WC()->cart ) {
			return $discounts;
		}

		foreach ( \WC()->cart->get_cart() as $cart_item ) {
			// BOGO discounts
			if ( ! empty( $cart_item['storedash_bogo_rule_id'] ) ) {
				$rule_id = $cart_item['storedash_bogo_rule_id'];
				if ( ! in_array( $rule_id, $seen_ids, true ) ) {
					$seen_ids[]  = $rule_id;
					$discounts[] = array(
						'id'   => $rule_id,
						'name' => $this->get_discount_name( $rule_id ),
						'type' => 'bogo',
					);
				}
			}

			// Price-rule + quantity discounts (canonical key). Quantity winners
			// also carry the legacy storedash_quantity_discount mirror with the
			// same discount_id, so $seen_ids dedupes across both keys.
			if ( ! empty( $cart_item['storedash_discount_applied']['discount_id'] ) ) {
				$rule_id = (int) $cart_item['storedash_discount_applied']['discount_id'];
				if ( ! in_array( $rule_id, $seen_ids, true ) ) {
					$seen_ids[]  = $rule_id;
					$discounts[] = array(
						'id'   => $rule_id,
						'name' => $cart_item['storedash_discount_applied']['discount_name'] ?? '',
						'type' => $cart_item['storedash_discount_applied']['kind'] ?? 'price',
					);
				}
			}

			// Quantity discounts (legacy mirror — kept for carts persisted
			// before storedash_discount_applied existed).
			if ( ! empty( $cart_item['storedash_quantity_discount']['discount_id'] ) ) {
				$rule_id = (int) $cart_item['storedash_quantity_discount']['discount_id'];
				if ( ! in_array( $rule_id, $seen_ids, true ) ) {
					$seen_ids[]  = $rule_id;
					$discounts[] = array(
						'id'   => $rule_id,
						'name' => $cart_item['storedash_quantity_discount']['discount_name'] ?? '',
						'type' => 'quantity',
					);
				}
			}
		}

		return $discounts;
	}

	/**
	 * Calculate total savings from all discounts in cart.
	 *
	 * @since 1.0.0
	 * @return float
	 */
	protected function calculate_total_savings() {
		$total = 0;

		if ( ! \WC()->cart ) {
			return $total;
		}

		foreach ( \WC()->cart->get_cart() as $cart_item ) {
			// BOGO savings
			if ( ! empty( $cart_item['storedash_is_free_item'] ) && isset( $cart_item['data'] ) ) {
				$original = $cart_item['data']->get_regular_price();
				$current  = $cart_item['data']->get_price();
				$total   += ( $original - $current ) * $cart_item['quantity'];
			}

			// Price-rule / quantity savings. Quantity winners write BOTH the
			// canonical storedash_discount_applied key and the legacy quantity
			// mirror with identical payloads — count the canonical key once and
			// only fall back to the mirror when the canonical key is absent,
			// otherwise savings double-count.
			if ( ! empty( $cart_item['storedash_discount_applied']['savings'] ) ) {
				$total += $cart_item['storedash_discount_applied']['savings'];
			} elseif ( ! empty( $cart_item['storedash_quantity_discount']['savings'] ) ) {
				$total += $cart_item['storedash_quantity_discount']['savings'];
			}
		}

		return $total;
	}

	/**
	 * Get discount name by ID.
	 *
	 * @since 1.0.0
	 * @param int $discount_id Discount ID.
	 * @return string
	 */
	protected function get_discount_name( $discount_id ) {
		if ( isset( $this->discount_name_cache[ $discount_id ] ) ) {
			return $this->discount_name_cache[ $discount_id ];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'storedash_discounts';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT name FROM {$table} WHERE id = %d",
				$discount_id
			)
		);

		$this->discount_name_cache[ $discount_id ] = $name ?: '';

		return $this->discount_name_cache[ $discount_id ];
	}
}
