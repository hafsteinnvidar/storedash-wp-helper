<?php
declare(strict_types=1);

/**
 * Products Manager
 *
 * @package StoreDash\Products
 * @since   2.0.0
 */

namespace StoreDash\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main entry point for the products functionality.
 *
 * @since 2.0.0
 */
class Products_Manager {

	/**
	 * StoreDash meta flag for scheduled publishing.
	 */
	private const SCHEDULE_FLAG_META_KEY = '_storedash_schedule_publish';

	/**
	 * StoreDash meta value holding the requested publish datetime in UTC.
	 */
	private const SCHEDULE_DATE_META_KEY = '_storedash_publish_date_gmt';

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		// Route registry removed - using native WooCommerce endpoints instead
	}

	/**
	 * Initialize the products module.
	 *
	 * @since 2.0.0
	 */
	public function init() {
		// Use WooCommerce's native endpoints and just extend them via hooks

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			\StoreDash_Helpers::log_message( 'Products_Manager init()', 'info' );
		}

		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'handle_scheduled_publish_on_save' ), 250, 3 );

		// Keep YouTube/Vimeo iframes that WooCommerce's description kses strips.
		( new Video_Embeds() )->init();

		// The only Storedash-owned product route: the time-budgeted bulk writer
		// (POST /storedash/v1/products/bulk). Everything else stays on wc/v3.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the product REST routes.
	 *
	 * @since 1.16.0
	 */
	public function register_routes(): void {
		( new Route_Registry() )->register_routes();
	}

	/**
	 * Convert StoreDash scheduling markers into a real WordPress future post.
	 *
	 * @param WC_Product      $product  Product object.
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating Whether creating or updating.
	 */
	public function handle_scheduled_publish_on_save( $product, $request, $creating ) {
		$publish_date_gmt = $this->extract_scheduled_publish_date_gmt( $request );

		if ( null === $publish_date_gmt ) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! $product_id ) {
			return;
		}

		$post_date = get_date_from_gmt( $publish_date_gmt );

		$update_result = wp_update_post(
			array(
				'ID'            => $product_id,
				'post_status'   => 'future',
				'post_date'     => $post_date,
				'post_date_gmt' => $publish_date_gmt,
			),
			true
		);

		if ( is_wp_error( $update_result ) ) {
			\StoreDash_Helpers::log_message(
				sprintf(
					'[Schedule] Failed to schedule product %d: %s',
					$product_id,
					$update_result->get_error_message()
				),
				'error'
			);
			return;
		}

		delete_post_meta( $product_id, self::SCHEDULE_FLAG_META_KEY );
		delete_post_meta( $product_id, self::SCHEDULE_DATE_META_KEY );

		// Keep the in-memory product object aligned with the post update so the
		// REST response reflects the scheduled state.
		$product->set_status( 'future' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			\StoreDash_Helpers::log_message(
				sprintf(
					'[Schedule] Product %d scheduled for %s GMT (%s local) during %s',
					$product_id,
					$publish_date_gmt,
					$post_date,
					$creating ? 'create' : 'update'
				),
				'info'
			);
		}
	}

	/**
	 * Extract a scheduled publish datetime from StoreDash meta markers.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null GMT datetime in MySQL format or null when not requested.
	 */
	private function extract_scheduled_publish_date_gmt( $request ) {
		$meta_data = $request->get_param( 'meta_data' );

		if ( ! is_array( $meta_data ) ) {
			return null;
		}

		$should_schedule = false;
		$publish_at      = null;

		foreach ( $meta_data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( ! isset( $entry['key'] ) ) {
				continue;
			}

			$key   = (string) $entry['key'];
			$value = $entry['value'] ?? null;

			if ( self::SCHEDULE_FLAG_META_KEY === $key ) {
				$should_schedule = in_array( $value, array( 'yes', 'true', true, 1, '1' ), true );
			}

			if ( self::SCHEDULE_DATE_META_KEY === $key && is_string( $value ) && '' !== trim( $value ) ) {
				$publish_at = trim( $value );
			}
		}

		if ( ! $should_schedule || null === $publish_at ) {
			return null;
		}

		$timestamp = strtotime( $publish_at );

		if ( false === $timestamp ) {
			\StoreDash_Helpers::log_message(
				sprintf( '[Schedule] Invalid publish date received from StoreDash: %s', $publish_at ),
				'warning'
			);
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
