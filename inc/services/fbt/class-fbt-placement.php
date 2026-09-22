<?php
/**
 * FBT Placement
 *
 * Puts the frequently-bought-together bundle on the storefront without the
 * merchant editing a template. Modeled on Enquiry_Placement — three entry
 * points, one renderer:
 *
 *   1. Summary hook — injected into `woocommerce_single_product_summary` at
 *      the merchant's chosen (clamped) priority. Default: under Add to Cart,
 *      the classic FBT spot.
 *   2. After summary — `woocommerce_after_single_product_summary` priority 5,
 *      below the buy area but above WooCommerce's related products (which
 *      render at priority 20).
 *   3. Shortcode — `[storedash_fbt]`, always registered, in either placement
 *      mode.
 *   4. Fallback — if automatic placement was selected but nothing rendered by
 *      the time the footer runs (builder themes where the summary chain never
 *      fires), the markup is emitted into a `<template>` and cloned in after
 *      the add-to-cart form.
 *
 * Unlike the enquiry service there is NO product-tab position: a bundle upsell
 * hidden behind a tab click defeats its purpose.
 *
 * Eligibility is per-product and free: does the product carry bundle meta
 * (`storedash_fbt_ids` / `woobt_ids`)? There is no scope config — the bundle
 * meta IS the scope.
 *
 * @package StoreDash\Services\FBT
 * @since   1.12.0
 */

namespace StoreDash\Services\FBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FBT Placement Class
 */
class FBT_Placement {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'storedash_fbt';

	/**
	 * Whether the bundle has already been rendered on this request.
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
	 * Product resolved for the current product page, if any.
	 *
	 * @var \WC_Product|null
	 */
	private static $page_product = null;

	/**
	 * Whether automatic placement will render on this page.
	 *
	 * @var bool
	 */
	private static $page_eligible = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		// Resolve eligibility and enqueue while the head is still open, so the
		// stylesheet never lands in the footer and flashes an unstyled bundle.
		add_action( 'wp_enqueue_scripts', array( $this, 'prepare_page' ), 20 );

		// The shortcode is always available, in both placement modes.
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		// Automatic placement — summary hook at merchant-configured priority.
		add_action(
			'woocommerce_single_product_summary',
			array( $this, 'render_summary' ),
			FBT_Config::priority()
		);

		// Automatic placement — below the summary, above related products
		// (WooCommerce renders related/upsells at priority 15–20).
		add_action(
			'woocommerce_after_single_product_summary',
			array( $this, 'render_after_summary' ),
			5
		);

		// Fallback runs early in the footer so insertion happens before the
		// widget script initialises and binds to the inserted DOM.
		add_action( 'wp_footer', array( $this, 'render_fallback' ), 5 );
	}

	/**
	 * Register (not enqueue) the shared FBT assets.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		if ( ! wp_style_is( 'storedash-fbt-widget', 'registered' ) ) {
			wp_register_style(
				'storedash-fbt-widget',
				STOREDASH_URL . 'assets/css/fbt-widget.css',
				array(),
				STOREDASH_VERSION
			);
		}

		if ( ! wp_script_is( 'storedash-fbt-widget', 'registered' ) ) {
			wp_register_script(
				'storedash-fbt-widget',
				STOREDASH_URL . 'assets/js/fbt-widget.js',
				array(),
				STOREDASH_VERSION,
				true
			);

			wp_localize_script(
				'storedash-fbt-widget',
				'storedashFbt',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'storedash_fbt_add_bundle' ),
					'cart_url' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
					'strings'  => array(
						'error_general' => __( 'Could not add to cart. Please try again.', 'storedash' ),
					),
				)
			);
		}
	}

	/**
	 * Resolve the product and eligibility for this page, and enqueue if needed.
	 *
	 * @return void
	 */
	public function prepare_page(): void {
		if ( ! FBT_Config::is_auto_placement() ) {
			return;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		self::$page_product  = $product;
		self::$page_eligible = FBT_Data::has_bundle_meta( $product->get_id() );

		if ( self::$page_eligible ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Summary placement.
	 *
	 * @return void
	 */
	public function render_summary(): void {
		if ( ! self::$page_eligible ) {
			return;
		}

		if ( FBT_Config::POSITION_SUMMARY !== FBT_Config::position() ) {
			return;
		}

		echo $this->render_for( self::$page_product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * After-summary placement.
	 *
	 * @return void
	 */
	public function render_after_summary(): void {
		if ( ! self::$page_eligible ) {
			return;
		}

		if ( FBT_Config::POSITION_AFTER_SUMMARY !== FBT_Config::position() ) {
			return;
		}

		echo $this->render_for( self::$page_product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Shortcode handler.
	 *
	 * Works in both placement modes. Outside a product context an explicit
	 * `id` is required, since there is nothing to infer from.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		if ( ! FBT_Config::is_active() ) {
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

		// wc_get_product() returns FALSE for an unknown id, and the global is
		// unset off a product page — normalise both to null.
		if ( ! $resolved instanceof \WC_Product ) {
			return '';
		}

		return $this->render_for( $resolved );
	}

	/**
	 * Footer fallback for themes that never run the summary chain.
	 *
	 * Emits the markup inside an inert `<template>` plus a small script that
	 * inserts it at the first anchor that exists. Unlike the enquiry fallback
	 * this inserts the full bundle, not a trigger button — FBT has no modal
	 * form, and a bundle below the add-to-cart form is its natural spot.
	 *
	 * @return void
	 */
	public function render_fallback(): void {
		if ( self::$did_render ) {
			return;
		}

		if ( ! self::$page_eligible ) {
			return;
		}

		$markup = $this->render_for( self::$page_product, true );
		if ( '' === $markup ) {
			return;
		}
		?>
		<template id="storedash-fbt-fallback"><?php echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template>
		<script>
		(function () {
			var tpl = document.getElementById('storedash-fbt-fallback');
			if (!tpl) return;

			// The PHP hook may have rendered after this script was printed in
			// an unusual template order; never produce a second bundle.
			if (document.querySelector('.storedash-fbt-widget-container')) return;

			// Ordered [selector, mode] pairs. 'after' inserts as a sibling
			// below the anchor; 'append' inserts inside it at the end.
			// 'form.cart' is WooCommerce's add-to-cart form — present on every
			// purchasable product in every theme, and the bundle's natural
			// neighbour.
			var anchors = [
				['form.cart', 'after'],
				['.elementor-widget-woocommerce-product-add-to-cart', 'append'],
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
		})();
		</script>
		<?php
	}

	/**
	 * Render for a product, enqueueing assets and flagging the request.
	 *
	 * @param \WC_Product|null $product     Product.
	 * @param bool             $is_fallback Whether this is the footer fallback.
	 * @return string
	 */
	private function render_for( $product, bool $is_fallback = false ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		if ( ! FBT_Config::is_active() ) {
			return '';
		}

		$markup = FBT_Renderer::render( FBT_Config::get(), $product );
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

		wp_enqueue_style( 'storedash-fbt-widget' );
		wp_enqueue_script( 'storedash-fbt-widget' );

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
new FBT_Placement();
