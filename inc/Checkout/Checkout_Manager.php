<?php
/**
 * Checkout optimization module coordinator.
 *
 * Constructed by StoreDash_API on every request (like Seo_Manager). `init()`
 * always registers the REST routes (`rest_api_init`) and the funnel cron
 * handler + schedule self-heal; the front-end components construct only on
 * front-end / AJAX / cron requests and each one registers NO hooks unless
 * its feature group is switched on in `Config` (per-store, default OFF).
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Checkout\Delivery\Delivery_Estimate;
use StoreDash\Checkout\FreeShipping\Free_Shipping_Bar;
use StoreDash\Checkout\Funnel\Funnel_Flusher;
use StoreDash\Checkout\Funnel\Funnel_Tracker;

/**
 * Module manager.
 *
 * @since 1.29.0
 */
class Checkout_Manager {

	/**
	 * Funnel flusher (always constructed: owns the cron handler).
	 *
	 * @var Funnel_Flusher|null
	 */
	protected $flusher = null;

	/**
	 * Funnel tracker (null outside front-end requests or when the funnel is off).
	 *
	 * @var Funnel_Tracker|null
	 */
	protected $tracker = null;

	/**
	 * Free-shipping bar (null outside front-end requests or when off).
	 *
	 * @var Free_Shipping_Bar|null
	 */
	protected $free_shipping_bar = null;

	/**
	 * Register everything.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Funnel plumbing on every request: cron handler + schedule follows the switch.
		$this->flusher = new Funnel_Flusher();
		$this->flusher->register_cron();

		if ( ! $this->is_storefront_request() ) {
			return;
		}

		// Funnel counter (no output; self-skips when `funnel.enabled` is false).
		$this->tracker = new Funnel_Tracker( $this->flusher );

		// Free-shipping bar (display only; self-skips unless enabled with a threshold > 0).
		try {
			$this->free_shipping_bar = new Free_Shipping_Bar();
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Free-shipping bar failed to initialise: ' . $e->getMessage(), 'error' );
		}

		/*
		 * Delivery_Estimate — constructed below. Hooks the shipping-rate
		 *     labels; the `woocommerce_shipping_rate_delivery_time` filter is a
		 *     read-time getter filter, so it needs no blocks-loaded guard.
		 */
		// Estimated delivery dates (self-skips when `delivery_estimate.enabled` is false).
		// Also runs for Store API / REST requests: `is_admin()` is false there, so the
		// storefront gate above already lets them through.
		try {
			( new Delivery_Estimate() );
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Delivery estimate failed to initialise: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * `rest_api_init` callback.
	 */
	public function register_routes(): void {
		( new Route_Registry() )->register_routes( 'storedash/v1' );
	}

	/**
	 * Front-end, AJAX (incl. WC AJAX) or cron request — same gate the
	 * bootstrap uses for Cart_Tracking.
	 *
	 * @return bool
	 */
	protected function is_storefront_request(): bool {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		$is_frontend = ! is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );
		$is_wc_ajax  = defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX;

		return $is_frontend || $is_wc_ajax;
	}
}
