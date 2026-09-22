<?php
/**
 * Minimal PHPUnit bootstrap for standalone (non-WordPress) unit tests.
 *
 * The discount engine classes guard on ABSPATH and type-hint a handful of
 * WooCommerce classes. For pure-logic unit tests we stub just enough of that
 * surface so the class under test can load and run without a full WordPress
 * test install.
 *
 * @package StoreDash\Tests
 */

// Engine files `exit` unless ABSPATH is defined.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Stub of WC_Product — only the methods the resolver touches.
if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {

		/** @var int */
		protected $id;
		/** @var int */
		public $parent_id = 0;
		/** @var string */
		public $regular_price = '';
		/** @var string */
		public $sale_price = '';
		/** @var string */
		public $price = '';
		/** @var string */
		public $type = 'simple';

		public function __construct( $id = 0 ) {
			$this->id = (int) $id;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_parent_id() {
			return $this->parent_id;
		}

		public function get_regular_price() {
			return $this->regular_price;
		}

		public function get_sale_price() {
			return $this->sale_price;
		}

		public function get_price() {
			return $this->price;
		}

		public function set_price( $price ) {
			$this->price = (string) $price;
		}

		public function is_type( $type ) {
			return $this->type === $type;
		}
	}
}

// Cart double + WC() accessor, controllable per-test via the global below. The
// resolver's cart-quantity gate derives its count from cart CONTENTS (paid
// lines only — BOGO free-item lines are skipped);
// leaving the global null models "no cart" (display time).
if ( ! isset( $GLOBALS['__test_wc_cart'] ) ) {
	$GLOBALS['__test_wc_cart'] = null;
}

if ( ! class_exists( 'Fake_WC_Cart' ) ) {
	class Fake_WC_Cart {

		/** @var int */
		public $count = 0;
		/** @var float */
		public $subtotal = 0.0;
		/** @var array */
		public $cart_contents = array();
		/** @var array */
		public $applied_coupons = array();
		/** @var array Captured add_to_cart() calls. */
		public $added = array();
		/** @var array Captured remove_cart_item() calls. */
		public $removed = array();

		public function get_cart_contents_count() {
			return $this->count;
		}

		public function get_subtotal() {
			return $this->subtotal;
		}

		public function get_cart() {
			return $this->cart_contents;
		}

		public function get_applied_coupons() {
			return $this->applied_coupons;
		}

		public function set_quantity( $key, $qty, $refresh = true ) {
			if ( isset( $this->cart_contents[ $key ] ) ) {
				$this->cart_contents[ $key ]['quantity'] = $qty;
			}
		}

		public function remove_cart_item( $key ) {
			$this->removed[] = $key;
			unset( $this->cart_contents[ $key ] );
		}

		public function add_to_cart( $product_id, $qty = 1, $variation_id = 0, $variation = array(), $data = array() ) {
			$this->added[] = compact( 'product_id', 'qty', 'variation_id', 'variation', 'data' );
			return 'added_' . count( $this->added );
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * Minimal WC() accessor returning an object whose `cart` is the test double.
	 *
	 * @return object
	 */
	function WC() {
		return (object) array( 'cart' => $GLOBALS['__test_wc_cart'] );
	}
}

if ( ! isset( $GLOBALS['__test_price_decimals'] ) ) {
	$GLOBALS['__test_price_decimals'] = 2;
}

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() {
		return $GLOBALS['__test_price_decimals'];
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

// Single authoritative stub of the plugin logger, shared by every suite so their
// own class_exists()-guarded stubs stand down and no test-ordering fragility
// remains. It is the union of the surface the suites use: capturing log_message,
// get_server_var, and a no-op debug_log.
if ( ! class_exists( 'StoreDash_Helpers' ) ) {
	class StoreDash_Helpers {

		/** @var array Captured log_message() calls. Reset in each suite's setUp. */
		public static $logs = array();

		public static function log_message( $message, $level = 'error', array $context = array() ) {
			self::$logs[] = compact( 'message', 'level', 'context' );
		}

		public static function get_server_var( $key, $type = 'text' ) {
			return isset( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : '';
		}

		public static function debug_log( $message, $context = array() ) {}
	}
}

// Taxonomy stubs for the matcher. Tests drive them through these globals:
// `__test_product_terms` maps product_id => taxonomy => term IDs, and
// `__test_term_children` maps taxonomy => parent term ID => descendant IDs
// (get_term_children() returns ALL descendants, not just direct children).
if ( ! isset( $GLOBALS['__test_product_terms'] ) ) {
	$GLOBALS['__test_product_terms'] = array();
}

if ( ! isset( $GLOBALS['__test_term_children'] ) ) {
	$GLOBALS['__test_term_children'] = array();
}

if ( ! isset( $GLOBALS['__test_hierarchical_taxonomies'] ) ) {
	$GLOBALS['__test_hierarchical_taxonomies'] = array( 'product_cat' );
}

// Product registry for wc_get_product(), keyed by product ID.
if ( ! isset( $GLOBALS['__test_products'] ) ) {
	$GLOBALS['__test_products'] = array();
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product_id ) {
		return $GLOBALS['__test_products'][ $product_id ] ?? false;
	}
}

if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
	function wc_get_product_term_ids( $product_id, $taxonomy ) {
		return $GLOBALS['__test_product_terms'][ $product_id ][ $taxonomy ] ?? array();
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_brand' ), true );
	}
}

if ( ! function_exists( 'is_taxonomy_hierarchical' ) ) {
	function is_taxonomy_hierarchical( $taxonomy ) {
		return in_array( $taxonomy, $GLOBALS['__test_hierarchical_taxonomies'], true );
	}
}

if ( ! function_exists( 'get_term_children' ) ) {
	function get_term_children( $term_id, $taxonomy ) {
		return $GLOBALS['__test_term_children'][ $taxonomy ][ $term_id ] ?? array();
	}
}

// Class under test (required directly; no WordPress autoloader needed).
require_once __DIR__ . '/../inc/Discounts/Engine/Discount_Priority_Resolver.php';
require_once __DIR__ . '/../inc/Discounts/Engine/Discount_Resolver.php';
require_once __DIR__ . '/../inc/Discounts/Engine/Cart_Discount_Orchestrator.php';
require_once __DIR__ . '/../inc/Discounts/Engine/Trait_Sale_Check.php';
require_once __DIR__ . '/../inc/Discounts/Engine/Discount_Matcher.php';
require_once __DIR__ . '/../inc/Discounts/Engine/Dynamic_Price_Display.php';

// i18n + price-formatting stubs for the Store API / tier-table suites.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	function wc_price( $price ) {
		return '$' . number_format( (float) $price, 2 );
	}
}

// WP_Error + helpers shared by the Credit suites (and any suite that needs
// them). Richer than the per-suite stubs so it must load first — it does,
// because bootstrap runs before any test file.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/** @var mixed */
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
