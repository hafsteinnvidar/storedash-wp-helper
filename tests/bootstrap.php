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

		/** Outbound webhooks are never sent from unit tests. */
		public static function is_store_connected(): bool {
			return false;
		}

		// Opt-in handler surface: mirror the real option-backed resolution so
		// the SMS/e-mail checkout tests exercise the same fallback rules.
		public static function resolve_webhook_url( $option, $default ) {
			$configured = get_option( $option, null );
			if ( null === $configured || false === $configured ) {
				return $default;
			}
			return trim( (string) $configured );
		}

		public static function checkout_optin_label(): string {
			$configured = trim( (string) get_option( 'woodash_checkout_optin_label', '' ) );
			return '' === $configured ? 'I want to receive promotional emails with sales, new products, and special offers' : $configured;
		}

		public static function checkout_sms_optin_label(): string {
			$configured = trim( (string) get_option( 'woodash_checkout_sms_optin_label', '' ) );
			return '' === $configured ? 'Send me offers and news by SMS' : $configured;
		}

		public static function get_client_ip() {
			return '127.0.0.1';
		}
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

// WP_REST_Response double shared by the REST controller suites (Seo catalog,
// shipment tracking). Superset of the per-suite stubs: data + status + headers.
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		/** @var mixed */
		public $data;
		/** @var int */
		public $status;
		/** @var array */
		public $headers = array();

		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data    = $data;
			$this->status  = (int) $status;
			$this->headers = is_array( $headers ) ? $headers : array();
		}

		public function header( $key, $value, $replace = true ) {
			$this->headers[ $key ] = $value;
		}

		public function get_headers() {
			return $this->headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

// Single option store + clock shared by every suite (SEO, DK, …). Each suite
// used to stub get_option()/update_option() over its own storage; whichever
// file loaded first won and the other suite read the wrong store in a full
// run. Tests set/reset `$GLOBALS['__seo_options']` directly.
$GLOBALS['__seo_options']         = array();
$GLOBALS['__seo_option_autoload'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__seo_options'] ) ? $GLOBALS['__seo_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__seo_options'][ $name ] = $value;
		if ( null !== $autoload ) {
			$GLOBALS['__seo_option_autoload'][ $name ] = $autoload;
		}
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $name, $GLOBALS['__seo_options'] ) ) {
			return false;
		}
		$GLOBALS['__seo_options'][ $name ]         = $value;
		$GLOBALS['__seo_option_autoload'][ $name ] = $autoload;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['__seo_options'][ $name ], $GLOBALS['__seo_option_autoload'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		return '2026-10-19 12:00:00';
	}
}

// Single WC_Order double + wc_get_order(), the union of what the DK, GiftCard
// and ShipmentTracking suites use (each used to define its own; the first
// loaded won). wc_get_order() passes objects through and resolves ids to
// `$GLOBALS['__test_wc_order']`.
$GLOBALS['__test_wc_order'] = null;

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order ) {
		return is_object( $order ) ? $order : ( $GLOBALS['__test_wc_order'] ?? false );
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order { // phpcs:ignore
		public $id              = 0;
		public $status          = 'processing';
		public $fees            = array();
		public $goods           = 0.0;
		public $total           = 0.0;
		public $notes           = array();
		public $meta            = array();
		public $statuses        = array();
		public $billing_country = 'IS';
		public $saves           = 0;

		public function get_id() {
			return $this->id;
		}
		public function get_items( $type ) {
			return 'fee' === $type ? $this->fees : array();
		}
		public function remove_item( $item_id ) {
			unset( $this->fees[ $item_id ] );
		}
		public function calculate_totals( $and_taxes = true ) {
			$this->total = $this->goods;
			foreach ( $this->fees as $fee ) {
				$this->total += $fee->get_total();
			}
			return $this->total;
		}
		public function get_total() {
			return $this->total;
		}
		public function get_status() {
			return $this->status;
		}
		public function update_status( $status, $note = '' ) {
			$this->statuses[] = $status;
			$this->status     = $status;
			$this->notes[]    = $note;
		}
		public function add_order_note( $note ) {
			$this->notes[] = $note;
		}
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}
		public function get_meta( $key, $single = true ) {
			return $this->meta[ $key ] ?? '';
		}
		public function get_billing_country() {
			return $this->billing_country;
		}
		public function get_currency() {
			return 'ISK';
		}
		public function save() {
			++$this->saves;
		}
		// Opt-in suites: billing identity + meta presence.
		public $billing_email      = 'customer@example.com';
		public $billing_phone      = '5551234';
		public $billing_first_name = 'Anna';
		public $billing_last_name  = 'Example';
		public $customer_id        = 0;
		public function get_billing_email() {
			return $this->billing_email;
		}
		public function get_billing_phone() {
			return $this->billing_phone;
		}
		public function get_billing_first_name() {
			return $this->billing_first_name;
		}
		public function get_billing_last_name() {
			return $this->billing_last_name;
		}
		public function get_customer_id() {
			return $this->customer_id;
		}
		public function meta_exists( $key ) {
			return array_key_exists( $key, $this->meta );
		}
	}
}
