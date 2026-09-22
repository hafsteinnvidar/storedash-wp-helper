<?php
/**
 * StoreDash WooCommerce Authentication Handler
 *
 * Handles WooCommerce OAuth authentication flow and provides fallback mechanisms
 * when the default callback fails
 *
 * @package StoreDash
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Auth_Handler {

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Request-scoped cache for validated API keys.
	 *
	 * @var array<string, int|false>
	 */
	private static $validated_keys = array();

	/**
	 * Get singleton instance
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		// CRITICAL: Authenticate WooCommerce API keys for StoreDash endpoints
		// This runs BEFORE permission callbacks, making current_user_can() work globally
		add_filter( 'determine_current_user', array( $this, 'authenticate_api_keys_for_storedash' ), 20 );

		// Hook into WooCommerce auth process
		add_action( 'woocommerce_api_auth', array( $this, 'log_auth_request' ), 5 );
		add_filter( 'woocommerce_rest_api_valid_to_send', array( $this, 'validate_callback_connectivity' ), 10, 1 );

		// Add custom endpoint for manual credential submission (fallback)
		add_action( 'rest_api_init', array( $this, 'register_fallback_endpoints' ) );
	}

	/**
	 * Authenticate WooCommerce API keys for StoreDash endpoints.
	 *
	 * This hooks into 'determine_current_user' filter which runs BEFORE permission callbacks.
	 * By authenticating here, current_user_can() works correctly in all permission callbacks.
	 *
	 * @since 2.0.0
	 *
	 * @param int|false $user_id The current user ID or false if not authenticated.
	 * @return int|false User ID if authenticated, original value otherwise.
	 */
	public function authenticate_api_keys_for_storedash( $user_id ) {
		static $authenticating = false;

		// If already authenticated, don't interfere
		if ( $user_id ) {
			return $user_id;
		}

		// Re-entrancy guard. While we validate credentials, any code we call may
		// invoke wp_get_current_user() (directly or via a third-party filter —
		// seen live: Yoast SEO's map_meta_cap_for_seo_manager). The current user
		// is not resolved yet at that point, so WordPress re-fires
		// 'determine_current_user' and re-enters this callback. Without this
		// bail-out that recursion is unbounded and exhausts PHP memory. Inside
		// the guarded window the request is treated as anonymous (user 0), which
		// is exactly how the site behaves for any non-storedash request at this
		// stage of the lifecycle.
		if ( $authenticating ) {
			return $user_id;
		}

		// Only process REST API requests
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $user_id;
		}

		// Check if this is a StoreDash endpoint
		if ( ! $this->is_storedash_rest_request() ) {
			return $user_id;
		}

		// Try to authenticate using WooCommerce API keys
		$authenticating = true;
		try {
			$authenticated_user_id = $this->authenticate_wc_api_keys();
		} finally {
			$authenticating = false;
		}

		if ( $authenticated_user_id ) {
			return $authenticated_user_id;
		}

		return $user_id;
	}

	/**
	 * Check if current request is to a StoreDash REST API endpoint.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if request is to our endpoints.
	 */
	private function is_storedash_rest_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- REST API endpoint detection, no nonce needed
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- REST API endpoint detection, no nonce needed
		$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$rest_prefix = trailingslashit( rest_get_url_prefix() );

		// Check for both namespaces
		$storedash_match = ( false !== strpos( $request_uri, $rest_prefix . 'storedash/' ) );
		$woodash_match   = ( false !== strpos( $request_uri, $rest_prefix . 'woodash/' ) );

		return $storedash_match || $woodash_match;
	}

	/**
	 * Authenticate using WooCommerce API keys (Basic Auth or Query String).
	 *
	 * Supports two authentication methods:
	 * - Basic Auth (preferred): Authorization header with consumer_key:consumer_secret.
	 * - Query String (DEPRECATED, HTTPS-only): ?consumer_key=xxx&consumer_secret=xxx.
	 *   Query strings leak into access logs, browser history, proxy logs, and Referer
	 *   headers, so this fallback is accepted only over HTTPS and emits a deprecation
	 *   warning when used. Clients should send credentials via the Authorization header.
	 *
	 * @since 2.0.0
	 *
	 * @return int|false User ID if authenticated, false otherwise.
	 */
	private function authenticate_wc_api_keys() {
		// Try Basic Auth first
		$consumer_key    = '';
		$consumer_secret = '';

		// Method 1: Basic Auth header
		$auth_header = $this->get_authorization_header();
		if ( $auth_header && 0 === stripos( $auth_header, 'basic ' ) ) {
			$encoded = substr( $auth_header, 6 );
			$decoded = base64_decode( $encoded );
			if ( $decoded && strpos( $decoded, ':' ) !== false ) {
				list($consumer_key, $consumer_secret) = explode( ':', $decoded, 2 );
			}
		}

		// Method 2: Query string parameters (DEPRECATED fallback, HTTPS-only).
		// Query strings leak into access logs / history / Referer headers, so only accept
		// them over HTTPS. Over plain HTTP the fallback is skipped entirely and the method
		// falls through to the "no credentials" return below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- REST API uses consumer key authentication, not nonces
		if ( empty( $consumer_key ) && is_ssl() && ! empty( $_GET['consumer_key'] ) && ! empty( $_GET['consumer_secret'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- REST API uses consumer key authentication, not nonces
			$consumer_key = sanitize_text_field( wp_unslash( $_GET['consumer_key'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- REST API uses consumer key authentication, not nonces
			$consumer_secret = sanitize_text_field( wp_unslash( $_GET['consumer_secret'] ) );

			// Log deprecation (no credential material) so we can see if any client still relies on this path.
			StoreDash_Helpers::log_message(
				'Deprecated auth: consumer credentials received via query string; clients should use the Authorization header.',
				'warning'
			);
		}

		// No credentials found
		if ( empty( $consumer_key ) || empty( $consumer_secret ) ) {
			return false;
		}

		// Validate the API keys against WooCommerce database
		return $this->validate_wc_api_keys( $consumer_key, $consumer_secret );
	}

	/**
	 * Get Authorization header from request.
	 *
	 * @since 2.0.0
	 *
	 * @return string|null Authorization header or null.
	 */
	private function get_authorization_header() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading HTTP headers for REST API auth
		// Apache/Nginx
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading HTTP headers for REST API auth
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading HTTP headers for REST API auth
		// CGI/FastCGI
		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading HTTP headers for REST API auth
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		// getallheaders() fallback
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			// Check both cases
			if ( isset( $headers['Authorization'] ) ) {
				return sanitize_text_field( $headers['Authorization'] );
			}
			if ( isset( $headers['authorization'] ) ) {
				return sanitize_text_field( $headers['authorization'] );
			}
		}

		return null;
	}

	/**
	 * Validate WooCommerce API keys and return the associated user ID.
	 *
	 * @since 2.0.0
	 *
	 * @param string $consumer_key    The consumer key.
	 * @param string $consumer_secret The consumer secret.
	 * @return int|false User ID if valid, false otherwise.
	 */
	private function validate_wc_api_keys( $consumer_key, $consumer_secret ) {
		// Request-scoped cache to avoid repeated DB lookups within the same request.
		// The HTTP method is part of the key because the key's read/write scope is
		// enforced per method below.
		$request_method = strtoupper( StoreDash_Helpers::get_server_var( 'REQUEST_METHOD' ) );
		$cache_key      = md5( $consumer_key . ':' . $consumer_secret . ':' . $request_method );
		if ( array_key_exists( $cache_key, self::$validated_keys ) ) {
			return self::$validated_keys[ $cache_key ];
		}

		global $wpdb;

		// WooCommerce stores consumer_key as hash
		if ( ! function_exists( 'wc_api_hash' ) ) {
			self::$validated_keys[ $cache_key ] = false;
			return false;
		}

		$hashed_key = wc_api_hash( sanitize_text_field( $consumer_key ) );

		// Look up the key in WooCommerce's API keys table
		$key_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT key_id, user_id, permissions, consumer_secret
                FROM {$wpdb->prefix}woocommerce_api_keys
                WHERE consumer_key = %s",
				$hashed_key
			)
		);

		if ( ! $key_data ) {
			self::$validated_keys[ $cache_key ] = false;
			return false;
		}

		// Validate consumer secret
		if ( ! hash_equals( $key_data->consumer_secret, $consumer_secret ) ) {
			self::$validated_keys[ $cache_key ] = false;
			return false;
		}

		// Verify the user exists. Deliberately NO capability check here:
		// user_can()/current_user_can() fire the map_meta_cap / user_has_cap
		// filter chains, and this method runs inside 'determine_current_user' —
		// before the current user is resolved. A third-party capability filter
		// that calls wp_get_current_user() (Yoast SEO does) then re-enters this
		// filter in unbounded recursion until PHP memory is exhausted (seen live,
		// took down every storedash endpoint on the affected store). WooCommerce's
		// own REST auth makes the same choice: authenticate the key user here,
		// enforce capabilities later in each route's permission_callback (all
		// authenticated storedash routes check manage_woocommerce there), where
		// the current user is resolved and capability filters are safe to run.
		$user = get_user_by( 'id', $key_data->user_id );
		if ( ! $user ) {
			self::$validated_keys[ $cache_key ] = false;
			return false;
		}

		// Enforce the key's read/write scope against the HTTP method, mirroring
		// WooCommerce's own REST authentication. A read-only key must not be able to
		// drive POST/PUT/PATCH/DELETE on storedash routes just because its user has
		// manage_woocommerce.
		if ( ! self::method_allowed_for_permission( $request_method, $key_data->permissions ) ) {
			self::$validated_keys[ $cache_key ] = false;
			return false;
		}

		$result                             = (int) $key_data->user_id;
		self::$validated_keys[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Whether an HTTP method is permitted by a WooCommerce API key scope.
	 *
	 * Pure mapping with no instance/WordPress state — exposed as a public static
	 * so the read/write scope truth table can be pinned by a standalone unit test
	 * (see tests/Auth/Method_Allowed_For_PermissionTest.php).
	 *
	 * @param string $method     Upper-case HTTP method.
	 * @param string $permissions Key permission: 'read', 'write', or 'read_write'.
	 * @return bool
	 */
	public static function method_allowed_for_permission( $method, $permissions ) {
		$read_methods  = array( 'GET', 'HEAD', 'OPTIONS' );
		$write_methods = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

		switch ( $permissions ) {
			case 'read':
				return in_array( $method, $read_methods, true );
			case 'write':
				return in_array( $method, $write_methods, true );
			case 'read_write':
				return true;
			default:
				// Unknown/empty scope — allow reads only, fail closed on writes.
				return in_array( $method, $read_methods, true );
		}
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_fallback_endpoints() {
		register_rest_route(
			'storedash/v1',
			'/store-config',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_store_config' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'store_id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'webhook_secret' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Update store configuration pushed from StoreDash during onboarding.
	 *
	 * Sets the store_id so cart/waitlist/stock webhooks reference the correct store.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function update_store_config( $request ) {
		$store_id = $request->get_param( 'store_id' );

		if ( $store_id <= 0 ) {
			return new \WP_REST_Response(
				array( 'error' => 'Invalid store_id' ),
				400
			);
		}

		// Update both option keys to fix the historical inconsistency
		update_option( 'woodash_store_id', $store_id );
		update_option( 'storedash_store_id', $store_id );

		// Sync webhook secret so cart/waitlist/standard webhooks can verify signatures
		$webhook_secret = $request->get_param( 'webhook_secret' );
		if ( ! empty( $webhook_secret ) ) {
			update_option( 'woodash_webhook_secret', $webhook_secret );
		}

		// Gather WooCommerce settings for the dashboard
		$country_code = get_option( 'woocommerce_default_country', '' );
		$country_base = explode( ':', $country_code )[0];

		/*
		 * DUPLICATED MAP — keep in sync with woo-dash.
		 *
		 * woo-dash holds an identical country→locale map in
		 * `src/features/onboarding/services/store-settings-sync.ts`
		 * (`COUNTRY_LOCALE_MAP`), used as the locale fallback when a settings
		 * source omits `locale` (notably the core WC REST fallback path, which
		 * never returns one). The two services deploy independently, so adding
		 * or changing an entry here requires the same edit there — otherwise
		 * stores onboarded before the plugin is verified get a different locale
		 * than stores onboarded after.
		 */
		$locale_map = array(
			'IS' => 'is-IS',
			'DE' => 'de-DE',
			'FR' => 'fr-FR',
			'ES' => 'es-ES',
			'IT' => 'it-IT',
			'PT' => 'pt-PT',
			'NL' => 'nl-NL',
			'JP' => 'ja-JP',
			'KR' => 'ko-KR',
			'CN' => 'zh-CN',
			'BR' => 'pt-BR',
			'SE' => 'sv-SE',
			'NO' => 'nb-NO',
			'DK' => 'da-DK',
			'PL' => 'pl-PL',
			'GB' => 'en-GB',
			'US' => 'en-US',
			'AU' => 'en-AU',
			'CA' => 'en-CA',
			'NZ' => 'en-NZ',
			'AT' => 'de-AT',
			'CH' => 'de-CH',
			'BE' => 'nl-BE',
			'IE' => 'en-IE',
			'MX' => 'es-MX',
			'AR' => 'es-AR',
			'CL' => 'es-CL',
			'CO' => 'es-CO',
			'RU' => 'ru-RU',
			'IN' => 'hi-IN',
			'ZA' => 'en-ZA',
			'TR' => 'tr-TR',
			'TH' => 'th-TH',
			'ID' => 'id-ID',
			'MY' => 'ms-MY',
			'PH' => 'en-PH',
			'SA' => 'ar-SA',
			'AE' => 'ar-AE',
			'EG' => 'ar-EG',
			'NG' => 'en-NG',
			'KE' => 'en-KE',
			'GH' => 'en-GH',
		);

		$locale = isset( $locale_map[ $country_base ] ) ? $locale_map[ $country_base ] : 'en-US';

		$wc_settings = array(
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'country'        => $country_code,
			'locale'         => $locale,
			'timezone'       => wp_timezone_string(),
			'weight_unit'    => get_option( 'woocommerce_weight_unit', 'kg' ),
			'dimension_unit' => get_option( 'woocommerce_dimension_unit', 'cm' ),
			'time_format'    => get_option( 'time_format', 'H:i' ),
			'store_address'  => get_option( 'woocommerce_store_address', '' ),
			'store_city'     => get_option( 'woocommerce_store_city', '' ),
			'store_postcode' => get_option( 'woocommerce_store_postcode', '' ),
		);

		return new \WP_REST_Response(
			array(
				'success'     => true,
				'store_id'    => $store_id,
				'wc_settings' => $wc_settings,
			),
			200
		);
	}

	/**
	 * Log WooCommerce auth requests for debugging
	 */
	public function log_auth_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce OAuth flow parameters
		// Sanitize app_name before checking
		$app_name = isset( $_GET['app_name'] ) ? sanitize_text_field( wp_unslash( $_GET['app_name'] ) ) : '';
		if ( $app_name !== 'StoreDash' ) {
			return;
		}

		// Register debug hooks only during OAuth flow (not globally)
		add_action( 'http_api_debug', array( $this, 'log_http_request' ), 10, 5 );
		add_filter( 'pre_http_request', array( $this, 'log_pre_http_request' ), 10, 3 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WooCommerce OAuth flow parameters
		// Sanitize all inputs before logging
		$request_uri = StoreDash_Helpers::get_server_var( 'REQUEST_URI', 'url' );
		$user_id     = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is unslashed and sanitized with esc_url_raw(); the intervening urldecode() hides the sanitizer from the sniff.
		$callback_url = isset( $_GET['callback_url'] ) ? esc_url_raw( urldecode( wp_unslash( $_GET['callback_url'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is unslashed and sanitized with esc_url_raw(); the intervening urldecode() hides the sanitizer from the sniff.
		$return_url      = isset( $_GET['return_url'] ) ? esc_url_raw( urldecode( wp_unslash( $_GET['return_url'] ) ) ) : '';
		$scope           = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '';
		$server_software = StoreDash_Helpers::get_server_var( 'SERVER_SOFTWARE', 'text' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		StoreDash_Helpers::debug_log(
			'WooCommerce Auth Request',
			array(
				'request_uri'  => $request_uri,
				'app_name'     => $app_name,
				'user_id'      => $user_id ? $user_id : 'not set',
				'callback_url' => $callback_url ? $callback_url : 'not set',
				'return_url'   => $return_url ? $return_url : 'not set',
				'scope'        => $scope ? $scope : 'not set',
				'server'       => $server_software,
				'php_version'  => PHP_VERSION,
			)
		);

		// Test callback connectivity right now
		if ( $callback_url ) {
			StoreDash_Helpers::debug_log( 'Testing callback connectivity to: ' . $callback_url );
			$test_response = wp_remote_get(
				$callback_url,
				array(
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $test_response ) ) {
				StoreDash_Helpers::debug_log(
					'CALLBACK TEST FAILED',
					array(
						'error' => $test_response->get_error_message(),
						'code'  => $test_response->get_error_code(),
					)
				);
			} else {
				StoreDash_Helpers::debug_log( 'Callback reachable: ' . wp_remote_retrieve_response_code( $test_response ) );
			}
		}
	}

	/**
	 * Validate callback connectivity before WooCommerce attempts to send data
	 */
	public function validate_callback_connectivity( $valid ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WooCommerce OAuth flow parameters
		// Sanitize app_name before checking
		$app_name = isset( $_GET['app_name'] ) ? sanitize_text_field( wp_unslash( $_GET['app_name'] ) ) : '';
		if ( $app_name !== 'StoreDash' ) {
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			return $valid;
		}

		if ( ! isset( $_GET['callback_url'] ) ) {
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			return $valid;
		}

		// Sanitize callback URL
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is unslashed and sanitized with esc_url_raw(); the intervening urldecode() hides the sanitizer from the sniff.
		$callback_url = esc_url_raw( urldecode( wp_unslash( $_GET['callback_url'] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		StoreDash_Helpers::debug_log( 'Validating callback connectivity to: ' . $callback_url );

		// Quick connectivity test
		$response = wp_remote_get(
			$callback_url,
			array(
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			StoreDash_Helpers::debug_log(
				'Callback connectivity test FAILED',
				array(
					'error' => $response->get_error_message(),
					'code'  => $response->get_error_code(),
				)
			);

			// Don't block the request, but log the issue
			// We'll provide alternative mechanisms
			return $valid;
		}

		StoreDash_Helpers::debug_log( 'Callback connectivity test passed: ' . wp_remote_retrieve_response_code( $response ) );
		return $valid;
	}

	/**
	 * Log HTTP requests BEFORE they're sent
	 */
	public function log_pre_http_request( $preempt, $parsed_args, $url ) {
		// Only log requests to our callback URL
		if ( strpos( $url, 'app.storedash.io/api/woo-auth' ) === false ) {
			return $preempt;
		}

		StoreDash_Helpers::debug_log(
			'PRE HTTP REQUEST',
			array(
				'url'            => $url,
				'method'         => $parsed_args['method'],
				'headers'        => $parsed_args['headers'],
				'body_truncated' => substr( print_r( $parsed_args['body'], true ), 0, 500 ),
				'timeout'        => $parsed_args['timeout'],
				'ssl_verify'     => $parsed_args['sslverify'],
			)
		);

		// Don't preempt the request, let it continue
		return $preempt;
	}

	/**
	 * Log all HTTP requests to track callback attempts
	 */
	public function log_http_request( $response, $context, $class, $parsed_args, $url ) {
		// Only log requests to our callback URL
		if ( strpos( $url, 'app.storedash.io/api/woo-auth' ) === false ) {
			return;
		}

		StoreDash_Helpers::debug_log(
			'HTTP REQUEST COMPLETE',
			array(
				'url'     => $url,
				'method'  => $parsed_args['method'],
				'context' => $context,
			)
		);

		if ( is_wp_error( $response ) ) {
			StoreDash_Helpers::debug_log(
				'REQUEST FAILED',
				array(
					'error' => $response->get_error_message(),
					'code'  => $response->get_error_code(),
					'data'  => $response->get_error_data(),
				)
			);
		} else {
			StoreDash_Helpers::debug_log(
				'Request successful',
				array(
					'status' => wp_remote_retrieve_response_code( $response ),
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}
	}
}

// Initialize
StoreDash_Auth_Handler::instance();
