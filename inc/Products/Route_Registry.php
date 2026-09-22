<?php
declare(strict_types=1);

/**
 * Products Route Registry
 *
 * @package StoreDash\Products
 * @since   1.16.0
 */

namespace StoreDash\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Core\Base_Permissions;
use StoreDash\Products\Controllers\Products_Bulk_Controller;
use WP_REST_Server;

/**
 * Registers the product REST routes owned by Storedash.
 *
 * Product CRUD stays on the native `wc/v3/products` endpoints; only the
 * time-budgeted bulk writer lives here.
 *
 * @since 1.16.0
 */
class Route_Registry {

	/**
	 * Permissions handler.
	 *
	 * @var Base_Permissions
	 */
	protected $permissions;

	/**
	 * Bulk controller.
	 *
	 * @var Products_Bulk_Controller
	 */
	protected $bulk;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->permissions = new Base_Permissions();
		$this->bulk        = new Products_Bulk_Controller();
	}

	/**
	 * Register all routes under the Storedash namespace.
	 *
	 * @param string $namespace Namespace to register under.
	 */
	public function register_routes( string $namespace = 'storedash/v1' ): void {
		register_rest_route(
			$namespace,
			'/products/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this->bulk, 'handle' ),
					'permission_callback' => array( $this->permissions, 'check_permission' ),
					'args'                => $this->bulk->get_args(),
				),
			)
		);
	}
}
