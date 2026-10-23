<?php
/**
 * Free-shipping progress bar ("Add X more for free shipping").
 *
 * Pure display: reads cart totals, writes nothing. Registers NO hooks unless
 * `Config::is_feature_active('free_shipping_bar')` AND the threshold is > 0.
 *
 * Classic cart / checkout: echoed on `woocommerce_before_cart` /
 * `woocommerce_before_checkout_form` and refreshed through WooCommerce's
 * fragment filters (`.storedash-fs-bar` key). Block cart / checkout: the
 * initial markup is printed in `wp_footer` inside a `<template>` and
 * `assets/js/checkout-bar.js` inserts it above the block and recomputes from
 * the `wc/store/cart` data store on every cart change.
 *
 * Assets load only on cart / checkout pages (never order-received) and only
 * for the surfaces listed in `show_on`.
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout\FreeShipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Checkout\Config;

/**
 * Free-shipping bar.
 *
 * @since 1.29.0
 */
class Free_Shipping_Bar {

	/**
	 * Asset handle.
	 */
	const HANDLE = 'storedash-checkout-bar';

	/**
	 * Root CSS class (also the fragment key).
	 */
	const CSS_CLASS = 'storedash-fs-bar';

	/**
	 * Template id for the block insert.
	 */
	const TEMPLATE_ID = 'storedash-fs-bar-tpl';

	/**
	 * Group config.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Resolved surface for this request: 'cart' | 'checkout' | ''.
	 *
	 * @var string|null
	 */
	private $surface = null;

	/**
	 * Queried page uses the cart / checkout block.
	 *
	 * @var bool
	 */
	private $is_blocks = false;

	/**
	 * One error log per request.
	 *
	 * @var bool
	 */
	private $logged = false;

	/**
	 * Hook everything up — only when the bar is on and has a threshold.
	 */
	public function __construct() {
		if ( ! Config::is_feature_active( Config::FEATURE_FREE_SHIPPING_BAR ) ) {
			return;
		}
		$this->settings = Config::get()['free_shipping_bar'];
		if ( (float) ( $this->settings['threshold'] ?? 0 ) <= 0 ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'prepare_page' ), 20 );
		add_action( 'woocommerce_before_cart', array( $this, 'render_cart' ), 10 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout' ), 10 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_fragment' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'add_fragment' ) );
		add_action( 'wp_footer', array( $this, 'render_block_template' ), 5 );
	}

	/* ------------------------------------------------------------------ */
	/* Hooks                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * `wp_enqueue_scripts` 20: resolve the surface and enqueue assets.
	 */
	public function prepare_page(): void {
		try {
			if ( '' === $this->surface() ) {
				return;
			}
			wp_enqueue_style( self::HANDLE, STOREDASH_URL . 'assets/css/checkout-bar.css', array(), STOREDASH_VERSION );

			if ( ! $this->is_blocks ) {
				return;
			}
			wp_enqueue_script( self::HANDLE, STOREDASH_URL . 'assets/js/checkout-bar.js', array(), STOREDASH_VERSION, true );
			wp_localize_script( self::HANDLE, 'storedashFsBar', $this->script_data() );
		} catch ( \Throwable $e ) {
			$this->log_once( $e );
		}
	}

	/**
	 * `woocommerce_before_cart`.
	 */
	public function render_cart(): void {
		$this->echo_for( 'cart' );
	}

	/**
	 * `woocommerce_before_checkout_form`.
	 */
	public function render_checkout(): void {
		$this->echo_for( 'checkout' );
	}

	/**
	 * Fragment filters: re-render after AJAX cart / order-review updates.
	 *
	 * @param mixed $fragments Fragments.
	 * @return mixed
	 */
	public function add_fragment( $fragments ) {
		try {
			if ( ! is_array( $fragments ) ) {
				return $fragments;
			}
			$surface = $this->surface();
			if ( '' === $surface && defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT === true ) {
				$surface = 'checkout';
			}
			if ( '' !== $surface && ! $this->is_blocks && $this->shows_on( $surface ) ) {
				$fragments[ '.' . self::CSS_CLASS ] = $this->render();
			}
		} catch ( \Throwable $e ) {
			$this->log_once( $e );
		}

		return $fragments;
	}

	/**
	 * `wp_footer` 5: initial markup for the block insert script.
	 */
	public function render_block_template(): void {
		try {
			if ( ! $this->is_blocks || '' === $this->surface() ) {
				return;
			}
			$markup = $this->render();
			if ( '' === $markup ) {
				return;
			}
			echo '<template id="' . esc_attr( self::TEMPLATE_ID ) . '">' . $markup . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every merchant value.
		} catch ( \Throwable $e ) {
			$this->log_once( $e );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Echo the bar for a classic surface when enabled for it.
	 *
	 * @param string $surface cart | checkout.
	 */
	private function echo_for( string $surface ): void {
		try {
			if ( ! $this->shows_on( $surface ) || $this->is_order_received() ) {
				return;
			}
			echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every merchant value.
		} catch ( \Throwable $e ) {
			$this->log_once( $e );
		}
	}

	/**
	 * Current bar markup from the live cart. Always returns the wrapper (so a
	 * fragment replacement has a target); `is-hidden` when there is nothing
	 * to say. Empty string only without a cart.
	 *
	 * @return string
	 */
	public function render(): string {
		$state = $this->cart_state();
		if ( null === $state ) {
			return '';
		}
		$copy   = $this->copy();
		$hidden = $state['empty'] || ( $state['unlocked'] && ! empty( $this->settings['hide_when_unlocked'] ) );
		$amount = function_exists( 'wc_price' ) ? wc_price( $state['remaining'] ) : esc_html( (string) $state['remaining'] );
		$text   = $state['unlocked']
			? esc_html( $copy['unlocked'] )
			: Threshold_Calculator::format_message( esc_html( $copy['remaining'] ), $amount );

		$classes = array( self::CSS_CLASS );
		if ( $state['unlocked'] ) {
			$classes[] = 'is-unlocked';
		}
		if ( $hidden ) {
			$classes[] = 'is-hidden';
		}

		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" role="status" aria-live="polite" style="' . esc_attr( $this->style_vars() ) . '">'
			. '<div class="' . esc_attr( self::CSS_CLASS . '__text' ) . '">' . $text . '</div>'
			. '<div class="' . esc_attr( self::CSS_CLASS . '__track' ) . '"><div class="' . esc_attr( self::CSS_CLASS . '__fill' ) . '" style="width:' . esc_attr( (string) round( $state['progress'], 1 ) ) . '%"></div></div>'
			. '</div>';
	}

	/**
	 * Threshold state from `WC()->cart`, or null without a cart.
	 *
	 * @return array|null { remaining, unlocked, progress, empty }
	 */
	private function cart_state() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		if ( ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'get_subtotal' ) ) {
			return null;
		}
		$cart           = $wc->cart;
		$incl           = $this->include_tax( $cart );
		$subtotal       = (float) $cart->get_subtotal() + ( $incl ? (float) $cart->get_subtotal_tax() : 0.0 );
		$decimals       = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$state          = Threshold_Calculator::compute(
			(float) $this->settings['threshold'],
			$subtotal,
			(float) $cart->get_discount_total(),
			(float) $cart->get_discount_tax(),
			$incl,
			! empty( $this->settings['ignore_discounts'] ),
			$decimals
		);
		$state['empty'] = method_exists( $cart, 'is_empty' ) ? (bool) $cart->is_empty() : false;

		return $state;
	}

	/**
	 * Resolve `include_tax`: woo = follow the cart display setting.
	 *
	 * @param object $cart WC_Cart.
	 * @return bool
	 */
	private function include_tax( $cart ): bool {
		$mode = (string) ( $this->settings['include_tax'] ?? 'woo' );
		if ( 'incl' === $mode ) {
			return true;
		}
		if ( 'excl' === $mode ) {
			return false;
		}

		return method_exists( $cart, 'display_prices_including_tax' ) ? (bool) $cart->display_prices_including_tax() : false;
	}

	/**
	 * Copy with translated defaults for empty merchant strings.
	 *
	 * @return array { remaining, unlocked }
	 */
	private function copy(): array {
		$copy = isset( $this->settings['copy'] ) && is_array( $this->settings['copy'] ) ? $this->settings['copy'] : array();

		return array(
			'remaining' => '' !== trim( (string) ( $copy['remaining'] ?? '' ) ) ? (string) $copy['remaining'] : __( 'Add {amount} more for free shipping', 'storedash' ),
			'unlocked'  => '' !== trim( (string) ( $copy['unlocked'] ?? '' ) ) ? (string) $copy['unlocked'] : __( "You've unlocked free shipping", 'storedash' ),
		);
	}

	/**
	 * Inline CSS custom properties from the merchant tokens (already
	 * regex-limited by Config::sanitize). Empty tokens fall back to the
	 * stylesheet defaults.
	 *
	 * @return string
	 */
	private function style_vars(): string {
		$tokens = isset( $this->settings['tokens'] ) && is_array( $this->settings['tokens'] ) ? $this->settings['tokens'] : array();
		$vars   = array();
		foreach ( array( 'bg', 'fg', 'accent', 'radius' ) as $key ) {
			$value = trim( (string) ( $tokens[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$vars[] = '--sd-fs-' . $key . ':' . $value;
			}
		}

		return implode( ';', $vars );
	}

	/**
	 * Data for checkout-bar.js (block surfaces).
	 *
	 * @return array
	 */
	private function script_data(): array {
		$cart = ( function_exists( 'WC' ) && isset( WC()->cart ) ) ? WC()->cart : null;
		$copy = $this->copy();

		return array(
			'threshold'        => (float) $this->settings['threshold'],
			'includeTax'       => $cart ? $this->include_tax( $cart ) : false,
			'ignoreDiscounts'  => ! empty( $this->settings['ignore_discounts'] ),
			'hideWhenUnlocked' => ! empty( $this->settings['hide_when_unlocked'] ),
			'copyRemaining'    => $copy['remaining'],
			'copyUnlocked'     => $copy['unlocked'],
			'surface'          => $this->surface(),
			'templateId'       => self::TEMPLATE_ID,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Page detection                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Which surface this request renders, honouring `show_on`. Cached.
	 *
	 * @return string cart | checkout | ''
	 */
	private function surface(): string {
		if ( null !== $this->surface ) {
			return $this->surface;
		}
		$this->surface = '';
		if ( is_admin() || $this->is_order_received() ) {
			return '';
		}

		$queried = ( function_exists( 'is_page' ) && is_page() ) ? get_queried_object() : null;
		$blocks  = '';
		if ( $queried && function_exists( 'has_block' ) ) {
			if ( has_block( 'woocommerce/cart', $queried ) ) {
				$blocks = 'cart';
			} elseif ( has_block( 'woocommerce/checkout', $queried ) ) {
				$blocks = 'checkout';
			}
		}

		if ( '' !== $blocks ) {
			$this->is_blocks = true;
			$this->surface   = $blocks;
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			$this->surface = 'cart';
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$this->surface = 'checkout';
		}

		if ( '' !== $this->surface && ! $this->shows_on( $this->surface ) ) {
			$this->surface = '';
		}

		return $this->surface;
	}

	/**
	 * `show_on` contains the surface.
	 *
	 * @param string $surface cart | checkout.
	 * @return bool
	 */
	private function shows_on( string $surface ): bool {
		$show_on = isset( $this->settings['show_on'] ) && is_array( $this->settings['show_on'] ) ? $this->settings['show_on'] : array();

		return in_array( $surface, $show_on, true );
	}

	/**
	 * Thank-you page.
	 *
	 * @return bool
	 */
	private function is_order_received(): bool {
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
		\StoreDash_Helpers::log_message( 'Free-shipping bar failed: ' . $e->getMessage(), 'error' );
	}
}
