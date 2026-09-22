<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StoreDash API - Smart Coupons Controller
 *
 * Surfaces per-customer store-credit balances that WooCommerce core does not
 * expose server-to-server: `/wc/v3/users/me/store-credits` is scoped to the
 * CURRENT session user only, so the dashboard has no way to look up an
 * arbitrary customer's balance by email. This reads shop_coupon posts with
 * discount_type=smart_coupon whose email_restrictions contain the requested
 * email, and sums the amount across valid (non-expired, amount>0) coupons.
 *
 * Read-only. Depends on the Smart Coupons (StoreApps) plugin; when it is not
 * active, responds with smart_coupons_active:false and an empty balance.
 */
class StoreDash_API_Smart_Coupons {

	const NAMESPACE = 'storedash/v1';

	/**
	 * Constructor — self-registers routes (mirrors Payment_Gateways / Shipment_Tracking).
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the smart-coupons routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/smart-coupons/credits',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_credits' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'email' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_email( $value );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/smart-coupons/options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_options' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/**
	 * Resolve the store-credit balance + coupons for a customer email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_credits( $request ) {
		if ( ! class_exists( 'WC_Smart_Coupons' ) ) {
			return rest_ensure_response(
				array(
					'smart_coupons_active' => false,
					'email'                => $request['email'],
					'balance'              => '0',
					'currency'             => get_woocommerce_currency(),
					'coupons'              => array(),
				)
			);
		}

		$email   = sanitize_email( $request['email'] );
		$coupons = array();
		$balance = 0.0;

		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'discount_type',
						'value' => 'smart_coupon',
					),
					array(
						'key'     => 'customer_email',
						'value'   => '"' . $email . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $ids as $coupon_id ) {
			$coupon  = new WC_Coupon( $coupon_id );
			$amount  = (float) $coupon->get_amount();
			$expires = $coupon->get_date_expires();
			$valid   = $amount > 0 && ( ! $expires || $expires->getTimestamp() > time() );
			if ( $valid ) {
				$balance += $amount;
			}
			$coupons[] = array(
				'id'              => $coupon_id,
				'code'            => $coupon->get_code(),
				'amount'          => (string) $amount,
				'original_amount' => (string) $coupon->get_meta( 'wc_sc_original_amount' ),
				'expires'         => $expires ? $expires->date( 'c' ) : null,
				'is_valid'        => $valid,
			);
		}

		return rest_ensure_response(
			array(
				'smart_coupons_active' => true,
				'email'                => $email,
				'balance'              => (string) $balance,
				'currency'             => get_woocommerce_currency(),
				'coupons'              => $coupons,
			)
		);
	}

	/**
	 * Resolve restriction options for the Smart Coupons restrictions UI:
	 * user roles + shipping methods. Payment gateways come from the
	 * existing `/storedash/v1/payment-gateways` endpoint; brands from
	 * StoreDash's own DB — neither is duplicated here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_options( $request ) {
		$roles = array();
		foreach ( wp_roles()->roles as $role_id => $role ) {
			$roles[] = array(
				'id'    => $role_id,
				'label' => translate_user_role( $role['name'] ),
			);
		}

		$methods = array();
		if ( function_exists( 'WC' ) && WC() && WC()->shipping() ) {
			foreach ( WC()->shipping()->get_shipping_methods() as $method_id => $method ) {
				$methods[] = array(
					'id'    => $method_id,
					'label' => $method->get_method_title(),
				);
			}
		}

		return rest_ensure_response(
			array(
				'roles'            => $roles,
				'shipping_methods' => $methods,
			)
		);
	}
}

// Initialize (self-registering, mirrors Payment_Gateways).
new StoreDash_API_Smart_Coupons();
