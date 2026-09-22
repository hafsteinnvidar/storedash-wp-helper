<?php
/**
 * Base Permissions
 *
 * @package StoreDash\Core
 * @since   2.1.0
 */

namespace StoreDash\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Base_Permissions {

	/**
	 * Check if a given request has access to the endpoint.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return bool True if the request has access, false otherwise.
	 */
	public function check_permission( $request = null ) {
		return current_user_can( 'manage_woocommerce' );
	}
}
