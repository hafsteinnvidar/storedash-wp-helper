<?php
/**
 * `GET /credit/balance?email=` (consumer key) and `GET /credit/me`
 * (customer token) — contract B.
 *
 * @package StoreDash\Credit\Controllers
 * @since   1.17.0
 */

namespace StoreDash\Credit\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Engine\Customer_Resolver;
use WP_REST_Request;

/**
 * Balance controller.
 *
 * @since 1.17.0
 */
class Credit_Balance_Controller extends Abstract_Credit_Controller {

	/**
	 * Balance for an arbitrary customer email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function balance( WP_REST_Request $request ) {
		$key = $this->customer_key_from_email( $request->get_param( 'email' ) );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return rest_ensure_response( $this->balance_payload( $key ) );
	}

	/**
	 * Balance for the authenticated customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function me( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->error( 'storedash_credit_unauthorized', 'Authentication required', 401 );
		}
		$key = Customer_Resolver::key_for_user( $user_id );
		if ( '' === $key ) {
			return $this->error( 'storedash_credit_no_email', 'Customer has no email', 400 );
		}
		return rest_ensure_response( $this->balance_payload( $key ) );
	}
}
