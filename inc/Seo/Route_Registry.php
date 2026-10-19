<?php
/**
 * SEO REST routes (`storedash/v1/seo/*`).
 *
 * Registered on every request — even when the module is off or another SEO
 * plugin is active — so Storedash can read status and migrate while Yoast /
 * RankMath is still installed. Every route requires `manage_woocommerce`
 * (consumer-key auth via StoreDash_Auth_Handler), like Discounts and Credit.
 *
 * @package StoreDash\Seo
 * @since   1.26.0
 */

namespace StoreDash\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Core\Base_Permissions;
use StoreDash\Seo\Controllers\Seo_Bulk_Controller;
use StoreDash\Seo\Controllers\Seo_Migrate_Controller;
use StoreDash\Seo\Controllers\Seo_Settings_Controller;
use StoreDash\Seo\Engine\Plugin_Conflict_Guard;
use WP_REST_Server;

/**
 * Registers the SEO routes; controllers are created lazily.
 *
 * @since 1.26.0
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
	 * Conflict guard shared with Seo_Manager.
	 *
	 * @var Plugin_Conflict_Guard
	 */
	protected $guard;

	/**
	 * Constructor.
	 *
	 * @param Plugin_Conflict_Guard $guard Guard.
	 */
	public function __construct( Plugin_Conflict_Guard $guard ) {
		$this->permissions = new Base_Permissions();
		$this->guard       = $guard;
	}

	/**
	 * Controller factory.
	 *
	 * @param string $name settings|bulk|migrate.
	 * @return object|null
	 */
	protected function get_controller( string $name ) {
		if ( isset( $this->controllers[ $name ] ) ) {
			return $this->controllers[ $name ];
		}
		switch ( $name ) {
			case 'settings':
				$this->controllers[ $name ] = new Seo_Settings_Controller( $this->guard );
				break;
			case 'bulk':
				$this->controllers[ $name ] = new Seo_Bulk_Controller();
				break;
			case 'migrate':
				$this->controllers[ $name ] = new Seo_Migrate_Controller();
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
			'/seo/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'settings' )->get_status( $request );
				},
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$namespace,
			'/seo/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function ( $request ) {
						return $this->get_controller( 'settings' )->get_settings( $request );
					},
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => function ( $request ) {
						return $this->get_controller( 'settings' )->put_settings( $request );
					},
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/seo/bulk-meta',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'bulk' )->bulk_meta( $request );
				},
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$namespace,
			'/seo/migrate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'migrate' )->migrate( $request );
				},
				'permission_callback' => $manage,
			)
		);
	}
}
