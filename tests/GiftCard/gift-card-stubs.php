<?php
/**
 * Shared WordPress / WooCommerce stubs + class loading for the GiftCard suites.
 *
 * @package StoreDash\Tests\GiftCard
 */

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'wc_add_number_precision' ) ) {
	function wc_add_number_precision( $value, $round = true ) {
		$result = (float) $value * pow( 10, wc_get_price_decimals() );
		return $round ? round( $result, 6 ) : $result;
	}
}

if ( ! function_exists( 'wc_remove_number_precision' ) ) {
	function wc_remove_number_precision( $value ) {
		return (float) $value / pow( 10, wc_get_price_decimals() );
	}
}

// Post meta for the gift card product flag: `__test_post_meta[id][key]`.
if ( ! isset( $GLOBALS['__test_post_meta'] ) ) {
	$GLOBALS['__test_post_meta'] = array();
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		return $GLOBALS['__test_post_meta'][ $post_id ][ $key ] ?? '';
	}
}

$storedash_gc_root = __DIR__ . '/../../inc/';
require_once $storedash_gc_root . 'Credit/Money.php';
require_once $storedash_gc_root . 'GiftCard/Settings.php';
require_once $storedash_gc_root . 'GiftCard/Code.php';
require_once $storedash_gc_root . 'GiftCard/Serializer.php';
require_once $storedash_gc_root . 'GiftCard/Card_Ledger.php';
require_once $storedash_gc_root . 'GiftCard/Gift_Card_Webhook.php';
require_once $storedash_gc_root . 'GiftCard/Product/Gift_Card_Product.php';
require_once $storedash_gc_root . 'GiftCard/Product/Recipient_Fields.php';
require_once $storedash_gc_root . 'GiftCard/Engine/Fee_Allocator.php';
require_once $storedash_gc_root . 'GiftCard/Engine/Rate_Limiter.php';
require_once $storedash_gc_root . 'GiftCard/Engine/Redemption.php';
require_once $storedash_gc_root . 'GiftCard/Engine/Reservation.php';
require_once $storedash_gc_root . 'GiftCard/Engine/Refund_Handler.php';
require_once $storedash_gc_root . 'GiftCard/Engine/Issuer.php';
require_once $storedash_gc_root . 'GiftCard/Blocks/Store_API_Integration.php';
