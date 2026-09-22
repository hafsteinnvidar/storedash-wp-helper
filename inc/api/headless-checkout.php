<?php
/**
 * StoreDash Headless Checkout API
 *
 * REST endpoint for creating signed checkout URLs.
 * Called server-to-server by storedash-storefront.
 *
 * @package StoreDash
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_API_Headless_Checkout {

	private static $instance = null;

	private $namespace = 'storedash/v1';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/headless-checkout/create-handoff',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_handoff' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'cart_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'session_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Create a signed checkout URL.
	 *
	 * Uses HMAC instead of transients — the URL is stateless,
	 * retryable, and valid for 1 hour.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_handoff( $request ) {
		$cart_token = $request->get_param( 'cart_token' );
		$session_id = $request->get_param( 'session_id' );

		if ( empty( $cart_token ) || empty( $session_id ) ) {
			return new WP_Error(
				'missing_params',
				'cart_token and session_id are required',
				array( 'status' => 400 )
			);
		}

		$timestamp = time();
		$sig       = self::sign( $cart_token, $session_id, $timestamp );

		$enter_url = site_url(
			'/storedash-checkout/enter?' . http_build_query(
				array(
					'ct'  => $cart_token,
					'sid' => $session_id,
					'ts'  => $timestamp,
					'sig' => $sig,
				)
			)
		);

		return rest_ensure_response(
			array(
				'enter_url' => $enter_url,
			)
		);
	}

	/**
	 * Create an HMAC signature for a checkout handoff.
	 *
	 * @param string $cart_token
	 * @param string $session_id
	 * @param int    $timestamp
	 * @return string
	 */
	public static function sign( $cart_token, $session_id, $timestamp ) {
		$payload = $cart_token . '|' . $session_id . '|' . $timestamp;
		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Verify an HMAC signature and check expiry.
	 *
	 * @param string $cart_token
	 * @param string $session_id
	 * @param int    $timestamp
	 * @param string $sig
	 * @param int    $max_age_seconds Maximum age in seconds (default 1 hour).
	 * @return true|string True if valid, error code string if invalid.
	 */
	public static function verify( $cart_token, $session_id, $timestamp, $sig, $max_age_seconds = 3600 ) {
		if ( empty( $cart_token ) || empty( $session_id ) || empty( $timestamp ) || empty( $sig ) ) {
			return 'missing_params';
		}

		if ( ( time() - intval( $timestamp ) ) > $max_age_seconds ) {
			return 'expired';
		}

		$expected = self::sign( $cart_token, $session_id, intval( $timestamp ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return 'invalid_signature';
		}

		return true;
	}
}

StoreDash_API_Headless_Checkout::instance();
