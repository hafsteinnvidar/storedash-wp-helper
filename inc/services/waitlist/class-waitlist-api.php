<?php
declare(strict_types=1);

/**
 * Waitlist REST API
 *
 * Provides REST API endpoints for waitlist data synchronization
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.0.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waitlist API Class
 */
class Waitlist_API {

	/**
	 * API namespace
	 */
	const NAMESPACE = 'storedash/v1';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// Get pending waitlist entries
		register_rest_route(
			self::NAMESPACE,
			'/waitlist/pending',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pending_entries' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				// 'type' => 'integer' plus absint() sanitising matter beyond
				// validation: without them WP hands the callback the raw query
				// string, so `?page=1` echoes back into the response as the
				// STRING "1". The Go sync consumer types that field as an int
				// and encoding/json rejects the whole payload on a type
				// mismatch, which silently killed the nightly waitlist sync.
				'args'                => array(
					'batch_size' => array(
						'default'           => 100,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0 && $param <= 500;
						},
					),
					'page'       => array(
						'default'           => 1,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
				),
			)
		);

		// Create a waitlist entry (headless storefronts).
		//
		// The AJAX widget cannot serve a storefront on another origin: its nonce
		// and form stamp are bound to a WordPress page render. This route takes
		// the same payload over the consumer-key auth every other storedash/v1
		// route uses, so the storefront server (never the browser) submits on
		// the shopper's behalf.
		register_rest_route(
			self::NAMESPACE,
			'/waitlist/entries',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_entry' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'product_id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'variation_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'minimum'           => 0,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'customer_email'  => array(
						'required'          => true,
						'type'              => 'string',
						'maxLength'         => 100,
						'sanitize_callback' => 'sanitize_email',
					),
					'customer_name'   => array(
						'required'          => false,
						'type'              => 'string',
						'maxLength'         => 100,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_phone'  => array(
						'required'          => false,
						'type'              => 'string',
						'maxLength'         => 20,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'language'        => array(
						'required'          => false,
						'type'              => 'string',
						'pattern'           => '^[a-zA-Z]{2}$',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'marketing_optin' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'source'          => array(
						'required'          => false,
						'type'              => 'string',
						'enum'              => array( 'storefront' ),
						'default'           => 'storefront',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Get waitlist stats (useful for monitoring)
		register_rest_route(
			self::NAMESPACE,
			'/waitlist/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Check permissions (WooCommerce consumer key/secret authentication)
	 */
	public function check_permissions( $request ) {
		// WooCommerce handles authentication via consumer key/secret
		// This is automatically validated by WooCommerce REST API authentication
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get pending waitlist entries
	 */
	public function get_pending_entries( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_product_waitlist';

		$batch_size = $request->get_param( 'batch_size' ) ?: 100;
		$page       = $request->get_param( 'page' ) ?: 1;
		$offset     = ( $page - 1 ) * $batch_size;

		// Get pending entries
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
                id,
                store_id,
                product_id,
                variation_id,
                customer_email,
                customer_name,
                customer_phone,
                language,
                product_sku,
                product_name,
                variation_attributes,
                status,
                source,
                created_at,
                updated_at
            FROM $table_name
            WHERE status = 'pending'
            ORDER BY created_at ASC
            LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			),
			ARRAY_A
		);

		// Get total count
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM $table_name WHERE status = 'pending'"
		);

		// Transform data
		$formatted_entries = array_map(
			function ( $entry ) {
				return array(
					'id'                   => (int) $entry['id'],
					'store_id'             => (int) $entry['store_id'],
					'product_id'           => (int) $entry['product_id'],
					'variation_id'         => $entry['variation_id'] ? (int) $entry['variation_id'] : null,
					'customer_email'       => $entry['customer_email'],
					'customer_name'        => $entry['customer_name'],
					'customer_phone'       => $entry['customer_phone'],
					'language'             => $entry['language'],
					'product_sku'          => $entry['product_sku'],
					'product_name'         => $entry['product_name'],
					'variation_attributes' => $entry['variation_attributes'] ? json_decode( $entry['variation_attributes'], true ) : null,
					'status'               => $entry['status'],
					'source'               => $entry['source'],
					'created_at'           => $entry['created_at'],
					'updated_at'           => $entry['updated_at'],
				);
			},
			$entries
		);

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'data'       => $formatted_entries,
				// Every field cast to int: consumers type these as integers and
				// a string (or ceil()'s float) breaks a strict JSON decoder.
				'pagination' => array(
					'total'       => (int) $total,
					'page'        => (int) $page,
					'batch_size'  => (int) $batch_size,
					'total_pages' => (int) ceil( $total / $batch_size ),
				),
			),
			200
		);
	}

	/**
	 * Per-shopper rate limits for the REST ingress.
	 *
	 * The caller is an authenticated storefront server, so PHP's own view of
	 * the client is that server for every shopper. The storefront forwards the
	 * visitor's IP in X-StoreDash-Client-IP (the customer-auth routes use the
	 * same convention); we bucket on that, and on the email itself so a
	 * script rotating addresses still runs into a wall. Trusting the header is
	 * safe here because it is only ever used to bucket limits, never to
	 * authorize, and the request has already passed consumer-key auth.
	 *
	 * Deliberately separate counters from the AJAX widget: those key on the
	 * real REMOTE_ADDR, which for this route would be the storefront server
	 * and would let one busy shopper lock everyone out.
	 */
	const LIMIT_IP_BURST   = array( 5, MINUTE_IN_SECONDS );
	const LIMIT_IP_HOURLY  = array( 30, HOUR_IN_SECONDS );
	const LIMIT_EMAIL_DAY  = array( 20, DAY_IN_SECONDS );
	const LIMIT_GLOBAL_MIN = array( 120, MINUTE_IN_SECONDS );

	/**
	 * Create a waitlist entry on behalf of a shopper.
	 *
	 * Duplicate signups answer 200 with `already_registered: true` rather
	 * than 409: the shopper's intent (be told when it's back) is satisfied
	 * either way, and an idempotent answer lets the storefront retry a timed-
	 * out request without surfacing an error for a signup that succeeded.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_entry( $request ) {
		$shopper_ip = $this->get_shopper_ip();
		$email      = strtolower( (string) $request->get_param( 'customer_email' ) );

		// Global backstop first so a flood without a client IP header (or with
		// a forged, rotating one) is still bounded.
		if ( ! $this->bump_counter( 'sd_wl_rest_global', self::LIMIT_GLOBAL_MIN[0], self::LIMIT_GLOBAL_MIN[1] ) ) {
			return $this->rate_limited();
		}

		if ( '' !== $shopper_ip ) {
			$ip_hash = md5( $shopper_ip );
			if ( ! $this->bump_counter( 'sd_wl_rest_ipb_' . $ip_hash, self::LIMIT_IP_BURST[0], self::LIMIT_IP_BURST[1] )
				|| ! $this->bump_counter( 'sd_wl_rest_iph_' . $ip_hash, self::LIMIT_IP_HOURLY[0], self::LIMIT_IP_HOURLY[1] ) ) {
				return $this->rate_limited();
			}
		}

		if ( '' !== $email && ! $this->bump_counter( 'sd_wl_rest_em_' . md5( $email ), self::LIMIT_EMAIL_DAY[0], self::LIMIT_EMAIL_DAY[1] ) ) {
			return $this->rate_limited();
		}

		$result = Waitlist_Handler::create_entry(
			array(
				'product_id'      => $request->get_param( 'product_id' ),
				'variation_id'    => $request->get_param( 'variation_id' ),
				'customer_email'  => $request->get_param( 'customer_email' ),
				'customer_name'   => $request->get_param( 'customer_name' ),
				'customer_phone'  => $request->get_param( 'customer_phone' ),
				'language'        => $request->get_param( 'language' ),
				'marketing_optin' => (bool) $request->get_param( 'marketing_optin' ),
				// The authenticated user here is the API-key owner (a shop
				// manager), not the shopper — never attach it to the opt-in.
				'customer_id'     => null,
				'source'          => $request->get_param( 'source' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( 'storedash_waitlist_duplicate' === $result->get_error_code() ) {
				return new \WP_REST_Response(
					array(
						'success' => true,
						'data'    => array(
							'already_registered' => true,
						),
					),
					200
				);
			}

			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'id'                 => (int) $result['id'],
					'already_registered' => false,
				),
			),
			201
		);
	}

	/**
	 * The end shopper's IP address, as reported by the storefront.
	 *
	 * @return string Validated IP, or '' when absent/malformed.
	 */
	private function get_shopper_ip(): string {
		if ( empty( $_SERVER['HTTP_X_STOREDASH_CLIENT_IP'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed and validated below.
		$raw = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_STOREDASH_CLIENT_IP'] ) );

		$ip = filter_var( $raw, FILTER_VALIDATE_IP );

		return $ip ? $ip : '';
	}

	/**
	 * Increment a DB-backed transient counter, reporting whether the request
	 * may proceed. Transients rather than the object cache: the plugin's cache
	 * group is non-persistent, so a wp_cache_* counter never accumulates.
	 *
	 * @param string $key    Transient key.
	 * @param int    $max    Maximum requests in the window.
	 * @param int    $window Window length in seconds.
	 * @return bool False when the limit is already reached.
	 */
	private function bump_counter( string $key, int $max, int $window ): bool {
		$attempts = (int) get_transient( $key );

		if ( $attempts >= $max ) {
			return false;
		}

		set_transient( $key, $attempts + 1, $window );

		return true;
	}

	/**
	 * The rate-limit response.
	 *
	 * @return \WP_Error
	 */
	private function rate_limited(): \WP_Error {
		return new \WP_Error(
			'storedash_rate_limited',
			__( 'Too many requests. Please try again in a few minutes.', 'storedash' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Get waitlist statistics
	 */
	public function get_stats( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_product_waitlist';

		$now   = current_time( 'mysql' );
		$today = gmdate( 'Y-m-d', strtotime( $now ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
					SUM(CASE WHEN status = 'notified' THEN 1 ELSE 0 END) AS total_notified,
					SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS total_cancelled,
					SUM(CASE WHEN DATE(created_at) = %s THEN 1 ELSE 0 END) AS entries_today,
					SUM(CASE WHEN created_at >= DATE_SUB(%s, INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS entries_this_week,
					SUM(CASE WHEN DATE(notified_at) = %s THEN 1 ELSE 0 END) AS notifications_today
				FROM $table_name",
				$today,
				$now,
				$today
			)
		);

		$stats = array(
			'total_pending'       => $row ? (int) $row->total_pending : 0,
			'total_notified'      => $row ? (int) $row->total_notified : 0,
			'total_cancelled'     => $row ? (int) $row->total_cancelled : 0,
			'entries_today'       => $row ? (int) $row->entries_today : 0,
			'entries_this_week'   => $row ? (int) $row->entries_this_week : 0,
			'notifications_today' => $row ? (int) $row->notifications_today : 0,
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $stats,
			),
			200
		);
	}
}

// Initialize
new Waitlist_API();
