<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Webhooks {
	public function __construct() {
		add_action( 'woocommerce_api_woodash_webhook', array( $this, 'handle_webhook' ) );
	}

	/**
	 * Handle incoming webhooks
	 */
	public function handle_webhook() {
		try {
			// Get store ID from URL - sanitize input
			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
			if ( ! preg_match( '/\/webhooks\/woo\/(\d+)$/', $request_uri, $matches ) ) {
				$this->log_webhook_error( 'Invalid webhook URL format', $request_uri );
				wp_send_json_error( 'Invalid webhook URL', 400 );
				return;
			}
			$store_id = (int) $matches[1];

			// Validate store ID
			if ( $store_id <= 0 ) {
				$this->log_webhook_error( 'Invalid store ID', $store_id );
				wp_send_json_error( 'Invalid store ID', 400 );
				return;
			}

			// Rate limiting - prevent webhook abuse
			$client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			if ( empty( $client_ip ) ) {
				$this->log_webhook_error( 'Unable to determine client IP', '' );
				wp_send_json_error( 'Unable to process request', 400 );
				return;
			}

			// DB-backed transient so the per-IP/store counter persists across requests.
			$rate_limit_key   = 'webhook_rate_limit_' . md5( $client_ip . $store_id );
			$current_requests = (int) get_transient( $rate_limit_key );

			if ( $current_requests > 10000 ) { // 10,000 requests per hour per IP/store combination
				$this->log_webhook_error( 'Rate limit exceeded', "IP: $client_ip, Store: $store_id, Requests: $current_requests" );
				wp_send_json_error( 'Rate limit exceeded', 429 );
				return;
			}

			set_transient( $rate_limit_key, $current_requests + 1, HOUR_IN_SECONDS );

			// Verify webhook signature - sanitize headers
			// wp_unslash() is a no-op on the base64 signature (no backslashes to strip) and
			// the HMAC is computed over $payload (raw php://input), so this does not alter
			// the signed bytes or the compared value — see SEC-3 sign/verify byte-consistency.
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '' ) );
			$payload   = file_get_contents( 'php://input' );

			// Validate payload
			if ( $payload === false ) {
				$this->log_webhook_error( 'Failed to read webhook payload', '' );
				wp_send_json_error( 'Invalid payload', 400 );
				return;
			}

			$secret = get_option( 'woodash_webhook_secret' );

			if ( ! $signature || ! $secret ) {
				$this->log_webhook(
					array(
						'store_id' => $store_id,
						'event'    => sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WC_WEBHOOK_EVENT'] ?? 'unknown' ) ),
						'error'    => 'Missing signature or secret',
						'payload'  => $payload,
					)
				);
				wp_send_json_error( 'Missing signature or secret', 401 );
				return;
			}

			// Verify signature (constant-time comparison — audit 2026-06-14, P3-1).
			$calculated_signature = base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) );
			if ( ! hash_equals( $calculated_signature, (string) $signature ) ) {
				$this->log_webhook(
					array(
						'store_id' => $store_id,
						'event'    => sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WC_WEBHOOK_EVENT'] ?? 'unknown' ) ),
						'error'    => 'Invalid signature',
						'payload'  => $payload,
					)
				);
				wp_send_json_error( 'Invalid signature', 401 );
				return;
			}

			// Process webhook based on event type - sanitize event header
			$event = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WC_WEBHOOK_EVENT'] ?? '' ) );

			// Log webhook receipt
			$this->log_webhook(
				array(
					'store_id'  => $store_id,
					'event'     => $event,
					'signature' => $signature,
					'payload'   => $payload,
				)
			);
			switch ( $event ) {
				case 'order.created':
				case 'order.updated':
				case 'order.deleted':
				case 'product.created':
				case 'product.updated':
				case 'product.deleted':
					// Order and product webhooks flow directly to storedash-sync via the
					// main-app WC-native webhooks (Hookdeck -> Go sync). This receiver only
					// verifies the signature and acknowledges; no PHP-side interception.
					// The WC core REST payload already carries bundle_* / bundled_items.
					wp_send_json_success();
					return;
				default:
					wp_send_json_error( 'Unknown webhook event', 400 );
					return;
			}
		} catch ( Exception $e ) {
			$this->log_webhook_error(
				'Critical webhook processing error: ' . $e->getMessage(),
				array(
					'store_id' => $store_id ?? 'unknown',
					'event'    => $event ?? 'unknown',
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
				)
			);

			wp_send_json_error( 'Internal server error', 500 );
		} catch ( Error $e ) {
			$this->log_webhook_error(
				'Fatal webhook processing error: ' . $e->getMessage(),
				array(
					'store_id' => $store_id ?? 'unknown',
					'event'    => $event ?? 'unknown',
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
				)
			);

			wp_send_json_error( 'Internal server error', 500 );
		}
	}

	/**
	 * Log webhook receipt
	 */
	private function log_webhook( $data ) {
		// Only log webhook details in debug mode.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Route through the WC_Logger (WooCommerce > Status > Logs) instead of a
		// predictably-named, web-readable file in wp-content/. The previous
		// wp-content/woodash-webhooks.log exposed customer PII + signatures at a
		// guessable URL on any WP_DEBUG site and is disallowed on managed hosts
		// (WP.com/VIP have a read-only filesystem outside uploads).
		StoreDash_Helpers::debug_log(
			'Inbound webhook received',
			array(
				'store_id' => $data['store_id'],
				'event'    => $data['event'],
			)
		);
	}

	/**
	 * Log webhook errors
	 *
	 * @param string $message Error message
	 * @param mixed  $context Additional context
	 */
	private function log_webhook_error( $message, $context = '' ) {
		$context_data = array();
		if ( ! empty( $context ) ) {
			$context_data = is_array( $context ) ? $context : array( 'details' => $context );
		}

		// Use the StoreDash logging helper
		if ( class_exists( 'StoreDash_Helpers' ) ) {
			StoreDash_Helpers::log_message( 'Webhook: ' . $message, 'error', $context_data );
		} else {
			// Fallback to error_log
			$log_message = sprintf( '[%s] Webhook Error: %s', current_time( 'Y-m-d H:i:s' ), $message );
			if ( ! empty( $context ) ) {
				$log_message .= ' | Context: ' . ( is_string( $context ) ? $context : wp_json_encode( $context ) );
			}
			error_log( $log_message );
		}
	}
}

new StoreDash_Webhooks();
