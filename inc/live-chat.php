<?php
/**
 * Live Chat widget injection for StoreDash
 *
 * Always injects the widget script tag — the widget itself checks
 * if chat is enabled during session/start. Zero impact on page load speed.
 *
 * @package StoreDash
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Live_Chat {

	private $chat_url;

	/**
	 * Store ID resolved for the current page (used by the attribute filter).
	 *
	 * @var string
	 */
	private $enqueued_store_id = '';

	/**
	 * Chat base URL resolved for the current page (used by the attribute filter).
	 *
	 * @var string
	 */
	private $enqueued_chat_url = '';

	public function __construct() {
		$this->chat_url = defined( 'STOREDASH_CHAT_URL' )
			? STOREDASH_CHAT_URL
			: get_option( 'storedash_live_chat_url', 'https://storedash-chat-service-5nte2.ondigitalocean.app' );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_widget' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_cart_bridge' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_widget_attributes' ), 10, 2 );
	}

	/**
	 * Resolve the store ID from either the current or legacy (pre-rename) option,
	 * so the widget is not silently dead on installs that only set woodash_store_id.
	 *
	 * @return string Store ID, or '' when unset.
	 */
	private function get_store_id() {
		$store_id = (string) get_option( 'storedash_store_id', '' );
		if ( '' === $store_id ) {
			$store_id = (string) get_option( 'woodash_store_id', '' );
		}
		return $store_id;
	}

	/**
	 * Whether the live-chat widget should load. Filterable so a merchant (or the
	 * dashboard) can disable it site-wide.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return (bool) apply_filters( 'storedash_live_chat_enabled', true );
	}

	/**
	 * Enqueue the chat widget loader as a proper external script.
	 *
	 * Enqueuing (instead of printing a raw <script> tag) is required by the
	 * WordPress.org Plugin Check and lets other plugins/caches manage the handle.
	 * The widget JS itself checks whether chat is enabled during session start and
	 * shows/hides itself.
	 */
	public function enqueue_widget() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		$store_id = $this->get_store_id();
		if ( '' === $store_id ) {
			return;
		}

		$chat_url = rtrim( $this->chat_url, '/' );

		$this->enqueued_store_id = $store_id;
		$this->enqueued_chat_url = $chat_url;

		wp_enqueue_script(
			'storedash-live-chat',
			$chat_url . '/widget/chat-widget.js',
			array(),
			STOREDASH_VERSION,
			true
		);
	}

	/**
	 * Add defer + the widget's data attributes to the enqueued loader tag.
	 *
	 * Done via script_loader_tag (rather than the WP 6.3 strategy/attributes API)
	 * to keep the WordPress 5.8 compatibility floor.
	 *
	 * @param string $tag    The full <script> tag.
	 * @param string $handle The registered script handle.
	 * @return string
	 */
	public function add_widget_attributes( $tag, $handle ) {
		if ( 'storedash-live-chat' !== $handle ) {
			return $tag;
		}

		$attributes = sprintf(
			' defer data-store-id="%s" data-chat-url="%s"',
			esc_attr( $this->enqueued_store_id ),
			esc_url( $this->enqueued_chat_url )
		);

		return str_replace( ' src=', $attributes . ' src=', $tag );
	}

	/**
	 * Enqueue the cart bridge script that listens for chat widget postMessages
	 * and calls the WooCommerce Store API to add items to cart.
	 *
	 * Security: The WooCommerce Store API nonce is validated server-side by WooCommerce.
	 * No prices or discounts are ever sent from the chat — WooCommerce resolves everything.
	 */
	public function enqueue_cart_bridge() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! function_exists( 'WC' ) ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		$store_id = $this->get_store_id();
		if ( '' === $store_id ) {
			return;
		}

		wp_enqueue_script(
			'storedash-chat-cart-bridge',
			STOREDASH_URL . 'assets/js/chat-cart-bridge.js',
			array(),
			STOREDASH_VERSION,
			true
		);

		wp_localize_script(
			'storedash-chat-cart-bridge',
			'storedashChatBridge',
			array(
				'nonce'        => wp_create_nonce( 'wc_store_api' ),
				'storeApiBase' => esc_url_raw( get_rest_url( null, 'wc/store/v1' ) ),
			)
		);
	}
}

new StoreDash_Live_Chat();
