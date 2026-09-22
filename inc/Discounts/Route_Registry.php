<?php
/**
 * Discounts Route Registry
 *
 * All discounts now use dynamic pricing (no static sale_price writes).
 * Prices are calculated on-the-fly via WooCommerce hooks.
 *
 * @package StoreDash\Discounts
 * @since   1.0.0
 */

namespace StoreDash\Discounts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Discounts\Controllers\Discounts_Sync_Controller;
use StoreDash\Discounts\Controllers\Discounts_Delete_Controller;
use StoreDash\Discounts\Controllers\Discounts_Status_Controller;
use StoreDash\Discounts\Controllers\Discounts_Toggle_Controller;
use StoreDash\Core\Base_Permissions;
use WP_REST_Server;

/**
 * Registers all discount-related REST API routes.
 *
 * Uses lazy loading to only instantiate controllers when their endpoint is actually called.
 * This prevents memory overhead from creating 6 controllers + their dependencies on every request.
 *
 * @since 1.0.0
 */
class Route_Registry {

	/**
	 * Version for cache busting - increment when code changes.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const VERSION = '3.0.0';

	/**
	 * Controllers instances (lazy loaded).
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $controllers = array();

	/**
	 * Permissions handler.
	 *
	 * @since 1.0.0
	 * @var Base_Permissions
	 */
	protected $permissions;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->permissions = new Base_Permissions();
		// Controllers are now lazy-loaded via get_controller() - no eager instantiation
	}

	/**
	 * Get a controller instance (lazy loading).
	 *
	 * Only instantiates the controller when it's actually needed,
	 * preventing memory overhead from creating unused controllers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Controller name.
	 * @return object|null Controller instance or null if not found.
	 */
	protected function get_controller( $name ) {
		// Return cached instance if already created
		if ( isset( $this->controllers[ $name ] ) ) {
			return $this->controllers[ $name ];
		}

		// Create controller on demand
		switch ( $name ) {
			case 'sync':
				$this->controllers[ $name ] = new Discounts_Sync_Controller();
				break;
			case 'delete':
				$this->controllers[ $name ] = new Discounts_Delete_Controller();
				break;
			case 'status':
				$this->controllers[ $name ] = new Discounts_Status_Controller();
				break;
			case 'toggle':
				$this->controllers[ $name ] = new Discounts_Toggle_Controller();
				break;
			default:
				return null;
		}

		return $this->controllers[ $name ];
	}

	/**
	 * Register all routes.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @param string $namespace The namespace to register routes under.
	 */
	protected function register_routes_for_namespace( $namespace ) {
		$rest_base = 'discounts';

		// Shared id arg for the /{id}/ routes. The route regex already
		// constrains it to digits; this adds schema documentation + absint.
		$id_arg = array(
			'description'       => 'Local WP discount rule ID.',
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);

		// Sync discount from Supabase (uses lazy-loaded controller).
		// NOTE: args here mirror (not replace) the controller's in-callback
		// validation — the callback parses the raw JSON body itself, so it
		// stays the source of truth. Enums/required match validate_discount_data
		// exactly; looser fields (amount, enabled, …) are deliberately not
		// type-constrained here to avoid rejecting representations the
		// callback already accepts.
		register_rest_route(
			$namespace,
			'/' . $rest_base . '/sync',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => function ( $request ) {
						return $this->get_controller( 'sync' )->sync_discount( $request );
					},
					'permission_callback' => array( $this->permissions, 'check_permission' ),
					'args'                => array(
						'supabase_id'   => array(
							'description' => 'Supabase discount row ID (source of truth).',
							'required'    => true,
						),
						'rule_type'     => array(
							'description' => 'Discount rule type.',
							'type'        => 'string',
							'enum'        => array( 'store_wide', 'product', 'category', 'tag', 'brand', 'bogo', 'quantity' ),
						),
						'discount_type' => array(
							'description' => 'How the discount amount is applied.',
							'type'        => 'string',
							'enum'        => array( 'percentage', 'fixed_amount', 'fixed_price' ),
						),
					),
				),
			)
		);

		// Get discount status
		register_rest_route(
			$namespace,
			'/' . $rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function ( $request ) {
						return $this->get_controller( 'status' )->get_status( $request );
					},
					'permission_callback' => array( $this->permissions, 'check_permission' ),
				),
			)
		);

		// Toggle discount enabled/disabled
		register_rest_route(
			$namespace,
			'/' . $rest_base . '/(?P<id>[\d]+)/toggle',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => function ( $request ) {
						return $this->get_controller( 'toggle' )->toggle_discount( $request );
					},
					'permission_callback' => array( $this->permissions, 'check_permission' ),
					'args'                => array(
						'id'      => $id_arg,
						'enabled' => array(
							'description' => 'Whether the discount should be enabled. Falsy/absent disables.',
							'type'        => 'boolean',
						),
					),
				),
			)
		);

		// Delete discount completely
		register_rest_route(
			$namespace,
			'/' . $rest_base . '/(?P<id>[\d]+)/delete',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => function ( $request ) {
						return $this->get_controller( 'delete' )->delete_discount( $request );
					},
					'permission_callback' => array( $this->permissions, 'check_permission' ),
					'args'                => array(
						'id' => $id_arg,
					),
				),
			)
		);
	}
}
