<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StoreDash API - Shipment Tracking Controller
 *
 * Records tracking for a StoreDash-created shipment via the WooCommerce Shipment
 * Tracking extension (`wc_st_add_tracking_number()` → `_wc_shipment_tracking_items`),
 * which is the storage the official WooCommerce ShipStation plugin uses. When
 * that extension is absent it falls back to the legacy `_tracking_*` meta —
 * mirroring exactly what the ShipStation plugin's own shipnotify handler does.
 *
 * `wc_st_add_tracking_number()` is PHP-only (not reachable via WC REST), which is
 * why StoreDash routes ShipStation tracking through this helper. This endpoint
 * only records tracking locally — it never calls any carrier/ShipStation API.
 */
class StoreDash_API_Shipment_Tracking {

	const NAMESPACE = 'storedash/v1';

	/**
	 * Constructor — self-registers routes (mirrors Waitlist_API).
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the shipment-tracking route.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/shipment-tracking',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_tracking' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'id'                   => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
						'sanitize_callback' => 'absint',
					),
					'custom_tracking_link' => array(
						'required'          => false,
						'type'              => 'string',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) || is_null( $value );
						},
						'sanitize_callback' => array( __CLASS__, 'sanitize_tracking_link' ),
					),
				),
			)
		);
	}

	/**
	 * Sanitize an optional custom tracking link: http(s) URLs only, else ''.
	 *
	 * @param mixed $value Raw request value.
	 * @return string
	 */
	public static function sanitize_tracking_link( $value ): string {
		$url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Record tracking for an order.
	 *
	 * When `custom_tracking_link` is supplied the tracking is stored as a *custom*
	 * provider (provider name + link) in the Shipment Tracking extension instead of
	 * a predefined carrier — used for Icelandic carriers the extension doesn't know.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_tracking( $request ) {
		$order_id = absint( $request['id'] );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'storedash' ),
				array( 'status' => 404 )
			);
		}

		$tracking_number = sanitize_text_field( (string) $request->get_param( 'tracking_number' ) );
		if ( '' === $tracking_number ) {
			return new WP_Error(
				'missing_tracking_number',
				__( 'A tracking number is required.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		$provider_raw = sanitize_text_field( (string) $request->get_param( 'tracking_provider' ) );
		$provider     = strtolower( $provider_raw );
		$custom_link  = self::sanitize_tracking_link( $request->get_param( 'custom_tracking_link' ) );
		$raw_date     = $request->get_param( 'date_shipped' );
		$timestamp    = ( is_numeric( $raw_date ) && (int) $raw_date > 0 ) ? (int) $raw_date : time();

		$method = 'meta';
		if ( function_exists( 'wc_st_add_tracking_number' ) ) {
			// Idempotency guard (mirrors the Dropp endpoint): a repeat call with
			// the same number+provider must not append a duplicate tracking item.
			// The legacy meta branch needs no guard — update_meta_data overwrites.
			$existing_items = $order->get_meta( '_wc_shipment_tracking_items', true );
			if ( is_array( $existing_items ) ) {
				foreach ( $existing_items as $item ) {
					$item_providers = array(
						strtolower( (string) ( $item['tracking_provider'] ?? '' ) ),
						strtolower( (string) ( $item['custom_tracking_provider'] ?? '' ) ),
					);
					if ( isset( $item['tracking_number'] )
						&& $item['tracking_number'] === $tracking_number
						&& in_array( $provider, $item_providers, true ) ) {
						return new WP_REST_Response(
							array(
								'success' => true,
								'method'  => 'shipment_tracking',
								'skipped' => 'already_exists',
							),
							200
						);
					}
				}
			}

			// WC Shipment Tracking extension — canonical store (_wc_shipment_tracking_items).
			if ( '' !== $custom_link ) {
				// Assumed extension signature (source not vendored here):
				// wc_st_add_tracking_number( $order_id, $tracking_number, $provider, $date_shipped = null, $custom_url = false ).
				// A truthy 5th arg makes the extension store the item as
				// custom_tracking_provider + custom_tracking_link instead of a
				// predefined tracking_provider slug, so keep the merchant-facing
				// provider casing (it is a display name here, not a slug).
				wc_st_add_tracking_number( $order_id, $tracking_number, $provider_raw, $timestamp, $custom_link );
			} else {
				wc_st_add_tracking_number( $order_id, $tracking_number, $provider, $timestamp );
			}
			$method = 'shipment_tracking';
		} else {
			// Legacy fallback (mirrors the ShipStation plugin for Shipment Tracking < 1.4.0).
			$order->update_meta_data( '_tracking_provider', $provider );
			$order->update_meta_data( '_tracking_number', $tracking_number );
			$order->update_meta_data( '_date_shipped', $timestamp );
			if ( '' !== $custom_link ) {
				$order->update_meta_data( '_tracking_link', $custom_link );
			}
			$order->save_meta_data();
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'method'  => $method,
			),
			201
		);
	}
}

// Initialize (self-registering, mirrors Waitlist_API).
new StoreDash_API_Shipment_Tracking();
