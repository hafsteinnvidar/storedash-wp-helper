<?php
/**
 * Minimal WordPress stubs for the standalone opt-in handler tests.
 *
 * Options, WC_Order and StoreDash_Helpers come from tests/bootstrap.php; this
 * file adds the HTTP + escaping surface the handler touches and records the
 * outbound webhook POST instead of sending it.
 *
 * @package StoreDash\Tests
 */

if ( ! class_exists( 'Optin_Test_WP' ) ) {
	/**
	 * Shared in-memory state for the opt-in tests.
	 */
	class Optin_Test_WP {
		/** @var array<int, array{url: string, args: array}> */
		public static $posts = array();
		/** @var array<string, array> */
		public static $hooks = array();
		/** @var array<string, mixed> */
		public static $user_meta = array();

		public static function reset(): void {
			$GLOBALS['__seo_options'] = array();
			self::$posts              = array();
			self::$user_meta          = array();
			$_POST                    = array();
		}
	}
}

if ( ! defined( 'STOREDASH_VERSION' ) ) {
	define( 'STOREDASH_VERSION', '0.0.0-test' );
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {
		Optin_Test_WP::$hooks[ $hook ][] = array( 'callback' => $callback, 'priority' => $priority );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		Optin_Test_WP::$posts[] = array( 'url' => $url, 'args' => $args );
		return array();
	}
}
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url() {
		return 'https://shop.test';
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return 'uuid-test';
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		Optin_Test_WP::$user_meta[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ) {
		$out = (string) $checked === (string) $current ? " checked='checked'" : '';
		if ( $echo ) {
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $out;
	}
}

require_once __DIR__ . '/../../inc/services/optin/class-optin-handler.php';
require_once __DIR__ . '/../../inc/services/optin/class-blocks-optin-field.php';
require_once __DIR__ . '/../../inc/services/optin/class-blocks-sms-optin-field.php';
