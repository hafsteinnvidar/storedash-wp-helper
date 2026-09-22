<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StoreDash API - Payment Gateways Controller
 *
 * Surfaces payment-gateway capability metadata that the WooCommerce REST API does
 * not expose: the store's installed gateways (enabled + disabled), whether each
 * supports API refunds, and the resolved gateway/transaction details for a single
 * order. StoreDash uses this to decide whether to offer an automatic (gateway)
 * refund and to link an order's payment out to the provider dashboard.
 *
 * WC_Payment_Gateway capabilities and transaction URLs are PHP-only (not reachable
 * through WC REST), which is why they are proxied through this helper. Read-only —
 * it never triggers a payment or a refund.
 */
class StoreDash_API_Payment_Gateways {

	const NAMESPACE = 'storedash/v1';

	/**
	 * Constructor — self-registers routes (mirrors Shipment_Tracking / Waitlist_API).
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the payment-gateway routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/payment-gateways',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_gateways' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/payment-meta',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order_payment_meta' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * List every payment gateway the store has, enabled or not.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_gateways( $request ) {
		$unavailable = self::woocommerce_unavailable();
		if ( $unavailable ) {
			return $unavailable;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$result   = array();

		foreach ( $gateways as $gateway ) {
			$result[] = self::format_gateway( $gateway );
		}

		return rest_ensure_response( array( 'gateways' => $result ) );
	}

	/**
	 * Resolve the gateway/transaction details for a single order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order_payment_meta( $request ) {
		$unavailable = self::woocommerce_unavailable();
		if ( $unavailable ) {
			return $unavailable;
		}

		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'storedash' ),
				array( 'status' => 404 )
			);
		}

		$gateway_id = (string) $order->get_payment_method();
		$gateways   = WC()->payment_gateways()->payment_gateways();
		$gateway    = ( '' !== $gateway_id && isset( $gateways[ $gateway_id ] ) ) ? $gateways[ $gateway_id ] : null;

		return rest_ensure_response( self::build_order_payment_meta( $order, $gateway ) );
	}

	/**
	 * Map a WC_Payment_Gateway to the store-level capability shape (Contract 1).
	 *
	 * Pure mapping (no WordPress/instance state beyond the gateway object) so the
	 * shape can be pinned by a standalone unit test.
	 *
	 * @param object $gateway A WC_Payment_Gateway (or test double).
	 * @return array
	 */
	public static function format_gateway( $gateway ) {
		return array(
			'id'               => (string) $gateway->id,
			'title'            => self::normalize_string( $gateway->get_title() ),
			'method_title'     => self::normalize_string( $gateway->get_method_title() ),
			'enabled'          => self::gateway_enabled( $gateway ),
			'supports_refunds' => (bool) $gateway->supports( 'refunds' ),
			'supports'         => self::gateway_supports( $gateway ),
		);
	}

	/**
	 * Build the per-order payment meta shape (Contract 2).
	 *
	 * A null $gateway models an order whose gateway is no longer installed/known:
	 * the order still carries its historic payment_method + title, but there is no
	 * live capability object, so refunds are reported unsupported and there is no
	 * transaction URL. Pure mapping for standalone unit testing.
	 *
	 * @param object      $order   A WC_Order (or test double).
	 * @param object|null $gateway The matching WC_Payment_Gateway, or null if unknown.
	 * @return array
	 */
	public static function build_order_payment_meta( $order, $gateway ) {
		$gateway_id     = (string) $order->get_payment_method();
		$transaction_id = self::normalize_string( $order->get_transaction_id() );

		if ( null === $gateway ) {
			return array(
				'gateway_id'       => '' !== $gateway_id ? $gateway_id : null,
				'gateway_title'    => self::normalize_string( $order->get_payment_method_title() ),
				'supports_refunds' => false,
				'gateway_enabled'  => false,
				'transaction_id'   => $transaction_id,
				'transaction_url'  => null,
			);
		}

		return array(
			'gateway_id'       => '' !== $gateway_id ? $gateway_id : (string) $gateway->id,
			'gateway_title'    => self::normalize_string( $gateway->get_method_title() ),
			'supports_refunds' => (bool) $gateway->supports( 'refunds' ),
			'gateway_enabled'  => self::gateway_enabled( $gateway ),
			'transaction_id'   => $transaction_id,
			'transaction_url'  => self::normalize_url( $gateway->get_transaction_url( $order ) ),
		);
	}

	/**
	 * Whether a gateway's `enabled` option is on.
	 *
	 * @param object $gateway Gateway object.
	 * @return bool
	 */
	private static function gateway_enabled( $gateway ) {
		return isset( $gateway->enabled ) && 'yes' === $gateway->enabled;
	}

	/**
	 * Normalize a gateway's `supports` list to a clean, de-duplicated string array.
	 *
	 * @param object $gateway Gateway object.
	 * @return string[]
	 */
	private static function gateway_supports( $gateway ) {
		$supports = ( isset( $gateway->supports ) && is_array( $gateway->supports ) ) ? $gateway->supports : array();
		$clean    = array();

		foreach ( $supports as $feature ) {
			if ( ! is_scalar( $feature ) ) {
				continue;
			}
			$feature = (string) $feature;
			if ( '' !== $feature ) {
				$clean[] = $feature;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Coerce a value to a non-empty string, or null.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function normalize_string( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = (string) $value;
		return '' === $value ? null : $value;
	}

	/**
	 * Sanitize a transaction URL, normalizing empty to null (Contract 2).
	 *
	 * @param mixed $value Raw URL from get_transaction_url().
	 * @return string|null
	 */
	private static function normalize_url( $value ) {
		$value = self::normalize_string( $value );
		return null === $value ? null : esc_url_raw( $value );
	}

	/**
	 * Guard for WooCommerce being active.
	 *
	 * @return WP_Error|null WP_Error (503) when WooCommerce is unavailable, else null.
	 */
	private static function woocommerce_unavailable() {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error(
				'woocommerce_inactive',
				__( 'WooCommerce is not active.', 'storedash' ),
				array( 'status' => 503 )
			);
		}
		return null;
	}
}

// Initialize (self-registering, mirrors Shipment_Tracking).
new StoreDash_API_Payment_Gateways();
