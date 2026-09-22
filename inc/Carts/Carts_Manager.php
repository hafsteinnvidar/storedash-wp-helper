<?php
/**
 * Carts Manager
 *
 * @package StoreDash\Carts
 * @since   2.0.0
 */

namespace StoreDash\Carts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main manager class for the Carts module.
 *
 * @since 2.0.0
 */
class Carts_Manager {

	/**
	 * Route registry instance.
	 *
	 * @since 2.0.0
	 * @var Route_Registry
	 */
	protected $route_registry;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		$this->route_registry = new Route_Registry();
	}

	/**
	 * Initialize the carts module.
	 *
	 * @since 2.0.0
	 */
	public function init() {
		// Note: Routes are registered via the main API class, not directly here
		// This maintains backward compatibility with the existing structure
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 2.0.0
	 */
	public function register_routes() {
		$this->route_registry->register_routes();
	}

	/**
	 * Get the route registry instance.
	 *
	 * @since 2.0.0
	 *
	 * @return Route_Registry
	 */
	public function get_route_registry() {
		return $this->route_registry;
	}
}
