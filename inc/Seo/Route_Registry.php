<?php
/**
 * SEO REST routes (`storedash/v1/seo/*`).
 *
 * Registered on every request — even when the module is off or another SEO
 * plugin is active — so Storedash can read status and migrate while Yoast /
 * RankMath is still installed. Every route requires `manage_woocommerce`
 * (consumer-key auth via StoreDash_Auth_Handler), like Discounts and Credit —
 * except `GET /seo/catalog.txt` (B5), a public plain-text catalog for AI
 * agents that 404s unless `llms_txt_enabled` is on and `?token=` matches.
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
use StoreDash\Seo\Controllers\Seo_Catalog_Controller;
use StoreDash\Seo\Controllers\Seo_Migrate_Controller;
use StoreDash\Seo\Controllers\Seo_Redirects_Controller;
use StoreDash\Seo\Controllers\Seo_Settings_Controller;
use StoreDash\Seo\Engine\Bot_Crawl_Logger;
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
	 * @param string $name settings|bulk|migrate|redirects|catalog.
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
			case 'redirects':
				$this->controllers[ $name ] = new Seo_Redirects_Controller();
				break;
			case 'catalog':
				$this->controllers[ $name ] = new Seo_Catalog_Controller();
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

		// Public plain-text catalog linked from /llms.txt (B5). Public product
		// data only; 404 unless the module + llms_txt_enabled are on.
		register_rest_route(
			$namespace,
			'/seo/catalog\.txt',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'catalog' )->get_catalog( $request );
				},
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Seo_Catalog_Controller::MAX_PAGE,
						'default' => 1,
					),
					'token' => array(
						'type'      => 'string',
						'maxLength' => 64,
					),
				),
			)
		);

		// B5 debug: pending crawl keys, last flush, failures, webhook configured.
		register_rest_route(
			$namespace,
			'/seo/crawl-stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function ( $request ) {
					return rest_ensure_response( Bot_Crawl_Logger::stats() );
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

		register_rest_route(
			$namespace,
			'/seo/redirects',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function ( $request ) {
						return $this->get_controller( 'redirects' )->get_redirects( $request );
					},
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => function ( $request ) {
						return $this->get_controller( 'redirects' )->put_redirects( $request );
					},
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			$namespace,
			'/seo/redirects/hits',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function ( $request ) {
					return $this->get_controller( 'redirects' )->get_hits( $request );
				},
				'permission_callback' => $manage,
			)
		);
	}
}
