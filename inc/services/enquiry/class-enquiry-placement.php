<?php
/**
 * Enquiry Placement
 *
 * Puts the enquiry form on the storefront without the merchant editing a
 * template. Four entry points, one renderer:
 *
 *   1. Product tab — an "Ask a question" tab via the `woocommerce_product_tabs`
 *      filter. This is the DEFAULT, and it is a filter rather than a template
 *      hook, which is why it survives Elementor Theme Builder, Woodmart, Divi
 *      and Bricks where `woocommerce_single_product_summary` frequently never
 *      runs at all. Verified rendering on hreysti.is (Woodmart + Elementor),
 *      the store where summary-chain placement failed.
 *   2. Summary hook — a trigger button injected into
 *      `woocommerce_single_product_summary` at the merchant's chosen priority,
 *      for merchants who want the enquiry prompt up in the buy area.
 *   3. Shortcode — `[storedash_enquiry]`, always registered, in either
 *      placement mode.
 *   4. Fallback — if automatic placement was selected but nothing rendered by
 *      the time the footer runs, a trigger button is emitted into a `<template>`
 *      and cloned into the first anchor that exists.
 *
 * The fallback is what makes "Automatic" honest on themes we've never seen, and
 * unlike the waitlist's it has a precise trigger rather than a heuristic: our
 * tab callback runs at `woocommerce_after_single_product_summary` priority 10,
 * long before `wp_footer`, so a still-false `$did_render` in the footer is a
 * fact, not a guess.
 *
 * It cannot double-render: any successful render in paths 1–3 sets
 * `self::$did_render`, and the fallback is skipped.
 *
 * @package StoreDash\Services\Enquiry
 * @since   1.9.0
 */

namespace StoreDash\Services\Enquiry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enquiry Placement Class
 */
class Enquiry_Placement {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'storedash_enquiry';

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
	 * Product resolved for the current product page, if any.
	 *
	 * Resolved once at `wp_enqueue_scripts` so that eligibility is known before
	 * the document head is printed, and reused by every render path — the
	 * alternative is hydrating the product a second time mid-render.
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
		// Registered ahead of Widgets_Manager (priority 10) so the handles
		// exist even when Elementor is not installed — Widgets_Manager is only
		// loaded at all when `elementor/loaded` has fired, so it cannot be the
		// sole registrar. Re-registering an existing handle is a no-op, so the
		// two paths coexist safely.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		// Resolve eligibility and enqueue while the head is still open. Doing
		// this at render time instead would push the stylesheet into the footer
		// and flash an unstyled form inside the tab.
		add_action( 'wp_enqueue_scripts', array( $this, 'prepare_page' ), 20 );

		// The shortcode is always available, in both placement modes.
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		// Automatic placement — product tab.
		add_filter( 'woocommerce_product_tabs', array( $this, 'register_tab' ), 98 );

		// Automatic placement — summary hook. The priority is merchant
		// configured and clamped to a known-safe set by Enquiry_Config.
		add_action(
			'woocommerce_single_product_summary',
			array( $this, 'render_summary' ),
			Enquiry_Config::priority()
		);

		// Fallback runs early in the footer so the insertion happens before the
		// main widget script initialises and binds to the inserted DOM.
		add_action( 'wp_footer', array( $this, 'render_fallback' ), 5 );
	}

	/**
	 * Register (not enqueue) the shared enquiry assets.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		if ( ! wp_style_is( 'storedash-enquiry-widget', 'registered' ) ) {
			wp_register_style(
				'storedash-enquiry-widget',
				STOREDASH_URL . 'assets/css/enquiry-widget.css',
				array(),
				STOREDASH_VERSION
			);
		}

		if ( ! wp_script_is( 'storedash-enquiry-widget', 'registered' ) ) {
			wp_register_script(
				'storedash-enquiry-widget',
				STOREDASH_URL . 'assets/js/enquiry-widget.js',
				array(),
				STOREDASH_VERSION,
				true
			);

			wp_localize_script(
				'storedash-enquiry-widget',
				'storedashEnquiry',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'storedash_enquiry_submit' ),
					'strings'  => array(
						'error_general' => __( 'An error occurred. Please try again.', 'storedash' ),
						'error_email'   => __( 'Please enter a valid email address.', 'storedash' ),
						'error_name'    => __( 'Please enter your name.', 'storedash' ),
						'error_message' => __( 'Please enter a message.', 'storedash' ),
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
		if ( ! Enquiry_Config::is_auto_placement() ) {
			return;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		// The `$product` global is not populated until `the_post` fires inside
		// the loop, which is after this hook. The queried object is the product
		// on a single-product view, and its post/meta caches were primed by the
		// main query, so this hydration is cheap.
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		self::$page_product  = $product;
		self::$page_eligible = Enquiry_Scope::is_eligible( $product );

		if ( self::$page_eligible ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Add the "Ask a question" tab.
	 *
	 * @param array $tabs Registered product tabs.
	 * @return array
	 */
	public function register_tab( $tabs ): array {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		if ( ! self::$page_eligible ) {
			return $tabs;
		}

		if ( Enquiry_Config::POSITION_TAB !== Enquiry_Config::position() ) {
			return $tabs;
		}

		$tabs['storedash_enquiry'] = array(
			'title'    => Enquiry_Renderer::copy( Enquiry_Config::get(), 'tab_label' ),
			'priority' => 50,
			'callback' => array( $this, 'render_tab_content' ),
		);

		return $tabs;
	}

	/**
	 * Render the tab body.
	 *
	 * @return void
	 */
	public function render_tab_content(): void {
		echo $this->render_for( self::$page_product, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Summary placement — a trigger button in the buy area.
	 *
	 * @return void
	 */
	public function render_summary(): void {
		if ( ! self::$page_eligible ) {
			return;
		}

		if ( Enquiry_Config::POSITION_SUMMARY !== Enquiry_Config::position() ) {
			return;
		}

		echo $this->render_for( self::$page_product, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Shortcode handler.
	 *
	 * Works in both placement modes and on any page. Outside a product context
	 * an explicit `id` is required, since there is nothing to infer from.
	 *
	 * Renders inline by default — a shortcode is placed deliberately, so the
	 * merchant already chose the spot. `display="button"` opts into the modal.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		if ( ! Enquiry_Config::is_active() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'display' => 'inline',
			),
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
		if ( ! $resolved instanceof \WC_Product ) {
			return '';
		}

		// The shortcode honours scope too: a merchant who excluded a category
		// would not expect the form to reappear because the theme happens to
		// run the shortcode inside a template.
		if ( ! Enquiry_Scope::is_eligible( $resolved ) ) {
			return '';
		}

		return $this->render_for( $resolved, 'button' === $atts['display'] );
	}

	/**
	 * Footer fallback for themes that render neither product tabs nor the
	 * summary chain.
	 *
	 * Emits the markup inside a `<template>` — inert until cloned, so it costs
	 * nothing visually and cannot produce a layout shift — plus a small script
	 * that inserts it at the first anchor that exists.
	 *
	 * Always renders the trigger button, never an inline form: if the anchor
	 * list picks a suboptimal host, a stray button is a cosmetic problem while
	 * a stray form is a broken page.
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

		$markup = $this->render_for( self::$page_product, true, true );
		if ( '' === $markup ) {
			return;
		}
		?>
		<template id="storedash-enquiry-fallback"><?php echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template>
		<script>
		(function () {
			var tpl = document.getElementById('storedash-enquiry-fallback');
			if (!tpl) return;

			// The PHP hook may have rendered after this script was printed in
			// an unusual template order; never produce a second form.
			if (document.querySelector('.storedash-enquiry-widget')) return;

			// Ordered [selector, mode] pairs. mode 'after' inserts as a sibling
			// below the anchor; 'append' inserts inside it at the end.
			//
			// The waitlist's best generic anchor, '.stock.out-of-stock', is
			// useless here: it only exists on out-of-stock products, and the
			// enquiry form renders on all of them. 'form.cart' is the enquiry
			// equivalent — WooCommerce's add-to-cart form, present on every
			// purchasable product in every theme.
			//
			// '.product' stays as the last resort, but note it matches the
			// WHOLE product section — appending there lands the button below
			// the tabs and related products. Better than nothing, worse than
			// anything above it.
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
	 * @param \WC_Product|null $product     Product.
	 * @param bool             $modal       Render a trigger button plus modal.
	 * @param bool             $is_fallback Whether this is the footer fallback.
	 * @return string
	 */
	private function render_for( $product, bool $modal, bool $is_fallback = false ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		if ( ! Enquiry_Config::is_active() ) {
			return '';
		}

		$markup = Enquiry_Renderer::render( Enquiry_Config::get(), $product, $modal );
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

		wp_enqueue_style( 'storedash-enquiry-widget' );
		wp_enqueue_script( 'storedash-enquiry-widget' );

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
new Enquiry_Placement();
