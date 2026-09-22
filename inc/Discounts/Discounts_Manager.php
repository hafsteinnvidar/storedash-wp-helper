<?php
/**
 * Discounts Manager
 *
 * All discounts now use dynamic pricing (no static sale_price writes).
 * Prices are calculated on-the-fly via WooCommerce hooks.
 *
 * @package StoreDash\Discounts
 * @since   1.0.0
 */

namespace StoreDash\Discounts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Discounts\Engine\Bogo_Discount_Rule;
use StoreDash\Discounts\Engine\Quantity_Discount_Rule;
use StoreDash\Discounts\Engine\Dynamic_Price_Display;
use StoreDash\Discounts\Engine\Cart_Discount_Orchestrator;
use StoreDash\Discounts\Sync\Discount_DB_Handler;

/**
 * Main manager class for the Discounts module.
 *
 * @since 1.0.0
 */
class Discounts_Manager {

	/**
	 * Route registry instance.
	 *
	 * @since 1.0.0
	 * @var Route_Registry
	 */
	protected $route_registry;

	/**
	 * BOGO discount engine instance.
	 *
	 * @since 1.0.0
	 * @var Bogo_Discount_Rule
	 */
	protected $bogo_engine;

	/**
	 * Quantity discount engine instance.
	 *
	 * @since 1.0.0
	 * @var Quantity_Discount_Rule
	 */
	protected $quantity_engine;

	/**
	 * Database handler instance.
	 *
	 * @since 1.0.0
	 * @var Discount_DB_Handler
	 */
	protected $db_handler;

	/**
	 * Dynamic price display instance.
	 *
	 * @since 2.0.0
	 * @var Dynamic_Price_Display
	 */
	protected $price_display;

	/**
	 * Cart orchestrator instance — the single cart application point.
	 *
	 * @since 3.2.0
	 * @var Cart_Discount_Orchestrator
	 */
	protected $orchestrator;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->route_registry  = new Route_Registry();
		$this->bogo_engine     = new Bogo_Discount_Rule();
		$this->quantity_engine = new Quantity_Discount_Rule();
		$this->price_display   = new Dynamic_Price_Display();
		$this->db_handler      = new Discount_DB_Handler();
		$this->orchestrator    = new Cart_Discount_Orchestrator( null, $this->bogo_engine );
	}

	/**
	 * Check if current request is a WooCommerce Store API request.
	 * Store API is used by WooCommerce Blocks for cart/checkout.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_store_api_request() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return strpos( $request_uri, '/wc/store/' ) !== false;
	}

	/**
	 * Initialize the discounts module.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Register scheduled action handlers for discount activation/deactivation
		add_action( 'storedash_activate_discount', array( $this, 'handle_activate_discount' ) );
		add_action( 'storedash_deactivate_discount', array( $this, 'handle_deactivate_discount' ) );

		// Initialize Store API integration for WooCommerce Blocks. WooCommerce
		// fires woocommerce_blocks_loaded during its OWN plugins_loaded callback,
		// which runs before this plugin's (priority 20) — so the action has
		// usually already fired and a listener alone would never run.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->init_store_api_integration();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'init_store_api_integration' ) );
		}

		// Register discount hooks - allow for Store API requests (WooCommerce Blocks)
		if ( did_action( 'woocommerce_loaded' ) ) {
			// Register hooks immediately if WooCommerce is loaded
			// Allow Store API requests through for Blocks compatibility
			if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || $this->is_store_api_request() ) {
				$this->init_discount_hooks();
			}
		} else {
			add_action( 'woocommerce_loaded', array( $this, 'maybe_init_discount_hooks' ) );
		}
	}

	/**
	 * Conditionally initialize discount hooks after WooCommerce loads.
	 * Allows Store API requests (WooCommerce Blocks) but blocks other REST requests.
	 *
	 * @since 1.0.0
	 */
	public function maybe_init_discount_hooks() {
		// Allow Store API requests for WooCommerce Blocks compatibility
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! $this->is_store_api_request() ) {
			return;
		}

		$this->init_discount_hooks();
	}

	/**
	 * Initialize discount application hooks.
	 * Now allows Store API requests for WooCommerce Blocks compatibility.
	 *
	 * @since 1.0.0
	 * @since 2.0.0 Added Dynamic_Price_Display for product page discounts.
	 */
	public function init_discount_hooks() {
		// Skip REST API requests EXCEPT Store API (WooCommerce Blocks)
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! $this->is_store_api_request() ) {
			return;
		}

		// Prevent hook registration during admin (unless AJAX)
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Register dynamic price display hooks — styles product/category/tag/brand/
		// store_wide discounts on shop and product pages (display only).
		$this->price_display->register_hooks();

		// Register BOGO discount hooks (cart-only).
		$this->bogo_engine->register_hooks();

		// Register Quantity discount hooks (cart + tier table).
		$this->quantity_engine->register_hooks();

		// Single cart application point — resolves ALL rule types per line and
		// applies exactly one winner (display rules now actually charge).
		$this->orchestrator->register_hooks();
	}

	/**
	 * Initialize Store API integration for WooCommerce Blocks.
	 * Called on 'woocommerce_blocks_loaded' action.
	 *
	 * @since 1.0.0
	 */
	public function init_store_api_integration() {
		// Load and initialize Store API integration class
		$integration_file = STOREDASH_PATH . 'inc/Discounts/Blocks/Store_API_Integration.php';
		if ( file_exists( $integration_file ) ) {
			require_once $integration_file;
			if ( class_exists( 'StoreDash\\Discounts\\Blocks\\Store_API_Integration' ) ) {
				new \StoreDash\Discounts\Blocks\Store_API_Integration();
			}
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		$this->route_registry->register_routes();
	}

	/**
	 * Get the route registry instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Route_Registry
	 */
	public function get_route_registry() {
		return $this->route_registry;
	}

	/**
	 * Handle scheduled discount activation.
	 *
	 * Called by Action Scheduler when a discount's start_date is reached.
	 * Simply enables the discount - prices are calculated dynamically via hooks.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Simplified - no batch processing, all discounts are dynamic.
	 *
	 * @param int $discount_id The discount ID passed by Action Scheduler.
	 */
	public function handle_activate_discount( $discount_id ) {
		if ( empty( $discount_id ) ) {
			\StoreDash_Helpers::debug_log( 'handle_activate_discount called without discount_id' );
			return;
		}

		$discount_id = (int) $discount_id;

		\StoreDash_Helpers::debug_log( 'Scheduled activation for discount', array( 'discount_id' => $discount_id ) );

		// Get discount to verify it exists
		$discount = $this->db_handler->get_discount( $discount_id );

		if ( ! $discount ) {
			\StoreDash_Helpers::debug_log( 'Discount not found during scheduled activation', array( 'discount_id' => $discount_id ) );
			return;
		}

		// Enable the discount - prices are now calculated dynamically via hooks
		$updated = $this->db_handler->update_discount( $discount_id, array( 'enabled' => 1 ) );

		if ( ! $updated ) {
			\StoreDash_Helpers::log_message( 'Failed to enable discount during scheduled activation', 'error', array( 'discount_id' => $discount_id ) );
			return;
		}

		\StoreDash_Helpers::debug_log(
			'Discount activated (dynamic pricing - no batch processing needed)',
			array(
				'discount_id' => $discount_id,
				'type'        => $discount->rule_type,
			)
		);
	}

	/**
	 * Handle scheduled discount deactivation.
	 *
	 * Called by Action Scheduler when a discount's end_date is reached.
	 * Simply disables the discount - prices are calculated dynamically via hooks.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Simplified - no batch processing, all discounts are dynamic.
	 *
	 * @param int $discount_id The discount ID passed by Action Scheduler.
	 */
	public function handle_deactivate_discount( $discount_id ) {
		if ( empty( $discount_id ) ) {
			\StoreDash_Helpers::debug_log( 'handle_deactivate_discount called without discount_id' );
			return;
		}

		$discount_id = (int) $discount_id;

		\StoreDash_Helpers::debug_log( 'Scheduled deactivation for discount', array( 'discount_id' => $discount_id ) );

		// Get discount to verify it exists
		$discount = $this->db_handler->get_discount( $discount_id );

		if ( ! $discount ) {
			\StoreDash_Helpers::debug_log( 'Discount not found during scheduled deactivation', array( 'discount_id' => $discount_id ) );
			return;
		}

		// Disable the discount - prices are now calculated dynamically via hooks
		$updated = $this->db_handler->update_discount( $discount_id, array( 'enabled' => 0 ) );

		if ( ! $updated ) {
			\StoreDash_Helpers::log_message( 'Failed to disable discount during scheduled deactivation', 'error', array( 'discount_id' => $discount_id ) );
			return;
		}

		\StoreDash_Helpers::debug_log(
			'Discount deactivated (dynamic pricing - no batch processing needed)',
			array(
				'discount_id' => $discount_id,
				'type'        => $discount->rule_type,
			)
		);
	}
}
