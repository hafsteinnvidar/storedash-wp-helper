<?php
/**
 * Waitlist Placement
 *
 * Puts the waitlist form on the storefront without the merchant editing a
 * template. Three entry points, one renderer:
 *
 *   1. Automatic — injected into `woocommerce_single_product_summary` at the
 *      merchant's chosen priority.
 *   2. Shortcode — `[storedash_waitlist]`, always registered, in either
 *      placement mode. This is the escape hatch for themes where the summary
 *      chain never runs (Elementor Theme Builder product templates, Divi,
 *      Bricks, heavily overridden `content-single-product.php`).
 *   3. Fallback — if automatic placement was selected but nothing rendered by
 *      the time the footer runs, the markup is emitted into a `<template>` and
 *      cloned into the first anchor that exists on the page.
 *
 * The fallback is what makes "Automatic" honest on themes we've never seen. It
 * cannot double-render: any successful render in paths 1 or 2 sets
 * `self::$did_render`, and the fallback is skipped.
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.8.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waitlist Placement Class
 */
class Waitlist_Placement {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'storedash_waitlist';

	/**
	 * Whether the form has already been rendered on this request.
	 *
	 * @var bool
	 */
	private static $did_render = false;

	/**
	 * Whether assets have been enqueued on this request.
	 *
	 * @var bool
	 */
	private static $assets_enqueued = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Registered ahead of Widgets_Manager (priority 10) so the handles
		// exist even when Elementor is not installed — Widgets_Manager is only
		// loaded at all when `elementor/loaded` has fired, so it cannot be the
		// sole registrar. Re-registering an existing handle is a no-op, so the
		// two paths coexist safely.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		// The shortcode is always available, in both placement modes.
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		// Automatic placement. The priority is merchant-configured and clamped
		// to a known-safe set by Waitlist_Config::priority().
		add_action(
			'woocommerce_single_product_summary',
			array( $this, 'render_automatic' ),
			Waitlist_Config::priority()
		);

		// Fallback runs early in the footer so the insertion happens before the
		// main widget script initialises and binds to the inserted DOM.
		add_action( 'wp_footer', array( $this, 'render_fallback' ), 5 );
	}

	/**
	 * Register (not enqueue) the shared waitlist assets.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		if ( ! wp_style_is( 'storedash-waitlist-widget', 'registered' ) ) {
			wp_register_style(
				'storedash-waitlist-widget',
				STOREDASH_URL . 'assets/css/waitlist-widget.css',
				array(),
				STOREDASH_VERSION
			);
		}

		if ( ! wp_script_is( 'storedash-waitlist-widget', 'registered' ) ) {
			wp_register_script(
				'storedash-waitlist-widget',
				STOREDASH_URL . 'assets/js/waitlist-widget.js',
				array(),
				STOREDASH_VERSION,
				true
			);

			wp_localize_script(
				'storedash-waitlist-widget',
				'storedashWaitlist',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'storedash_waitlist_submit' ),
					'strings'  => array(
						'error_general'   => __( 'An error occurred. Please try again.', 'storedash' ),
						'error_duplicate' => __( 'You\'re already on the waitlist for this product.', 'storedash' ),
						'error_email'     => __( 'Please enter a valid email address.', 'storedash' ),
						'error_name'      => __( 'Please enter your name.', 'storedash' ),
						'error_variation' => __( 'Please select a variation.', 'storedash' ),
					),
				)
			);
		}
	}

	/**
	 * Automatic placement on the single product summary.
	 *
	 * @return void
	 */
	public function render_automatic(): void {
		if ( ! Waitlist_Config::is_auto_placement() ) {
			return;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		global $product;

		echo $this->render_for( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Shortcode handler.
	 *
	 * Works in both placement modes and on any page. Outside a product context
	 * an explicit `id` is required, since there is nothing to infer from.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		if ( ! Waitlist_Config::is_active() ) {
			return '';
		}

		$atts = shortcode_atts(
			array( 'id' => 0 ),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$product_id = (int) $atts['id'];

		if ( $product_id > 0 ) {
			$resolved = wc_get_product( $product_id );
		} else {
			global $product;
			$resolved = $product;
		}

		// wc_get_product() returns FALSE (not null) for an unknown id, and the
		// global is unset entirely off a product page — normalise both to null
		// rather than relying on `??`, which only catches null.
		return $this->render_for( $resolved instanceof \WC_Product ? $resolved : null );
	}

	/**
	 * Footer fallback for themes where the summary chain never ran.
	 *
	 * Emits the markup inside a `<template>` — inert until cloned, so it costs
	 * nothing visually and cannot produce a layout shift — plus a small script
	 * that inserts it at the first anchor that exists.
	 *
	 * @return void
	 */
	public function render_fallback(): void {
		if ( self::$did_render ) {
			return;
		}

		if ( ! Waitlist_Config::is_auto_placement() ) {
			return;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		global $product;

		$markup = $this->render_for( $product, true );
		if ( '' === $markup ) {
			return;
		}
		?>
		<template id="storedash-waitlist-fallback"><?php echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template>
		<script>
		(function () {
			var tpl = document.getElementById('storedash-waitlist-fallback');
			if (!tpl) return;

			// The PHP hook may have rendered after this script was printed in
			// an unusual template order; never produce a second form.
			if (document.querySelector('.storedash-waitlist-widget')) return;

			// Ordered [selector, mode] pairs, verified against real themes.
			// mode 'after' inserts as a sibling below the anchor; 'append'
			// inserts inside it at the end.
			//
			// '.stock.out-of-stock' is the highest-value generic anchor: it is
			// WooCommerce's own out-of-stock message (wc_get_stock_html), which
			// nearly every theme and builder widget prints — including Woodmart
			// ('.stock.out-of-stock.wd-style-default') — and "right under the
			// out-of-stock notice" is exactly where a notify-me form belongs.
			// Archive grid labels use '.out-of-stock.product-label' WITHOUT the
			// 'stock' class, so this cannot match a related-products card.
			//
			// '.product' stays as the last resort, but note it matches the
			// WHOLE product section — appending there lands the form below the
			// tabs/related products. Better than nothing, worse than anything
			// above it.
			var anchors = [
				['form.cart', 'after'],
				['.stock.out-of-stock', 'after'],
				['.elementor-widget-woocommerce-product-add-to-cart', 'append'],
				['.wd-single-price', 'after'],
				['.elementor-widget-woocommerce-product-price', 'after'],
				['.summary', 'append'],
				['.entry-summary', 'append'],
				['.product', 'append']
			];

			var host = null;
			var mode = 'append';
			for (var i = 0; i < anchors.length; i++) {
				host = document.querySelector(anchors[i][0]);
				if (host) {
					mode = anchors[i][1];
					break;
				}
			}
			if (!host) return;

			var frag = tpl.content.cloneNode(true);

			if (mode === 'after' && host.parentNode) {
				host.parentNode.insertBefore(frag, host.nextSibling);
			} else {
				host.appendChild(frag);
			}

			// The anti-bot stamp travels inside the template markup, already
			// signed by PHP. Nothing to set here — and nothing here may touch
			// it, since any client-side edit invalidates the HMAC.
		})();
		</script>
		<?php
	}

	/**
	 * Render for a product, enqueueing assets and flagging the request.
	 *
	 * @param \WC_Product|null $product   Product.
	 * @param bool             $is_fallback Whether this is the footer fallback.
	 * @return string
	 */
	private function render_for( $product, bool $is_fallback = false ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		if ( ! Waitlist_Config::is_active() ) {
			return '';
		}

		$stock = Waitlist_Stock::resolve( $product );
		if ( empty( $stock['eligible'] ) ) {
			return '';
		}

		$markup = Waitlist_Renderer::render( Waitlist_Config::get(), $product, $stock );
		if ( '' === $markup ) {
			return '';
		}

		$this->enqueue_assets();

		if ( ! $is_fallback ) {
			self::$did_render = true;
		}

		return $markup;
	}

	/**
	 * Enqueue the shared assets once per request.
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}

		wp_enqueue_style( 'storedash-waitlist-widget' );
		wp_enqueue_script( 'storedash-waitlist-widget' );

		self::$assets_enqueued = true;
	}

	/**
	 * Whether anything rendered on this request. Used by the placement
	 * verifier and by tests.
	 *
	 * @return bool
	 */
	public static function did_render(): bool {
		return self::$did_render;
	}
}

// Initialize
new Waitlist_Placement();
