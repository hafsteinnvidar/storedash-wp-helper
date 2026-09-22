<?php
/**
 * StoreDash Helper Utilities
 *
 * Centralized helper functions for sanitization, logging, and common operations.
 *
 * @package StoreDash
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StoreDash helper utilities class
 */
class StoreDash_Helpers {

	/**
	 * Get and sanitize a $_SERVER variable
	 *
	 * @param string $key The $_SERVER key to retrieve
	 * @param string $sanitize_callback The type of sanitization to apply
	 * @return string|int|bool Sanitized value or empty string if not set
	 */
	public static function get_server_var( string $key, string $sanitize_callback = 'text' ) {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is unslashed here and sanitized by the type-specific switch below before every return.
		$value = wp_unslash( $_SERVER[ $key ] );

		// Apply appropriate sanitization based on type
		switch ( $sanitize_callback ) {
			case 'url':
				return esc_url_raw( $value );
			case 'email':
				return sanitize_email( $value );
			case 'int':
				return absint( $value );
			case 'bool':
				return (bool) $value;
			case 'key':
				return sanitize_key( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Cloudflare's published edge IP ranges (cloudflare.com/ips).
	 *
	 * Used to VERIFY that a request claiming to come through Cloudflare
	 * actually arrived from a Cloudflare edge before its CF-Connecting-IP
	 * header is believed. These ranges change very rarely (years); the
	 * `storedash_trusted_proxy_ranges` filter can extend or replace them.
	 *
	 * @var string[]
	 */
	const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Resolve the client IP address for rate-limit identity.
	 *
	 * The core rule: a forwarded header is only believed after VERIFYING the
	 * immediate peer (REMOTE_ADDR) is a proxy entitled to set it. Trusting the
	 * header alone is exploitable — a bot connecting straight to the origin can
	 * send `CF-Connecting-IP: <random>` on every request and rotate its way
	 * through any per-IP limit. Ignoring the header is also wrong — behind
	 * Cloudflare or a local nginx, every visitor shares the proxy's address and
	 * a per-IP limit becomes a site-wide limit.
	 *
	 * Resolution order:
	 *  1. REMOTE_ADDR is a Cloudflare edge (verified against published ranges,
	 *     extensible via `storedash_trusted_proxy_ranges`) → CF-Connecting-IP.
	 *  2. REMOTE_ADDR is private/loopback (a reverse proxy on the same box or
	 *     LAN — FlyWP nginx, Docker, load balancer) → rightmost PUBLIC address
	 *     in X-Forwarded-For, else X-Real-IP. Rightmost, because the trusted
	 *     local hop appends the true peer at the end; anything a client forged
	 *     sits further left.
	 *  3. Otherwise → REMOTE_ADDR (direct connection).
	 *
	 * The legacy `storedash_trust_proxy_headers` filter is kept as a manual
	 * override for exotic setups (unconditional header trust, original
	 * semantics) but is no longer required for the common cases.
	 *
	 * @return string Validated IP address, or empty string when none is resolvable.
	 */
	public static function get_client_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			$remote_addr = '';
		}

		// Manual override: unconditional header trust (legacy semantics).
		if ( (bool) apply_filters( 'storedash_trust_proxy_headers', false ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				$ip = self::header_ip( $header );
				if ( '' !== $ip ) {
					return $ip;
				}
			}

			return $remote_addr;
		}

		if ( '' === $remote_addr ) {
			return '';
		}

		// 1. Verified Cloudflare edge → its client header is authoritative.
		$trusted_ranges = (array) apply_filters( 'storedash_trusted_proxy_ranges', self::CLOUDFLARE_RANGES );
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::ip_in_ranges( $remote_addr, $trusted_ranges ) ) {
			$ip = self::header_ip( 'HTTP_CF_CONNECTING_IP' );
			if ( '' !== $ip ) {
				return $ip;
			}
		}

		// 2. Local reverse proxy → rightmost public hop in XFF, else X-Real-IP.
		if ( self::is_private_ip( $remote_addr ) ) {
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$chain = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
				for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
					$candidate = trim( $chain[ $i ] );
					if ( filter_var( $candidate, FILTER_VALIDATE_IP ) && ! self::is_private_ip( $candidate ) ) {
						return $candidate;
					}
				}
			}

			$ip = self::header_ip( 'HTTP_X_REAL_IP' );
			if ( '' !== $ip && ! self::is_private_ip( $ip ) ) {
				return $ip;
			}
		}

		// 3. Direct connection.
		return $remote_addr;
	}

	/**
	 * Read and validate a single-IP header.
	 *
	 * @param string $header $_SERVER key.
	 * @return string Valid IP or ''.
	 */
	private static function header_ip( string $header ): string {
		if ( empty( $_SERVER[ $header ] ) ) {
			return '';
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

		// X-Forwarded-For may be a chain even when read via the override path.
		if ( strpos( $ip, ',' ) !== false ) {
			$ip = trim( explode( ',', $ip )[0] );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether an IP is private, loopback or otherwise reserved.
	 *
	 * @param string $ip Valid IP.
	 * @return bool
	 */
	private static function is_private_ip( string $ip ): bool {
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Whether an IP falls inside any of the given CIDR ranges (v4 + v6).
	 *
	 * @param string   $ip     Valid IP.
	 * @param string[] $ranges CIDR strings.
	 * @return bool
	 */
	private static function ip_in_ranges( string $ip, array $ranges ): bool {
		$ip_bin = inet_pton( $ip );
		if ( false === $ip_bin ) {
			return false;
		}

		foreach ( $ranges as $cidr ) {
			if ( strpos( (string) $cidr, '/' ) === false ) {
				continue;
			}

			list( $subnet, $bits ) = explode( '/', (string) $cidr, 2 );

			$subnet_bin = inet_pton( $subnet );
			// Address families must match (a v4 address can never be inside a
			// v6 range); binary lengths differ, so compare them.
			if ( false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
				continue;
			}

			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			$rem   = $bits % 8;

			if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, $subnet_bin, 0, $bytes ) ) {
				continue;
			}

			if ( $rem > 0 ) {
				$mask = ( 0xFF << ( 8 - $rem ) ) & 0xFF;
				if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $subnet_bin[ $bytes ] ) & $mask ) ) {
					continue;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Debug logging - only logs when WP_DEBUG is enabled
	 *
	 * This is the primary method for all debug logging in the plugin.
	 * Uses WooCommerce logger when available for better integration.
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context data
	 */
	public static function debug_log( string $message, array $context = array() ): void {
		// Only log in debug mode - critical for production performance
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Use WooCommerce logger if available (preferred for WC ecosystem)
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger      = wc_get_logger();
			$log_context = array_merge( array( 'source' => 'storedash' ), $context );
			$logger->debug( $message, $log_context );
			return;
		}

		// Fallback to error_log
		$log_message = sprintf( '[StoreDash] %s', $message );
		if ( ! empty( $context ) ) {
			$log_message .= ' | Context: ' . wp_json_encode( $context );
		}
		error_log( $log_message );
	}

	/**
	 * Error and warning logging
	 *
	 * Errors are always logged, warnings only in debug mode
	 *
	 * @param string $message The message to log
	 * @param string $level   The log level (error, warning, info)
	 * @param array  $context Additional context data
	 */
	public static function log_message( string $message, string $level = 'error', array $context = array() ): void {
		// Always log errors, warnings/info only in debug
		if ( $level !== 'error' && ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) ) {
			return;
		}

		// Use WooCommerce logger if available (WordPress/WooCommerce best practice)
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();

			// Add context to message if provided
			$log_message = $message;
			if ( ! empty( $context ) ) {
				$log_message .= ' | Context: ' . wp_json_encode( $context );
			}

			// Map level to WC_Logger level and log
			$wc_level = in_array( $level, array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ), true )
				? $level
				: 'error';

			$logger->log( $wc_level, $log_message, array( 'source' => 'storedash' ) );
		} else {
			// Fallback to error_log if WC not available
			$log_message = sprintf(
				'[%s] [StoreDash] [%s] %s',
				gmdate( 'Y-m-d H:i:s' ),
				strtoupper( $level ),
				$message
			);

			if ( ! empty( $context ) ) {
				$log_message .= ' | ' . wp_json_encode( $context );
			}

			error_log( $log_message );
		}
	}

	/**
	 * Whether outbound HTTP requests should verify SSL certificates.
	 *
	 * Defaults to true (secure). Hosts with broken CA bundles can override
	 * via the `storedash_ssl_verify` filter.
	 *
	 * @return bool
	 */
	public static function get_ssl_verify(): bool {
		return apply_filters( 'storedash_ssl_verify', true );
	}

	/**
	 * Whether the store has completed its Storedash connection.
	 *
	 * A connection is complete when Storedash has provisioned BOTH the store ID
	 * and the webhook signing secret during setup. Outbound data senders (cart,
	 * waitlist, enquiry, opt-in) must gate on this so that a fresh, never-connected
	 * install transmits no customer data off-site — matching the readme's
	 * "does not send any data until you connect it" promise. Requiring the secret
	 * also guarantees every outbound payload is HMAC-signed.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when both store ID and webhook secret are present.
	 */
	public static function is_store_connected(): bool {
		$store_id = (int) get_option( 'woodash_store_id', 0 );
		$secret   = (string) get_option( 'woodash_webhook_secret', '' );

		if ( $store_id > 0 && '' === $secret ) {
			self::debug_log( 'Store has a store_id but no webhook secret — outbound sends are gated off until setup completes.' );
		}

		return $store_id > 0 && '' !== $secret;
	}

	/**
	 * Resolve an outbound webhook URL, honoring the connection gate and the
	 * "unset uses default, explicitly-empty disables" contract.
	 *
	 * - Not connected            → '' (nothing is sent before setup completes).
	 * - Option never set         → the Storedash default endpoint.
	 * - Option set to ''         → '' (the merchant deliberately disabled this call).
	 * - Option set to a URL      → that URL.
	 *
	 * @since 1.3.0
	 *
	 * @param string $option_key  Option name overriding the default endpoint.
	 * @param string $default_url Storedash default endpoint for this event type.
	 * @return string The URL to POST to, or '' when the call is disabled/ungated.
	 */
	public static function resolve_webhook_url( string $option_key, string $default_url ): string {
		if ( ! self::is_store_connected() ) {
			return '';
		}

		// Sentinel default distinguishes "never set" from an explicit empty string.
		$configured = get_option( $option_key, false );

		if ( false === $configured ) {
			return $default_url; // Never set — use the Storedash default.
		}

		$configured = trim( (string) $configured );
		if ( '' === $configured ) {
			return ''; // Explicitly cleared — merchant disabled this outbound call.
		}

		return esc_url_raw( $configured );
	}

	/**
	 * Resolve the checkout marketing opt-in checkbox label.
	 *
	 * Merchants may customise the wording from the StoreDash dashboard
	 * (stored in the `woodash_checkout_optin_label` option). An empty/unset
	 * value falls back to the built-in default so both the classic and block
	 * checkout render identical copy. Plain text only.
	 *
	 * @return string The label to display next to the checkbox.
	 */
	public static function checkout_optin_label(): string {
		$default = __( 'I want to receive promotional emails with sales, new products, and special offers', 'storedash' );

		$configured = trim( (string) get_option( 'woodash_checkout_optin_label', '' ) );

		return '' === $configured ? $default : $configured;
	}
}
