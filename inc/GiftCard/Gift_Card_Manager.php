<?php
/**
 * Gift cards module coordinator.
 *
 * Wires REST routes, the product flag + recipient fields, minting, the
 * checkout fee, reservation / release / refunds, the classic + block checkout
 * UIs and the Store API extension. Constructed by StoreDash_API.
 *
 * @package StoreDash\GiftCard
 * @since   1.24.0
 */

namespace StoreDash\GiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\GiftCard\Blocks\Store_API_Integration;
use StoreDash\GiftCard\Checkout\Blocks_Checkout;
use StoreDash\GiftCard\Checkout\Classic_Checkout;
use StoreDash\GiftCard\Engine\Issuer;
use StoreDash\GiftCard\Engine\Redemption;
use StoreDash\GiftCard\Engine\Refund_Handler;
use StoreDash\GiftCard\Engine\Reservation;
use StoreDash\GiftCard\Product\Gift_Card_Product;
use StoreDash\GiftCard\Product\Recipient_Fields;

/**
 * Module manager.
 *
 * @since 1.24.0
 */
class Gift_Card_Manager {

	/**
	 * Routes.
	 *
	 * @var Route_Registry
	 */
	protected $route_registry;

	/**
	 * Card store.
	 *
	 * @var Card_Ledger
	 */
	protected $ledger;

	/**
	 * Cart side (shared with the UIs and the Store API).
	 *
	 * @var Redemption
	 */
	protected $redemption;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->route_registry = new Route_Registry();
		$this->ledger         = new Card_Ledger();
		$this->redemption     = new Redemption( $this->ledger );
	}

	/**
	 * Register everything.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Product rules (flag, virtual / tax-free, coupon exclusion) and
		// recipient fields run everywhere products and carts are touched.
		( new Gift_Card_Product() )->register_hooks();
		( new Recipient_Fields() )->register_hooks();

		// Order lifecycle: every surface (admin, REST, cron, frontend).
		( new Issuer( $this->ledger ) )->register_hooks();
		( new Reservation( $this->ledger ) )->register_hooks();
		( new Refund_Handler( $this->ledger ) )->register_hooks();

		// Cart fee (frontend, WC AJAX, Store API).
		$this->redemption->register_hooks();

		// Checkout UIs.
		( new Classic_Checkout( $this->redemption ) )->register_hooks();
		( new Blocks_Checkout() )->register_hooks();

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
		( new Store_API_Integration( $this->redemption ) )->register();
	}
}
