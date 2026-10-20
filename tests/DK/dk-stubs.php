<?php
/**
 * Minimal WordPress/WooCommerce stubs for the standalone DK service tests.
 *
 * Options use the shared store in tests/bootstrap.php
 * (`$GLOBALS['__seo_options']`) so DK_Config can be exercised end to end
 * without a database. Hooks are recorded (not fired) so a test can assert
 * what DK_Kennitala_Field registered.
 *
 * @package StoreDash\Tests
 */

if ( ! class_exists( 'DK_Test_WP' ) ) {
	/**
	 * Shared in-memory WP state for the DK tests.
	 */
	class DK_Test_WP {
		/** @var array<string,array> */
		public static $hooks = array();
		/** @var array<string,string> */
		public static $post = array();
		/** @var int */
		public static $user_id = 0;
		/** @var array<string,string> */
		public static $user_meta = array();

		public static function reset(): void {
			$GLOBALS['__seo_options'] = array();
			self::$hooks              = array();
			self::$post               = array();
			self::$user_id            = 0;
			self::$user_meta          = array();
			$_POST                    = array();
		}
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {
		DK_Test_WP::$hooks[ $hook ][] = array( 'callback' => $callback, 'priority' => $priority );
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) {
		DK_Test_WP::$hooks[ $hook ][] = array( 'callback' => $callback, 'priority' => $priority );
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $callback = false ) {
		foreach ( DK_Test_WP::$hooks[ $hook ] ?? array() as $entry ) {
			if ( $entry['callback'] === $callback ) {
				return $entry['priority'];
			}
		}
		return false;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return addslashes( (string) $text );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return DK_Test_WP::$user_id;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return DK_Test_WP::$user_meta[ $key ] ?? '';
	}
}
if ( ! class_exists( 'DK_Test_Errors' ) ) {
	class DK_Test_Errors {
		/** @var array<string,string> */
		public $errors = array();
		public function __construct( $code = '', $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ] = $message;
			}
		}
		public function add( $code, $message ) {
			$this->errors[ $code ] = $message;
		}
		public function get_error_codes() {
			return array_keys( $this->errors );
		}
	}
}

require_once __DIR__ . '/../../inc/services/dk/class-dk-config.php';
require_once __DIR__ . '/../../inc/services/dk/class-dk-kennitala.php';
