<?php
/**
 * Abstract Carts Controller
 *
 * @package StoreDash\Carts
 * @since   2.0.0
 */

namespace StoreDash\Carts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Carts\Contracts\Carts_Controller_Interface;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Abstract base class for all cart controllers.
 *
 * Contains common functionality to ensure backward compatibility
 * and consistent behavior across all cart operations.
 *
 * @since 2.0.0
 */
abstract class Abstract_Carts_Controller implements Carts_Controller_Interface {

	/**
	 * Endpoint namespace.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	protected $namespace = 'storedash/v1';

	/**
	 * Route base.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	protected $rest_base = 'carts';

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		// Initialization if needed
	}

	/**
	 * Check if a given request has access to the endpoint.
	 *
	 * Maintains backward compatibility with original permission checks.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return bool True if the request has access, false otherwise.
	 */
	public function check_permission( $request = null ) {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get the namespace for the controller.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_namespace() {
		return $this->namespace;
	}

	/**
	 * Get the base path for the controller.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_rest_base() {
		return $this->rest_base;
	}

	/**
	 * Get the carts table name.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	protected function get_table_name() {
		return storedash_get_cart_table_name();
	}
}
