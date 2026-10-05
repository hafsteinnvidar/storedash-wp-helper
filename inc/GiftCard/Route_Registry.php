<?php
/**
 * Gift card REST routes (contract B / C).
 *
 * Every route requires `manage_woocommerce` (consumer-key auth via
 * StoreDash_Auth_Handler). There is no public route: shoppers apply codes
 * through the Store API extension, which is rate limited.
 *
 * @package StoreDash\GiftCard
 * @since   1.24.0
 */

namespace StoreDash\GiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Core\Base_Permissions;
use StoreDash\GiftCard\Controllers\Gift_Card_Action_Controller;
use StoreDash\GiftCard\Controllers\Gift_Card_Feed_Controller;
use StoreDash\GiftCard\Controllers\Gift_Card_Sync_Controller;
use WP_REST_Server;

/**
 * Registers `storedash/v1/gift-cards/*`.
 *
 * @since 1.24.0
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
	 * @param string $name sync|feed|action.
	 * @return object
	 */
	protected function controller( string $name ) {
		if ( ! isset( $this->controllers[ $name ] ) ) {
			switch ( $name ) {
				case 'sync':
					$this->controllers[ $name ] = new Gift_Card_Sync_Controller();
					break;
				case 'feed':
					$this->controllers[ $name ] = new Gift_Card_Feed_Controller();
					break;
				default:
					$this->controllers[ $name ] = new Gift_Card_Action_Controller();
			}
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
		$paging = array(
			'since'    => array(
				'description'       => 'ISO-8601 datetime.',
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
		);
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		$routes = array(
			array( '/gift-cards/sync', WP_REST_Server::CREATABLE, 'sync', 'sync', array() ),
			array( '/gift-cards', WP_REST_Server::READABLE, 'feed', 'cards', $paging ),
			array( '/gift-cards/ledger', WP_REST_Server::READABLE, 'feed', 'ledger', $paging ),
			array( '/gift-cards/issue', WP_REST_Server::CREATABLE, 'action', 'issue', array() ),
			array( '/gift-cards/(?P<id>\d+)', WP_REST_Server::READABLE, 'feed', 'get', $id_arg ),
			array( '/gift-cards/(?P<id>\d+)/adjust', WP_REST_Server::CREATABLE, 'action', 'adjust', $id_arg ),
			array( '/gift-cards/(?P<id>\d+)/disable', WP_REST_Server::CREATABLE, 'action', 'disable', $id_arg ),
			array( '/gift-cards/(?P<id>\d+)/enable', WP_REST_Server::CREATABLE, 'action', 'enable', $id_arg ),
			array( '/gift-cards/(?P<id>\d+)/resend', WP_REST_Server::CREATABLE, 'action', 'resend', $id_arg ),
		);

		foreach ( $routes as list( $route, $methods, $controller, $method, $args ) ) {
			register_rest_route(
				$namespace,
				$route,
				array(
					'methods'             => $methods,
					'callback'            => function ( $request ) use ( $controller, $method ) {
						return $this->controller( $controller )->$method( $request );
					},
					'permission_callback' => $manage,
					'args'                => $args,
				)
			);
		}
	}
}
