<?php
/**
 * Estimated delivery date under shipping rates (WordPress glue).
 *
 * Registers NO hooks unless `Config::is_feature_active('delivery_estimate')`
 * — the constructor returns early, same pattern as `Funnel_Tracker`.
 *
 * Two read-only surfaces, nothing on the rate object is ever written (label,
 * cost and meta untouched → order line items unaffected):
 *
 *   - Classic cart/checkout — `woocommerce_after_shipping_rate` echoes
 *     `<span class="storedash-eta">…</span>` under the rate.
 *   - Blocks + Store API — `woocommerce_shipping_rate_delivery_time` (WC ≥ 9.2,
 *     runs inside `WC_Shipping_Rate::get_delivery_time()` at read time, so the
 *     session rate cache never goes stale). An incoming non-empty value (another
 *     plugin) is returned unchanged.
 *
 * Text is computed once per rate id per request. Every hook body is wrapped
 * in try/catch and logs at most once per request; a failure renders nothing.
 *
 * `storedash_delivery_estimate_now` filter lets e2e tests override "now"
 * (must return a DateTimeImmutable).
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout\Delivery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Checkout\Config;

/**
 * Delivery estimate renderer.
 *
 * @since 1.29.0
 */
class Delivery_Estimate {

	/**
	 * Delivery-estimate config group.
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * Text per rate id for this request.
	 *
	 * @var array<string, string>
	 */
	private $cache = array();

	/**
	 * One error log per request.
	 *
	 * @var bool
	 */
	private $logged = false;

	/**
	 * Hook everything up — only when the feature is on.
	 */
	public function __construct() {
		if ( ! Config::is_feature_active( Config::FEATURE_DELIVERY_ESTIMATE ) ) {
			return;
		}
		$config       = Config::get();
		$this->config = isset( $config[ Config::FEATURE_DELIVERY_ESTIMATE ] ) && is_array( $config[ Config::FEATURE_DELIVERY_ESTIMATE ] )
			? $config[ Config::FEATURE_DELIVERY_ESTIMATE ]
			: array();

		add_action( 'woocommerce_after_shipping_rate', array( $this, 'render_classic' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		if ( method_exists( 'WC_Shipping_Rate', 'get_delivery_time' ) ) {
			add_filter( 'woocommerce_shipping_rate_delivery_time', array( $this, 'filter_delivery_time' ), 10, 2 );
		}
	}

	/**
	 * `woocommerce_after_shipping_rate`: classic cart + checkout span.
	 *
	 * @param mixed $method WC_Shipping_Rate.
	 * @param mixed $index  Package index (unused).
	 */
	public function render_classic( $method, $index = 0 ): void {
		try {
			if ( ! $this->is_cart_or_checkout_page() || ! is_object( $method ) || ! method_exists( $method, 'get_id' ) ) {
				return;
			}
			// Another plugin's delivery_time (read without our filter) wins; avoid double output.
			if ( '' !== $this->raw_delivery_time( $method ) ) {
				return;
			}
			$text = $this->text_for( (string) $method->get_id() );
			if ( '' === $text ) {
				return;
			}
			echo '<span class="storedash-eta">' . esc_html( $text ) . '</span>';
		} catch ( \Throwable $e ) {
			$this->log_once( $e );
		}
	}

	/**
	 * `woocommerce_shipping_rate_delivery_time`: blocks / Store API text.
	 *
	 * @param mixed $value Incoming delivery_time.
	 * @param mixed $rate  WC_Shipping_Rate.
	 * @return mixed
	 */
	public function filter_delivery_time( $value, $rate = null ) {
		if ( null !== $value && '' !== $value ) {
			return $value;
		}
		try {
			if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_id' ) ) {
				return $value;
			}
			$text = $this->text_for( (string) $rate->get_id() );

			return '' === $text ? $value : $text;
		} catch ( \Throwable $e ) {
			$this->log_once( $e );

			return $value;
		}
	}

	/**
	 * `wp_enqueue_scripts`: tiny stylesheet on cart/checkout only.
	 */
	public function enqueue_styles(): void {
		try {
			if ( ! $this->is_cart_or_checkout_page() ) {
				return;
			}
			wp_enqueue_style(
				'storedash-checkout-eta',
				STOREDASH_URL . 'assets/css/checkout-eta.css',
				array(),
				STOREDASH_VERSION
			);
		} catch ( \Throwable $e ) {
			$this->log_once( $e );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Estimate text for a rate id ('' = nothing to show). Cached per request.
	 *
	 * @param string $rate_id WC rate id.
	 * @return string
	 */
	private function text_for( string $rate_id ): string {
		if ( isset( $this->cache[ $rate_id ] ) ) {
			return $this->cache[ $rate_id ];
		}
		$this->cache[ $rate_id ] = $this->build_text( $rate_id );

		return $this->cache[ $rate_id ];
	}

	/**
	 * Resolve rule → estimate → formatted copy.
	 *
	 * @param string $rate_id WC rate id.
	 * @return string
	 */
	private function build_text( string $rate_id ): string {
		$rules   = isset( $this->config['rules'] ) && is_array( $this->config['rules'] ) ? $this->config['rules'] : array();
		$default = isset( $this->config['default'] ) && is_array( $this->config['default'] ) ? $this->config['default'] : array();

		$rule = Delivery_Estimator::resolve_rule( $rate_id, $rules, $default );
		if ( null === $rule ) {
			return '';
		}

		$estimate = Delivery_Estimator::estimate(
			$this->now(),
			$rule,
			isset( $this->config['cutoff_time'] ) ? (string) $this->config['cutoff_time'] : '',
			isset( $this->config['ship_days'] ) && is_array( $this->config['ship_days'] ) ? $this->config['ship_days'] : array(),
			isset( $this->config['holidays'] ) && is_array( $this->config['holidays'] ) ? $this->config['holidays'] : array()
		);

		$from = $this->format_date( $estimate['min_date'] );
		$to   = $this->format_date( $estimate['max_date'] );

		if ( Delivery_Estimator::KIND_PICKUP === $rule['kind'] ) {
			return str_replace( '{date}', $from, $this->copy( 'pickup' ) );
		}
		if ( $estimate['min_date']->format( 'Y-m-d' ) === $estimate['max_date']->format( 'Y-m-d' ) ) {
			return str_replace( '{date}', $from, $this->copy( 'single' ) );
		}

		return str_replace( array( '{from}', '{to}' ), array( $from, $to ), $this->copy( 'range' ) );
	}

	/**
	 * Merchant copy override or the plugin's translated default.
	 *
	 * @param string $key single|range|pickup.
	 * @return string
	 */
	private function copy( string $key ): string {
		if ( ! empty( $this->config['copy'][ $key ] ) && is_string( $this->config['copy'][ $key ] ) ) {
			return $this->config['copy'][ $key ];
		}
		switch ( $key ) {
			case 'pickup':
				return __( 'Ready for pickup {date}', 'storedash' );
			case 'range':
				return __( 'Arrives {from}–{to}', 'storedash' );
			default:
				return __( 'Arrives {date}', 'storedash' );
		}
	}

	/**
	 * Locale-aware date string in the store timezone.
	 *
	 * @param \DateTimeImmutable $date Date (store tz).
	 * @return string
	 */
	private function format_date( \DateTimeImmutable $date ): string {
		$long   = isset( $this->config['date_format'] ) && 'long' === $this->config['date_format'];
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( $long ) {
			$format = 'l j F';
		} else {
			$format = 0 === strpos( (string) $locale, 'is' ) ? 'D j. M' : 'D j M';
		}

		$formatted = wp_date( $format, $date->getTimestamp(), $this->timezone() );

		return is_string( $formatted ) ? $formatted : $date->format( $format );
	}

	/**
	 * Store-local "now" (overridable for e2e via filter).
	 *
	 * @return \DateTimeImmutable
	 */
	private function now(): \DateTimeImmutable {
		$now = new \DateTimeImmutable( 'now', $this->timezone() );
		/**
		 * Override "now" (tests / previews).
		 *
		 * @param \DateTimeImmutable $now Store-local now.
		 */
		$override = apply_filters( 'storedash_delivery_estimate_now', $now );

		return $override instanceof \DateTimeImmutable ? $override : $now;
	}

	/**
	 * Store timezone.
	 *
	 * @return \DateTimeZone
	 */
	private function timezone(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new \DateTimeZone( 'UTC' );
	}

	/**
	 * Rate's delivery_time without our own filter applied.
	 *
	 * @param object $method WC_Shipping_Rate.
	 * @return string
	 */
	private function raw_delivery_time( $method ): string {
		if ( ! method_exists( $method, 'get_delivery_time' ) ) {
			return '';
		}
		$had = has_filter( 'woocommerce_shipping_rate_delivery_time', array( $this, 'filter_delivery_time' ) );
		if ( false !== $had ) {
			remove_filter( 'woocommerce_shipping_rate_delivery_time', array( $this, 'filter_delivery_time' ), 10 );
		}
		try {
			$raw = $method->get_delivery_time();
		} finally {
			if ( false !== $had ) {
				add_filter( 'woocommerce_shipping_rate_delivery_time', array( $this, 'filter_delivery_time' ), 10, 2 );
			}
		}

		return is_string( $raw ) ? trim( $raw ) : '';
	}

	/**
	 * Classic cart or checkout page, never the order-received page.
	 *
	 * @return bool
	 */
	private function is_cart_or_checkout_page(): bool {
		if ( ! function_exists( 'is_checkout' ) || ! function_exists( 'is_cart' ) ) {
			return false;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return false;
		}

		return is_checkout() || is_cart();
	}

	/**
	 * Log once per request.
	 *
	 * @param \Throwable $e Error.
	 */
	private function log_once( \Throwable $e ): void {
		if ( $this->logged ) {
			return;
		}
		$this->logged = true;
		if ( class_exists( '\StoreDash_Helpers' ) ) {
			\StoreDash_Helpers::log_message( 'Delivery estimate failed: ' . $e->getMessage(), 'error' );
		}
	}
}
