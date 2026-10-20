<?php
/**
 * Route-scope tests for consumer-key authentication (security audit
 * 2026-10-13 #8) and the customer token bridge (#42).
 *
 * Live hole this pins: StoreDash_Auth_Handler decided "is this a Storedash
 * request?" with strpos() over the WHOLE request URI, query string included.
 * `POST /wp-json/wp/v2/users?x=/wp-json/storedash/` with any WooCommerce
 * consumer key therefore ran as the key's user — usually an administrator —
 * against the core users endpoint. WordPress additionally lets a
 * `?rest_route=` query var override the pretty path, so the path alone is not
 * the route either; StoreDash_REST_Route resolves it the way WordPress does.
 *
 * Invariants:
 *  1. The resolved route follows WP's order: $wp->query_vars > ?rest_route= > path.
 *  2. A query string can never make a request look like ours.
 *  3. Valid credentials on a non-Storedash route do NOT authenticate.
 *  4. guard_route_scope() 403s only requests THIS handler authenticated and
 *     only when the dispatched route leaves the storedash/woodash namespaces.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

// Shared stubs may already come from Auth_Reentrancy_Test when the suite runs
// as a whole; every stub here is guarded.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! defined( 'REST_REQUEST' ) ) {
	define( 'REST_REQUEST', true );
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_string( $value ) ? trim( $value ) : $value;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'rest_get_url_prefix' ) ) {
	function rest_get_url_prefix() {
		return 'wp-json';
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/' ) . '/';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'wc_api_hash' ) ) {
	function wc_api_hash( $key ) {
		return 'hashed_' . $key;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can() {
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() {
		return true;
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $user_id ) {
		$user     = new stdClass();
		$user->ID = (int) $user_id;
		return $user;
	}
}
if ( ! class_exists( 'StoreDash_Helpers' ) ) {
	class StoreDash_Helpers {
		public static function get_server_var( $key, $type = 'text' ) {
			return isset( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : '';
		}
		public static function log_message( $message, $level = 'info' ) {}
		public static function debug_log( $message, $context = array() ) {}
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
	}
}
if ( ! class_exists( 'SD_Test_WPDB' ) ) {
	class SD_Test_WPDB {
		public $prefix      = 'wp_';
		public $usermeta    = 'wp_usermeta';
		public $query_count = 0;
		public $rows        = array();
		private $last_hashed_key = '';
		public function prepare( $query, ...$args ) {
			$this->last_hashed_key = (string) $args[0];
			return $query;
		}
		public function get_row( $query ) {
			++$this->query_count;
			return isset( $this->rows[ $this->last_hashed_key ] ) ? $this->rows[ $this->last_hashed_key ] : null;
		}
	}
}

/** Minimal stand-in for WP_REST_Request: only get_route() is read. */
class SD_Test_REST_Request {
	private $route;
	public function __construct( string $route ) {
		$this->route = $route;
	}
	public function get_route() {
		return $this->route;
	}
}

require_once __DIR__ . '/../../inc/core/class-storedash-rest-route.php';
require_once __DIR__ . '/../../inc/core/class-storedash-auth-handler.php';

/**
 * @covers StoreDash_REST_Route
 * @covers StoreDash_Auth_Handler::authenticate_api_keys_for_storedash
 * @covers StoreDash_Auth_Handler::guard_route_scope
 */
class Rest_Route_Scope_Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new SD_Test_WPDB();
		unset( $GLOBALS['wp'], $_GET['rest_route'] );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$this->reset_handler_flag();
	}

	/** The "authenticated here" flag is static; clear it between tests. */
	private function reset_handler_flag(): void {
		$prop = new ReflectionProperty( StoreDash_Auth_Handler::class, 'authenticated_here' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, false );
	}

	private function arm_valid_credentials( int $user_id ): void {
		$ck = 'ck_' . uniqid( '', true );
		$cs = 'cs_secret';

		$row                  = new stdClass();
		$row->key_id          = 1;
		$row->user_id         = $user_id;
		$row->permissions     = 'read_write';
		$row->consumer_secret = $cs;

		$GLOBALS['wpdb']->rows[ wc_api_hash( $ck ) ] = $row;
		$_SERVER['HTTP_AUTHORIZATION']               = 'Basic ' . base64_encode( $ck . ':' . $cs );
	}

	// ----- StoreDash_REST_Route::current() --------------------------------

	public function test_route_from_pretty_path_ignores_query_string(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users?x=/wp-json/storedash/v1/status';
		$this->assertSame( '/wp/v2/users', StoreDash_REST_Route::current() );
	}

	public function test_route_from_subdirectory_install(): void {
		$_SERVER['REQUEST_URI'] = '/shop/wp-json/storedash/v1/status?foo=bar';
		$this->assertSame( '/storedash/v1/status', StoreDash_REST_Route::current() );
	}

	public function test_query_rest_route_wins_over_path_like_wordpress(): void {
		// WP::parse_request lets $_GET['rest_route'] override the rewrite match,
		// so this request is dispatched to wp/v2/users — the path is a decoy.
		$_SERVER['REQUEST_URI'] = '/wp-json/storedash/v1/status?rest_route=/wp/v2/users';
		$_GET['rest_route']     = '/wp/v2/users';
		$this->assertSame( '/wp/v2/users', StoreDash_REST_Route::current() );
	}

	public function test_resolved_query_var_wins_over_everything(): void {
		$_SERVER['REQUEST_URI']  = '/wp-json/storedash/v1/status';
		$GLOBALS['wp']           = new stdClass();
		$GLOBALS['wp']->query_vars = array( 'rest_route' => '/wc/v3/orders' );
		$this->assertSame( '/wc/v3/orders', StoreDash_REST_Route::current() );
	}

	public function test_non_rest_request_is_empty(): void {
		$_SERVER['REQUEST_URI'] = '/shop/product/foo?x=/wp-json/storedash/';
		$this->assertSame( '', StoreDash_REST_Route::current() );
	}

	public function test_in_namespaces_is_anchored(): void {
		$this->assertTrue( StoreDash_REST_Route::in_namespaces( '/storedash/v1/status', array( 'storedash', 'woodash' ) ) );
		$this->assertTrue( StoreDash_REST_Route::in_namespaces( 'woodash/v1/x', array( 'storedash', 'woodash' ) ) );
		$this->assertFalse( StoreDash_REST_Route::in_namespaces( '/wp/v2/users', array( 'storedash', 'woodash' ) ) );
		$this->assertFalse( StoreDash_REST_Route::in_namespaces( '/storedashx/v1', array( 'storedash' ) ) );
		$this->assertFalse( StoreDash_REST_Route::in_namespaces( '/storedash%2Fv1%2Fstatus', array( 'storedash' ) ) );
		$this->assertFalse( StoreDash_REST_Route::in_namespaces( '', array( 'storedash' ) ) );
	}

	// ----- authenticate_api_keys_for_storedash() --------------------------

	public function test_valid_key_still_authenticates_on_a_storedash_route(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/storedash/v1/status?per_page=5';
		$this->arm_valid_credentials( 42 );

		$this->assertSame( 42, StoreDash_Auth_Handler::instance()->authenticate_api_keys_for_storedash( false ) );
	}

	public function test_valid_key_still_authenticates_on_a_woodash_route(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/woodash/v1/products';
		$this->arm_valid_credentials( 42 );

		$this->assertSame( 42, StoreDash_Auth_Handler::instance()->authenticate_api_keys_for_storedash( false ) );
	}

	public function test_valid_key_still_authenticates_via_rest_route_query_var(): void {
		// Non-pretty permalinks: WordPress routes on ?rest_route=.
		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=/storedash/v1/status';
		$_GET['rest_route']     = '/storedash/v1/status';
		$this->arm_valid_credentials( 42 );

		$this->assertSame( 42, StoreDash_Auth_Handler::instance()->authenticate_api_keys_for_storedash( false ) );
	}

	/**
	 * @dataProvider smuggled_requests
	 */
	public function test_valid_key_does_not_authenticate_outside_our_namespaces( string $uri, ?string $rest_route ): void {
		$_SERVER['REQUEST_URI'] = $uri;
		if ( null !== $rest_route ) {
			$_GET['rest_route'] = $rest_route;
		}
		$this->arm_valid_credentials( 42 );

		$this->assertFalse(
			StoreDash_Auth_Handler::instance()->authenticate_api_keys_for_storedash( false ),
			"$uri must not authenticate a consumer key"
		);
		$this->assertSame( 0, $GLOBALS['wpdb']->query_count, 'no key lookup should even be attempted' );
	}

	public function smuggled_requests(): array {
		return array(
			'query string decoy'           => array( '/wp-json/wp/v2/users?x=/wp-json/storedash/', null ),
			'query string decoy, plugins'  => array( '/wp-json/wp/v2/plugins?x=/wp-json/storedash/v1/', null ),
			'rest_route override to core'  => array( '/wp-json/storedash/v1/status?rest_route=/wp/v2/users', '/wp/v2/users' ),
			'encoded namespace separator'  => array( '/wp-json/storedash%2Fv1%2Fstatus', null ),
			'namespace prefix collision'   => array( '/wp-json/storedashx/v1/status', null ),
			'plain core route'             => array( '/wp-json/wc/v3/orders', null ),
			'not a REST request at all'    => array( '/?x=/wp-json/storedash/', null ),
		);
	}

	// ----- guard_route_scope() -------------------------------------------

	public function test_guard_passes_when_this_handler_did_not_authenticate(): void {
		$handler = StoreDash_Auth_Handler::instance();

		$this->assertNull( $handler->guard_route_scope( null, null, new SD_Test_REST_Request( '/wp/v2/users' ) ) );
	}

	public function test_guard_allows_our_routes_and_blocks_the_rest_after_we_authenticated(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/storedash/v1/status';
		$this->arm_valid_credentials( 42 );
		$handler = StoreDash_Auth_Handler::instance();
		$this->assertSame( 42, $handler->authenticate_api_keys_for_storedash( false ) );

		$this->assertNull( $handler->guard_route_scope( null, null, new SD_Test_REST_Request( '/storedash/v1/status' ) ) );
		$this->assertNull( $handler->guard_route_scope( null, null, new SD_Test_REST_Request( '/woodash/v1/products' ) ) );

		foreach ( array( '/wp/v2/users', '/wp/v2/plugins', '/wc/v3/orders', '/batch/v1', '' ) as $route ) {
			$blocked = $handler->guard_route_scope( null, null, new SD_Test_REST_Request( $route ) );
			$this->assertInstanceOf( WP_Error::class, $blocked, "$route must be refused" );
			$this->assertSame( 'storedash_rest_forbidden', $blocked->get_error_code() );
			$this->assertSame( 403, $blocked->data['status'] );
		}
	}

	public function test_guard_never_overrides_an_existing_short_circuit(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/storedash/v1/status';
		$this->arm_valid_credentials( 42 );
		$handler = StoreDash_Auth_Handler::instance();
		$handler->authenticate_api_keys_for_storedash( false );

		$existing = new WP_Error( 'other', 'already decided' );
		$this->assertSame( $existing, $handler->guard_route_scope( $existing, null, new SD_Test_REST_Request( '/wp/v2/users' ) ) );
	}
}
