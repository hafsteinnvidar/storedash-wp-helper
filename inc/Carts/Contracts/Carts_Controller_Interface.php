<?php
/**
 * Carts Controller Interface
 *
 * @package StoreDash\Carts\Contracts
 * @since   2.0.0
 */

namespace StoreDash\Carts\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Interface for all cart controllers.
 *
 * @since 2.0.0
 */
interface Carts_Controller_Interface {

	/**
	 * Check if a given request has access to the endpoint.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return bool|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function check_permission( $request );

	/**
	 * Get the namespace for the controller.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_namespace();

	/**
	 * Get the base path for the controller.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_rest_base();
}
