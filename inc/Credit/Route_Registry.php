<?php
/**
 * Rewards credit REST routes (contract B).
 *
 * Every route except `/credit/me` requires `manage_woocommerce` (consumer-key
 * auth via StoreDash_Auth_Handler). `/credit/me` is authenticated by the
 * X-StoreDash-Customer token through StoreDash_Customer_Auth_Bridge and only
 * requires a logged-in user.
 *
 * @package StoreDash\Credit
 * @since   1.17.0
 */

namespace StoreDash\Credit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Core\Base_Permissions;
use StoreDash\Credit\Controllers\Credit_Balance_Controller;
use StoreDash\Credit\Controllers\Credit_Grant_Controller;
use StoreDash\Credit\Controllers\Credit_Ledger_Controller;
use StoreDash\Credit\Controllers\Credit_Sync_Controller;
use WP_REST_Server;

/**
 * Registers `storedash/v1/credit/*`.
 *
 * @since 1.17.0
 */
class Route_Registry {

	/**
	 * Lazily-created controllers.
	 *
	 * @var array
	 */
	protected $controllers = array();

	/**
	 * Permissions.
	 *
	 * @var Base_Permissions
	 */
	protected $permissions;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->permissions = new Base_Permissions();
	}

	/**
	 * Controller factory.
	 *
	 * @param string $name sync|balance|ledger|grant.
	 * @return object|null
	 */
	protected function get_controller( string $name ) {
		if ( isset( $this->controllers[ $name ] ) ) {
			return $this->controllers[ $name ];
		}
		switch ( $name ) {
			case 'sync':
				$this->controllers[ $name ] = new Credit_Sync_Controller();
				break;
			case 'balance':
				$this->controllers[ $name ] = new Credit_Balance_Controller();
				break;
			case 'ledger':
				$this->controllers[ $name ] = new Credit_Ledger_Controller();
				break;
			case 'grant':
				$this->controllers[ $name ] = new Credit_Grant_Controller();
				break;
			default:
				return null;
		}
		return $this->controllers[ $name ];
	}

	/**
	 * Register routes.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace = 'storedash/v1' ): void {
		$manage = array( $this->permissions, 'check_permission' );

		register_rest_route(
			$namespace,
			'/credit/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'sync' )->sync( $request );
				},
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$namespace,
			'/credit/balance',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'balance' )->balance( $request );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'email' => array(
						'description'       => 'Customer email.',
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/credit/ledger',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'ledger' )->feed( $request );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'since'    => array(
						'description'       => 'ISO-8601 datetime; only rows created at or after it.',
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 200,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/credit/grant',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'grant' )->grant( $request );
				},
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$namespace,
			'/credit/adjust',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'grant' )->adjust( $request );
				},
				'permission_callback' => $manage,
			)
		);

		// Customer-facing: authenticated by the X-StoreDash-Customer token (see
		// StoreDash_Customer_Auth_Bridge) or a normal WP login cookie.
		register_rest_route(
			$namespace,
			'/credit/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'balance' )->me( $request );
				},
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}
}
