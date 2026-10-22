<?php
declare(strict_types=1);

/**
 * Swatches REST controller.
 *
 * @package StoreDash\Products\Swatches
 * @since   1.29.0
 */

namespace StoreDash\Products\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Core\Base_Permissions;
use WP_REST_Server;

/**
 * Routes under `storedash/v1` (consumer-key auth + `manage_woocommerce`):
 *
 * - `GET  swatches/status`
 * - `POST swatches/provider`                      `{ provider: "emran"|"cartflows"|null }`
 * - `POST swatches/attributes/{attribute_id}/type` `{ type: select|color|image|button|radio }`
 *
 * The type route exists because `wc/v3/products/attributes` validates `type`
 * against `product_attributes_type_selector`, which CartFlows only fills on
 * its admin screen — so a plain REST write of `label` silently becomes
 * `select`. Here the active provider's types are merged in for the write.
 *
 * @since 1.29.0
 */
class Swatches_Controller {

	/** Normalized attribute types accepted on the wire. */
	public const TYPES = array( 'select', 'color', 'image', 'button', 'radio' );

	/**
	 * Provider resolver.
	 *
	 * @var Provider_Resolver
	 */
	private $resolver;

	/**
	 * `function( int $id ): ?object` — wc_get_attribute().
	 *
	 * @var callable
	 */
	private $get_attribute;

	/**
	 * `function( int $id, array $args ): int|\WP_Error` — wc_update_attribute().
	 *
	 * @var callable
	 */
	private $update_attribute;

	/**
	 * Constructor.
	 *
	 * @param Provider_Resolver $resolver         Provider resolver.
	 * @param callable|null     $get_attribute    Override for wc_get_attribute (tests).
	 * @param callable|null     $update_attribute Override for the attribute write (tests).
	 */
	public function __construct( Provider_Resolver $resolver, ?callable $get_attribute = null, ?callable $update_attribute = null ) {
		$this->resolver         = $resolver;
		$this->get_attribute    = $get_attribute ?? 'wc_get_attribute';
		$this->update_attribute = $update_attribute ?? array( $this, 'write_attribute' );
	}

	/**
	 * Register routes.
	 *
	 * @param string $namespace REST namespace.
	 */
	public function register_routes( string $namespace = 'storedash/v1' ): void {
		$permissions = new Base_Permissions();

		register_rest_route(
			$namespace,
			'/swatches/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $permissions, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/swatches/provider',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_provider' ),
					'permission_callback' => array( $permissions, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/swatches/attributes/(?P<attribute_id>\d+)/type',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_attribute_type' ),
					'permission_callback' => array( $permissions, 'check_permission' ),
					'args'                => array(
						'attribute_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'type'         => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => self::TYPES,
						),
					),
				),
			)
		);
	}

	/**
	 * `GET swatches/status`.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status() {
		return new \WP_REST_Response( $this->resolver->status(), 200 );
	}

	/**
	 * `POST swatches/provider`. Saving a provider whose plugin is not active is
	 * allowed (it takes effect once active); status reports the effective one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_provider( $request ) {
		$params = $request->get_json_params();
		if ( is_array( $params ) && array_key_exists( 'provider', $params ) ) {
			$provider = $params['provider'];
		} elseif ( $request->has_param( 'provider' ) ) {
			$provider = $request->get_param( 'provider' );
		} else {
			return new \WP_Error(
				'storedash_invalid_swatch_provider',
				__( 'provider is required (use null to clear).', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->save_provider( $provider );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $this->resolver->status(), 200 );
	}

	/**
	 * Persist the preferred provider (null / "" clears it).
	 *
	 * @param mixed $provider Raw provider value.
	 * @return true|\WP_Error
	 */
	public function save_provider( $provider ) {
		if ( null === $provider || '' === $provider ) {
			delete_option( Provider_Resolver::OPTION );
			return true;
		}

		if ( ! is_string( $provider ) || null === $this->resolver->get( $provider ) ) {
			return new \WP_Error(
				'storedash_invalid_swatch_provider',
				/* translators: %s: allowed provider slugs. */
				sprintf( __( 'provider must be one of: %s, or null.', 'storedash' ), implode( ', ', $this->resolver->slugs() ) ),
				array( 'status' => 400 )
			);
		}

		update_option( Provider_Resolver::OPTION, $provider, true );
		return true;
	}

	/**
	 * `POST swatches/attributes/{attribute_id}/type`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_attribute_type( $request ) {
		$result = $this->apply_attribute_type( (int) $request->get_param( 'attribute_id' ), (string) $request->get_param( 'type' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Validate and write a normalized attribute type through the active provider.
	 *
	 * @param int    $attribute_id WooCommerce attribute id.
	 * @param string $type         Normalized type.
	 * @return array{id: int, type: string}|\WP_Error
	 */
	public function apply_attribute_type( int $attribute_id, string $type ) {
		$provider = $this->resolver->active();
		if ( null === $provider ) {
			return new \WP_Error(
				'storedash_no_swatch_provider',
				__( 'No variation swatches plugin is active on this store.', 'storedash' ),
				array( 'status' => 409 )
			);
		}

		$native = in_array( $type, self::TYPES, true ) ? $provider->to_native_type( $type ) : null;
		if ( null === $native ) {
			return new \WP_Error(
				'storedash_swatch_type_unsupported',
				/* translators: 1: attribute type, 2: provider slug. */
				sprintf( __( 'Type "%1$s" is not supported by the %2$s swatches plugin.', 'storedash' ), $type, $provider->slug() ),
				array(
					'status'    => 422,
					'supported' => $provider->attribute_types(),
				)
			);
		}

		$attribute = $attribute_id > 0 ? call_user_func( $this->get_attribute, $attribute_id ) : null;
		if ( ! is_object( $attribute ) ) {
			return new \WP_Error(
				'storedash_attribute_not_found',
				__( 'Attribute not found.', 'storedash' ),
				array( 'status' => 404 )
			);
		}

		$args = array(
			'name'         => (string) $attribute->name,
			'slug'         => (string) $attribute->slug,
			'type'         => $native,
			'order_by'     => (string) $attribute->order_by,
			'has_archives' => (bool) $attribute->has_archives,
		);

		$written = call_user_func( $this->update_attribute, $attribute_id, $args, $provider );
		if ( is_wp_error( $written ) ) {
			$data = $written->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				return new \WP_Error( $written->get_error_code(), $written->get_error_message(), array( 'status' => 400 ) );
			}
			return $written;
		}

		return array(
			'id'   => $attribute_id,
			'type' => $type,
		);
	}

	/**
	 * Default writer: wc_update_attribute() with the provider's native types
	 * temporarily added to the type selector so the value is not reset to select.
	 *
	 * @param int                       $attribute_id Attribute id.
	 * @param array                     $args         wc_update_attribute() args.
	 * @param Swatch_Provider_Interface $provider     Active provider.
	 * @return int|\WP_Error
	 */
	public function write_attribute( int $attribute_id, array $args, Swatch_Provider_Interface $provider ) {
		$allow = static function ( $types ) use ( $provider ) {
			return array_merge( $provider->native_type_labels(), is_array( $types ) ? $types : array() );
		};

		add_filter( 'product_attributes_type_selector', $allow, PHP_INT_MAX );
		try {
			$result = wc_update_attribute( $attribute_id, $args );
		} finally {
			remove_filter( 'product_attributes_type_selector', $allow, PHP_INT_MAX );
		}

		return $result;
	}
}
