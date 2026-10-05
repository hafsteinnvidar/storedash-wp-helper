<?php
/**
 * Cart / Checkout blocks UI for gift cards.
 *
 * A dependency-free script (`assets/js/gift-card-blocks.js`, no build step)
 * fills the blocks' DiscountsMeta slot with a code field and calls
 * `wc.blocksCheckout.extensionCartUpdate` against the `storedash_gift_card`
 * Store API namespace, so totals update live — the same path headless
 * storefronts use.
 *
 * @package StoreDash\GiftCard\Checkout
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\GiftCard\Settings;

/**
 * Blocks script loader.
 *
 * @since 1.24.0
 */
class Blocks_Checkout {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 20 );
	}

	/**
	 * Enqueue on pages that render the Cart or Checkout block.
	 */
	public function enqueue_scripts(): void {
		if ( ! Settings::is_enabled() || ! function_exists( 'has_block' ) ) {
			return;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! ( has_block( 'woocommerce/checkout', $post_id ) || has_block( 'woocommerce/cart', $post_id ) ) ) {
			return;
		}

		wp_enqueue_script(
			'storedash-gift-card-blocks',
			STOREDASH_URL . 'assets/js/gift-card-blocks.js',
			array( 'wp-plugins', 'wp-element', 'wc-blocks-checkout' ),
			STOREDASH_VERSION,
			true
		);

		wp_localize_script(
			'storedash-gift-card-blocks',
			'storedash_gift_card_blocks',
			array(
				'title'       => __( 'Gift card', 'storedash' ),
				'placeholder' => __( 'Gift card code', 'storedash' ),
				'apply'       => __( 'Apply', 'storedash' ),
				'remove'      => __( 'Remove', 'storedash' ),
				/* translators: 1: last 4 characters of the code, 2: amount applied */
				'cardLabel'   => __( '····%1$s: %2$s applied', 'storedash' ),
				'notAllowed'  => __( 'Gift cards cannot be used to buy gift cards.', 'storedash' ),
				'invalid'     => __( 'This gift card code is not valid.', 'storedash' ),
			)
		);
	}
}
