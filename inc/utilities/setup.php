<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hook to properly handle meta_data JSON strings (particularly for woobt_ids)
// This runs AFTER WooCommerce saves the product to fix JSON-encoded meta values
// The frontend sends complex objects as JSON strings, but WordPress plugins like
// WooCommerce Frequently Bought Together expect PHP arrays/objects, not JSON strings
add_action(
	'woocommerce_rest_insert_product_object',
	function ( $product, $request, $creating ) {
		// Get the product ID
		$product_id = $product->get_id();

		if ( ! $product_id ) {
			return;
		}

		// Check and decode complex meta fields directly from post meta.
		// Tabs keys included: YIKES Custom Product Tabs (and StoreDash's native
		// dual-written storedash_tabs) expect PHP arrays, not JSON strings.
		$woobt_keys = array( 'woobt_ids', '_woobt_ids', 'storedash_fbt_ids', 'storedash_tabs', 'yikes_woo_products_tabs' );

		foreach ( $woobt_keys as $key ) {
			$meta_value = get_post_meta( $product_id, $key, true );

			// If it's a JSON string, decode it and update
			if ( is_string( $meta_value ) && ! empty( $meta_value ) ) {
				$first_char = $meta_value[0];

				// Check if it looks like JSON
				if ( $first_char === '[' || $first_char === '{' ) {
					$decoded = json_decode( $meta_value, true );

					// If valid JSON, update the post meta with decoded array
					if ( json_last_error() === JSON_ERROR_NONE ) {
						update_post_meta( $product_id, $key, $decoded );
						StoreDash_Helpers::debug_log(
							'Decoded JSON meta',
							array(
								'product_id' => $product_id,
								'meta_key'   => $key,
								'original'   => $meta_value,
								'decoded'    => $decoded,
							)
						);
					}
				}
			}
		}
	},
	200,
	3
); // Priority 200 to run after WooCommerce completely finishes saving

class StoreDash_Setup {
	public function __construct() {
		// Reduce memory usage during StoreDash sync requests.
		// The StoreDash sync service (on its product-list calls) and the woo-dash
		// app send X-StoreDash-Source: true. When detected, we disable WP object
		// cache priming for WooCommerce product queries — this prevents
		// batch-loading ALL products' meta/terms into the object cache at once,
		// which is the primary cause of OOM on smaller servers. WooCommerce still
		// fetches meta per-product as needed; it just won't duplicate everything
		// into the object cache.
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( $this, 'optimize_sync_product_queries' ), 10, 2 );

		// Debug logging hooks (only register in debug mode)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_filter( 'rest_request_before_callbacks', array( $this, 'log_api_request' ), 999, 3 );
			add_filter( 'rest_request_after_callbacks', array( $this, 'log_api_response' ), 999, 3 );
		}
	}

	/**
	 * Reduce PHP memory for product queries during sync.
	 *
	 * WordPress normally batch-loads all post meta and term caches for every
	 * product in a query result. For a per_page=50 request this means ALL 50
	 * products' meta (potentially hundreds of rows each) are loaded into the
	 * WP object cache at once — doubling memory since the product objects hold
	 * their own copy too.
	 *
	 * Disabling the cache priming means meta/terms are loaded per-product on
	 * demand instead. Slightly more SQL queries, but dramatically lower peak
	 * memory — the difference between OOM and success on smaller servers.
	 *
	 * Only applies when the request comes from the StoreDash sync service
	 * (identified by X-StoreDash-Source header).
	 */
	public function optimize_sync_product_queries( $wp_query_args, $query_vars ) {
		if ( ! empty( $GLOBALS['storedash_request_active'] ) ) {
			$wp_query_args['update_post_meta_cache'] = false;
			$wp_query_args['update_post_term_cache'] = false;
		}
		return $wp_query_args;
	}

	/**
	 * Log API requests for debugging
	 */
	public function log_api_request( $response, $handler, $request ) {
		// Only log WooCommerce API requests
		if ( strpos( $request->get_route(), '/wc/' ) === 0 ) {
			StoreDash_Helpers::debug_log( 'API Request: ' . $request->get_method() . ' ' . $request->get_route() );
		}
		return $response;
	}

	/**
	 * Log API responses for debugging
	 */
	public function log_api_response( $response, $handler, $request ) {
		// Only log WooCommerce API requests
		if ( strpos( $request->get_route(), '/wc/' ) === 0 ) {
			$status = is_wp_error( $response ) ? 'ERROR' : 'SUCCESS';
			StoreDash_Helpers::debug_log( 'API Response: ' . $request->get_method() . ' ' . $request->get_route() . ' - ' . $status );
		}
		return $response;
	}
}

// Note: StoreDash_Setup is initialized by StoreDash_Bootstrap::init_components()
