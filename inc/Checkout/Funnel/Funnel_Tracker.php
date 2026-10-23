<?php
/**
 * Checkout funnel tracker (WordPress glue).
 *
 * Registers NO hooks unless `Config::is_feature_active('funnel')` — the
 * constructor returns early, same pattern as `DK_Kennitala_Field`.
 *
 * Steps (count-only; never touches order creation, payment, totals or
 * shipping):
 *
 *   - `cart_view`     — `template_redirect` 5: cart page with a non-empty cart.
 *   - `checkout_view` — `template_redirect` 5: checkout page (classic or
 *                       blocks), not the order-received page, non-empty cart.
 *   - `order_placed`  — `woocommerce_checkout_order_processed` (classic) and
 *                       `woocommerce_store_api_checkout_order_processed`
 *                       (blocks / Store API). Read-only; the order argument is
 *                       ignored.
 *
 * Only shoppers with a WC session are counted (no session is ever forced).
 * Device = `wp_is_mobile()`. Every entry point is wrapped in try/catch and
 * logs at most once per request.
 *
 * Known limitation: pure headless Store API shoppers never render WP's cart
 * or checkout pages, so only `order_placed` is seen for them.
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Checkout\Config;

/**
 * Funnel tracker.
 *
 * @since 1.29.0
 */
class Funnel_Tracker {

	/**
	 * Counter.
	 *
	 * @var Funnel_Counter
	 */
	private $counter;

	/**
	 * Flusher (opportunistic shutdown flush), or null.
	 *
	 * @var Funnel_Flusher|null
	 */
	private $flusher;

	/**
	 * One error log per request.
	 *
	 * @var bool
	 */
	private $logged = false;

	/**
	 * Hook everything up — only when the funnel is on.
	 *
	 * @param Funnel_Flusher|null $flusher Flusher for the opportunistic flush.
	 * @param Funnel_Counter|null $counter Counter (injectable for tests).
	 */
	public function __construct( $flusher = null, $counter = null ) {
		if ( ! Config::is_feature_active( Config::FEATURE_FUNNEL ) ) {
			return;
		}
		$this->counter = $counter instanceof Funnel_Counter ? $counter : new Funnel_Counter();
		$this->flusher = $flusher instanceof Funnel_Flusher ? $flusher : null;

		add_action( 'template_redirect', array( $this, 'handle_page_view' ), 5 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_order_placed' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'handle_order_placed' ), 10, 1 );
	}

	/**
	 * `template_redirect`: cart_view / checkout_view.
	 */
	public function handle_page_view(): void {
		try {
			if ( is_admin() || wp_doing_ajax() || ! $this->has_session() || ! $this->cart_has_items() ) {
				return;
			}
			if ( $this->is_cart_page() ) {
				$this->count( 'cart_view' );

				return;
			}
			if ( $this->is_checkout_page() && ! $this->is_order_received_page() ) {
				$this->count( 'checkout_view' );
			}
		} catch ( \Throwable $e ) {
			$this->log_once( $e );
		}
	}

	/**
	 * `woocommerce_checkout_order_processed` / `..._store_api_checkout_order_processed`.
	 * Signatures differ (order id + posted data + order vs order object); the
	 * argument is accepted loosely and never read or written.
	 *
	 * @param mixed $order_or_id Ignored.
	 */
	public function handle_order_placed( $order_or_id = null ): void {
		try {
			if ( ! $this->has_session() ) {
				return;
			}
			$this->count( 'order_placed' );
		} catch ( \Throwable $e ) {
			$this->log_once( $e );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Record a step; on success arm the opportunistic flush.
	 *
	 * @param string $step Step.
	 */
	private function count( string $step ): void {
		if ( $this->counter->record( $step, $this->device() ) && $this->flusher ) {
			$this->flusher->maybe_flush_on_shutdown();
		}
	}

	/**
	 * Device class.
	 *
	 * @return string mobile | desktop | unknown
	 */
	private function device(): string {
		if ( ! function_exists( 'wp_is_mobile' ) ) {
			return 'unknown';
		}

		return wp_is_mobile() ? 'mobile' : 'desktop';
	}

	/**
	 * An existing WC session object (never started here).
	 *
	 * @return bool
	 */
	private function has_session(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$wc = WC();

		return is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session );
	}

	/**
	 * Cart exists and is non-empty.
	 *
	 * @return bool
	 */
	private function cart_has_items(): bool {
		$wc = WC();
		if ( ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'is_empty' ) ) {
			return false;
		}

		return ! $wc->cart->is_empty();
	}

	/**
	 * Cart page (classic shortcode or cart block).
	 *
	 * @return bool
	 */
	private function is_cart_page(): bool {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'has_block' ) && function_exists( 'is_page' ) && is_page() ) {
			$object = get_queried_object();
			if ( $object && has_block( 'woocommerce/cart', $object ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checkout page (classic or blocks) — same logic as Cart_Tracking.
	 *
	 * @return bool
	 */
	private function is_checkout_page(): bool {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT === true ) {
			return true;
		}
		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'is_page' ) ) {
			$checkout_page_id = (int) wc_get_page_id( 'checkout' );
			if ( $checkout_page_id > 0 && is_page( $checkout_page_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Thank-you page.
	 *
	 * @return bool
	 */
	private function is_order_received_page(): bool {
		return function_exists( 'is_order_received_page' ) && is_order_received_page();
	}

	/**
	 * Log one failure per request, then stay silent.
	 *
	 * @param \Throwable $e Error.
	 */
	private function log_once( \Throwable $e ): void {
		if ( $this->logged ) {
			return;
		}
		$this->logged = true;
		\StoreDash_Helpers::log_message( 'Checkout funnel tracking failed: ' . $e->getMessage(), 'error' );
	}
}
