<?php
/**
 * Rewards credit module coordinator.
 *
 * Wires REST routes, the earn / spend / refund / expiry engines, the classic +
 * block checkout UIs and the Store API extension. Mirrors Discounts_Manager:
 * constructed by StoreDash_API, `init()` registers everything.
 *
 * @package StoreDash\Credit
 * @since   1.17.0
 */

namespace StoreDash\Credit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Blocks\Store_API_Integration;
use StoreDash\Credit\Checkout\Account_Prompt;
use StoreDash\Credit\Checkout\Blocks_Checkout_Field;
use StoreDash\Credit\Checkout\Classic_Checkout;
use StoreDash\Credit\Engine\Earn_Handler;
use StoreDash\Credit\Engine\Eligibility;
use StoreDash\Credit\Engine\Expiry_Cron;
use StoreDash\Credit\Engine\Refund_Handler;
use StoreDash\Credit\Engine\Spend_Handler;

/**
 * Module manager.
 *
 * @since 1.17.0
 */
class Credit_Manager {

	/**
	 * Routes.
	 *
	 * @var Route_Registry
	 */
	protected $route_registry;

	/**
	 * Shared ledger.
	 *
	 * @var Ledger
	 */
	protected $ledger;

	/**
	 * Spend handler (shared with the UIs for cart state).
	 *
	 * @var Spend_Handler
	 */
	protected $spend;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->route_registry = new Route_Registry();
		$this->ledger         = new Ledger();
		$this->spend          = new Spend_Handler( $this->ledger, new Eligibility() );
	}

	/**
	 * Register everything.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Order lifecycle hooks must run everywhere (admin, REST, cron, frontend):
		// status changes and refunds come from every surface.
		( new Earn_Handler( $this->ledger, new Rules_Repository(), new Eligibility() ) )->register_hooks();
		( new Refund_Handler( $this->ledger ) )->register_hooks();
		( new Expiry_Cron( $this->ledger ) )->register_hooks();

		// Cart fee + checkout reservation. Needed on the frontend, WC AJAX and
		// the Store API (blocks + headless); harmless elsewhere because the fee
		// hook bails in admin and the order-processed hooks only fire on checkout.
		$this->spend->register_hooks();

		// Checkout UIs.
		( new Classic_Checkout( $this->spend ) )->register_hooks();
		( new Blocks_Checkout_Field( $this->spend, $this->ledger ) )->register_hooks();
		( new Account_Prompt() )->register_hooks();

		// Store API extension. WooCommerce fires woocommerce_blocks_loaded during
		// its own plugins_loaded callback, i.e. before this plugin's (priority 20).
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->init_store_api_integration();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'init_store_api_integration' ) );
		}
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		$this->route_registry->register_routes( 'storedash/v1' );
	}

	/**
	 * Register the Store API extension.
	 */
	public function init_store_api_integration(): void {
		( new Store_API_Integration( $this->spend ) )->register();
	}
}
