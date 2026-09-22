<?php
/**
 * Carts Route Registry
 *
 * @package StoreDash\Carts
 * @since   2.0.0
 */

namespace StoreDash\Carts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Carts\Controllers\Carts_Settings_Controller;
use StoreDash\Core\Base_Permissions;
use WP_REST_Server;

/**
 * Registers all cart-related REST API routes.
 *
 * @since 2.0.0
 */
class Route_Registry {

	/**
	 * Controllers instances.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	protected $controllers = array();

	/**
	 * Permissions handler.
	 *
	 * @since 2.0.0
	 * @var Base_Permissions
	 */
	protected $permissions;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		$this->permissions = new Base_Permissions();
		$this->init_controllers();
	}

	/**
	 * Initialize all controllers.
	 *
	 * @since 2.0.0
	 */
	protected function init_controllers() {
		$this->controllers = array(
			'settings' => new Carts_Settings_Controller(),
		);
	}

	/**
	 * Register all routes.
	 *
	 * @since 2.0.0
	 * @param array $namespaces Array of namespaces to register routes under (defaults to both legacy and modern).
	 */
	public function register_routes( $namespaces = null ) {
		// Default to both namespaces if not specified
		if ( $namespaces === null ) {
			$namespaces = array( 'storedash/v1' );
		}

		// Support single namespace for backward compatibility
		if ( ! is_array( $namespaces ) ) {
			$namespaces = array( $namespaces );
		}

		// Register routes under each namespace
		foreach ( $namespaces as $namespace ) {
			$this->register_routes_for_namespace( $namespace );
		}
	}

	/**
	 * Register routes for a specific namespace.
	 *
	 * @since 2.0.0
	 * @param string $namespace The namespace to register routes under.
	 */
	protected function register_routes_for_namespace( $namespace ) {

		// NOTE: the former POST /carts/{token}/recover route was removed — it was
		// an unused wp_mail stub. Production recovery flows through the worker's
		// Inngest pipeline → storedash-mail → public GET /recover-cart.

		// Cart settings (synced from dashboard)
		register_rest_route(
			$namespace,
			'/carts/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->controllers['settings'], 'get_settings' ),
					'permission_callback' => array( $this->permissions, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this->controllers['settings'], 'update_settings' ),
					'permission_callback' => array( $this->permissions, 'check_permission' ),
				),
			)
		);
	}
}
