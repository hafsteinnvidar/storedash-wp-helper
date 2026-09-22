<?php
/**
 * Webhook Filter
 *
 * Prevents webhook loops by properly detecting and filtering StoreDash-initiated changes.
 * Uses proper WooCommerce hooks and early detection for reliable filtering.
 *
 * @package StoreDash
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Webhook_Filter {

	/**
	 * Header name used to identify StoreDash-initiated changes
	 */
	const STOREDASH_HEADER = 'X-StoreDash-Source';

	/**
	 * Option key to track active StoreDash operations
	 */
	const ACTIVE_OPS_OPTION = 'storedash_active_operations';

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook VERY EARLY in the REST API request to detect StoreDash
		add_action( 'rest_api_init', array( $this, 'detect_storedash_request' ), 1 );

		// Hook into webhook should_deliver with HIGHEST PRIORITY (runs first)
		add_filter( 'woocommerce_webhook_should_deliver', array( $this, 'filter_webhook_delivery' ), 1, 3 );

		// Clean up after request completes
		add_action( 'shutdown', array( $this, 'cleanup_request_tracking' ) );

		// Debug payload annotation — only register in debug mode
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_filter( 'woocommerce_webhook_payload', array( $this, 'add_debug_to_payload' ), 999, 4 );
		}
	}

	/**
	 * Detect if this is a StoreDash request and track it
	 */
	public function detect_storedash_request() {
		if ( $this->is_storedash_request() ) {
			// Mark this request as coming from StoreDash
			$GLOBALS['storedash_request_active'] = true;

			StoreDash_Helpers::debug_log( 'StoreDash request detected and marked' );
		}
	}

	/**
	 * Check if the current request carries the StoreDash source header.
	 *
	 * NOTE: this only detects header PRESENCE and runs at rest_api_init priority 1,
	 * before authentication is resolved — so it is NOT a trust decision on its own.
	 * The header is unauthenticated and spoofable; actual webhook suppression is
	 * additionally gated on the manage_woocommerce capability in
	 * filter_webhook_delivery(), which runs inside the authenticated callback. This
	 * prevents a public request (e.g. a Store API checkout) from spoofing the header
	 * to suppress every one of the merchant's outbound webhooks.
	 */
	private function is_storedash_request() {
		// Check $_SERVER for the header (most reliable)
		$header_key   = 'HTTP_' . str_replace( '-', '_', strtoupper( self::STOREDASH_HEADER ) );
		$header_value = StoreDash_Helpers::get_server_var( $header_key );

		if ( ! empty( $header_value ) ) {
			StoreDash_Helpers::debug_log( 'Detected StoreDash request via header', array( 'header_key' => $header_key ) );
			return true;
		}

		// Check getallheaders if available
		if ( function_exists( 'getallheaders' ) ) {
			$headers      = getallheaders();
			$header_value = isset( $headers[ self::STOREDASH_HEADER ] ) ? $headers[ self::STOREDASH_HEADER ] : '';

			if ( ! empty( $header_value ) ) {
				StoreDash_Helpers::debug_log( 'Detected StoreDash request via getallheaders()' );
				return true;
			}
		}

		// Check apache_request_headers if available
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers      = apache_request_headers();
			$header_value = isset( $headers[ self::STOREDASH_HEADER ] ) ? $headers[ self::STOREDASH_HEADER ] : '';

			if ( ! empty( $header_value ) ) {
				StoreDash_Helpers::debug_log( 'Detected StoreDash request via apache_request_headers()' );
				return true;
			}
		}

		return false;
	}

	/**
	 * Filter webhook delivery for StoreDash-initiated changes
	 *
	 * @param bool       $should_deliver Whether the webhook should be delivered
	 * @param WC_Webhook $webhook The webhook object
	 * @param mixed      $arg The resource ID or object
	 * @return bool Whether to deliver the webhook
	 */
	public function filter_webhook_delivery( $should_deliver, $webhook, $arg ) {
		// If already decided not to deliver, respect that
		if ( ! $should_deliver ) {
			StoreDash_Helpers::debug_log( 'Webhook already blocked by another filter' );
			return false;
		}

		// Suppress echo webhooks for StoreDash-initiated changes — but ONLY when the
		// request is both marked (header present) AND authenticated with
		// manage_woocommerce. The header alone is unauthenticated and spoofable; a
		// real StoreDash request authenticates via a WooCommerce consumer key that
		// carries this capability, while a public Store API checkout does not. This
		// runs inside the authenticated callback, so current_user_can() is resolved.
		if ( ! empty( $GLOBALS['storedash_request_active'] ) && current_user_can( 'manage_woocommerce' ) ) {
			$topic      = $webhook->get_topic();
			$webhook_id = $webhook->get_id();

			StoreDash_Helpers::debug_log(
				'BLOCKING webhook - StoreDash initiated change',
				array(
					'webhook_id' => $webhook_id,
					'topic'      => $topic,
				)
			);

			// Block the webhook
			return false;
		}

		StoreDash_Helpers::debug_log(
			'Allowing webhook',
			array(
				'webhook_id' => $webhook->get_id(),
				'topic'      => $webhook->get_topic(),
			)
		);

		return $should_deliver;
	}

	/**
	 * Add debug information to webhook payload
	 */
	public function add_debug_to_payload( $payload, $resource, $resource_id, $webhook_id ) {
		// Only registered when WP_DEBUG is true — no need to re-check
		if ( ! empty( $GLOBALS['storedash_request_active'] ) ) {
			$payload['_source']  = 'storedash';
			$payload['_blocked'] = 'should_have_been_blocked';
		} else {
			$payload['_source'] = 'external';
		}

		return $payload;
	}

	/**
	 * Clean up request tracking after request completes
	 */
	public function cleanup_request_tracking() {
		if ( ! empty( $GLOBALS['storedash_request_active'] ) ) {
			unset( $GLOBALS['storedash_request_active'] );
			StoreDash_Helpers::debug_log( 'Cleaned up request tracking' );
		}
	}
}

// Initialize the filter
new StoreDash_Webhook_Filter();
