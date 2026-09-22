<?php
/**
 * Regression tests for the determine_current_user re-entrancy hazard in
 * StoreDash_Auth_Handler (fixed in 1.6.1).
 *
 * Live failure this pins: validate_wc_api_keys() used to call user_can()
 * inside the 'determine_current_user' filter. user_can() fires the
 * map_meta_cap filter chain; Yoast SEO's map_meta_cap_for_seo_manager calls
 * wp_get_current_user(), which — because the current user is not resolved
 * yet — re-fires 'determine_current_user' and re-enters our callback in
 * unbounded recursion until PHP memory is exhausted. Every storedash/v1
 * endpoint on the affected store returned HTTP 500.
 *
 * Two invariants are pinned here:
 *  1. A re-entrant call into authenticate_api_keys_for_storedash() while a
 *     validation is in flight returns immediately (anonymous), performs no
 *     second key lookup, and terminates.
 *  2. The credential-validation path never invokes capability machinery
 *     (user_can / current_user_can) — capability enforcement belongs to the
 *     routes' permission_callback stage, after user resolution.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Standalone-harness stubs (see tests/bootstrap.php for the shared ones).
// The handler file self-instantiates at file scope; add_action/add_filter may
// already be stubbed by another test file in the suite.
// ---------------------------------------------------------------------------

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
if ( ! function_exists( 'wc_api_hash' ) ) {
	function wc_api_hash( $key ) {
		return 'hashed_' . $key;
	}
}

// Capability machinery MUST NOT run inside the auth filter. These stubs make
// any regression an explicit, attributable failure instead of an undefined-
// function error.
if ( ! function_exists( 'user_can' ) ) {
	function user_can() {
		$GLOBALS['sd_test_capability_calls'][] = 'user_can';
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() {
		$GLOBALS['sd_test_capability_calls'][] = 'current_user_can';
		return true;
	}
}

// get_user_by is the seam the re-entrancy test uses: while the outer
// validation is in flight, it simulates a third-party filter calling
// wp_get_current_user() → determine_current_user → our callback again.
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $user_id ) {
		if ( isset( $GLOBALS['sd_test_user_lookup'] ) ) {
			return call_user_func( $GLOBALS['sd_test_user_lookup'], $field, $user_id );
		}
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

/**
 * Minimal $wpdb stub: one WooCommerce API key row, plus a query counter so
 * tests can assert exactly how many key lookups a request performed.
 */
class SD_Test_WPDB {
	public $prefix      = 'wp_';
	public $usermeta    = 'wp_usermeta';
	public $query_count = 0;
	/** @var array<string, object> hashed consumer key => key row */
	public $rows = array();

	private $last_hashed_key = '';

	public function prepare( $query, ...$args ) {
		$this->last_hashed_key = (string) $args[0];
		return $query;
	}

	public function get_row( $query ) {
		++$this->query_count;
		return isset( $this->rows[ $this->last_hashed_key ] )
			? $this->rows[ $this->last_hashed_key ]
			: null;
	}
}

require_once __DIR__ . '/../../inc/core/class-storedash-auth-handler.php';

/**
 * @covers StoreDash_Auth_Handler::authenticate_api_keys_for_storedash
 */
class Auth_Reentrancy_Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sd_test_capability_calls'] = array();
		unset( $GLOBALS['sd_test_user_lookup'] );

		$GLOBALS['wpdb'] = new SD_Test_WPDB();

		$_SERVER['REQUEST_URI']    = '/wp-json/storedash/v1/status';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	}

	/**
	 * Register a valid key row and set the matching Basic Authorization header.
	 *
	 * Consumer keys are unique per call because validate_wc_api_keys() keeps a
	 * request-scoped static cache keyed on the raw credentials.
	 */
	private function arm_valid_credentials( int $user_id, string $permissions = 'read_write' ): string {
		$ck = 'ck_' . uniqid( '', true );
		$cs = 'cs_secret';

		$row                  = new stdClass();
		$row->key_id          = 1;
		$row->user_id         = $user_id;
		$row->permissions     = $permissions;
		$row->consumer_secret = $cs;

		$GLOBALS['wpdb']->rows[ wc_api_hash( $ck ) ] = $row;

		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( $ck . ':' . $cs );

		return $ck;
	}

	public function test_valid_credentials_authenticate_the_key_user(): void {
		$this->arm_valid_credentials( 42 );

		$handler = StoreDash_Auth_Handler::instance();

		$this->assertSame( 42, $handler->authenticate_api_keys_for_storedash( false ) );
	}

	public function test_invalid_secret_is_rejected(): void {
		$this->arm_valid_credentials( 42 );
		// Same consumer key, wrong secret.
		$auth_parts                    = explode( ' ', $_SERVER['HTTP_AUTHORIZATION'] );
		$ck                            = explode( ':', base64_decode( $auth_parts[1] ) )[0];
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( $ck . ':cs_wrong' );

		$handler = StoreDash_Auth_Handler::instance();

		$this->assertFalse( $handler->authenticate_api_keys_for_storedash( false ) );
	}

	/**
	 * THE regression: a re-entrant determine_current_user pass while a
	 * validation is in flight must return immediately as anonymous, without a
	 * second key lookup — and the outer pass must still succeed.
	 */
	public function test_reentrant_call_during_validation_bails_immediately(): void {
		$this->arm_valid_credentials( 42 );

		$handler          = StoreDash_Auth_Handler::instance();
		$reentrant_result = 'not-called';
		$depth            = 0;

		// Simulate Yoast-style re-entry from inside the validation window:
		// get_user_by() stands in for any callee that ends up invoking
		// wp_get_current_user() → determine_current_user → this filter.
		$GLOBALS['sd_test_user_lookup'] = function ( $field, $user_id ) use ( $handler, &$reentrant_result, &$depth ) {
			++$depth;
			if ( 1 === $depth ) {
				$reentrant_result = $handler->authenticate_api_keys_for_storedash( false );
			}
			$user     = new stdClass();
			$user->ID = (int) $user_id;
			return $user;
		};

		$outer = $handler->authenticate_api_keys_for_storedash( false );

		$this->assertSame( 42, $outer, 'outer authentication must still succeed' );
		$this->assertFalse( $reentrant_result, 're-entrant pass must resolve anonymous (false), not recurse' );
		$this->assertSame( 1, $depth, 'user lookup must run exactly once — recursion means memory exhaustion in production' );
		$this->assertSame( 1, $GLOBALS['wpdb']->query_count, 're-entrant pass must not hit the database again' );
	}

	public function test_auth_path_never_invokes_capability_machinery(): void {
		$this->arm_valid_credentials( 42 );

		$handler = StoreDash_Auth_Handler::instance();
		$handler->authenticate_api_keys_for_storedash( false );

		$this->assertSame(
			array(),
			$GLOBALS['sd_test_capability_calls'],
			'user_can()/current_user_can() inside determine_current_user re-fires third-party capability filters before the user is resolved (Yoast recursion → OOM). Enforce capabilities in permission_callback instead.'
		);
	}

	public function test_read_only_key_still_denied_for_writes(): void {
		$this->arm_valid_credentials( 42, 'read' );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$handler = StoreDash_Auth_Handler::instance();

		$this->assertFalse(
			$handler->authenticate_api_keys_for_storedash( false ),
			'key scope enforcement must survive the capability-check removal'
		);
	}
}
