<?php
/**
 * Checkout Route Registry
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Checkout\Controllers\Checkout_Settings_Controller;
use StoreDash\Checkout\Permissions\Checkout_Permissions;
use WP_REST_Server;

/**
 * Registers the checkout-optimization REST routes.
 *
 * @since 1.29.0
 */
class Route_Registry {

	/**
	 * Settings controller.
	 *
	 * @var Checkout_Settings_Controller
	 */
	protected $settings;

	/**
	 * Permissions handler.
	 *
	 * @var Checkout_Permissions
	 */
	protected $permissions;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->permissions = new Checkout_Permissions();
		$this->settings    = new Checkout_Settings_Controller();
	}

	/**
	 * Register all routes under a namespace.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( $namespace = 'storedash/v1' ): void {
		register_rest_route(
			$namespace,
			'/checkout/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->settings, 'get_settings' ),
					'permission_callback' => array( $this->permissions, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this->settings, 'update_settings' ),
					'permission_callback' => array( $this->permissions, 'check_permission' ),
				),
			)
		);
	}
}
