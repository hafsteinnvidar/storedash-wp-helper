<?php
/**
 * DK Config REST Controller
 *
 * Receives the merchant's DK Plus checkout settings from the Storedash
 * dashboard and reports what the site is running (plan §4.8).
 *
 * Routes (namespace `storedash/v1`, WooCommerce REST auth, `manage_woocommerce`):
 *   GET  /dk/config   → { success, config, config_hash, plugin_version,
 *                         checkout_type: classic|blocks|unknown,
 *                         conflicting_field: bool }
 *   POST /dk/config   ← { kennitala_mode?, kennitala_position? }
 *                     → { success, config, config_hash }
 *
 * `conflicting_field` is true when DKSync's own kennitala filter is hooked —
 * then the classic field is not added twice (`DK_Kennitala_Field`), and the
 * dashboard can tell the merchant why.
 *
 * Cost when unused: one `rest_api_init` callback that registers the route.
 *
 * @package StoreDash\Services\DK
 * @since   1.27.0
 */

namespace StoreDash\Services\DK;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DK Config Controller Class
 */
class DK_Config_Controller {

	const NAMESPACE = 'storedash/v1';

	/** DKSync's classic-checkout filter callback (includes/extra/dksync-kennitala.php). */
	const DKSYNC_FILTER_CALLBACK = 'custom_checkout_fields';

	/**
	 * Hook route registration.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/dk/config',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_config' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);
	}

	/**
	 * Store managers only (WooCommerce REST keys map to a user).
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /dk/config
	 *
	 * @return \WP_REST_Response
	 */
	public function get_config() {
		$config = DK_Config::get();
		return rest_ensure_response(
			array(
				'success'           => true,
				'config'            => $config,
				'config_hash'       => $config['config_hash'],
				'plugin_version'    => defined( 'STOREDASH_VERSION' ) ? STOREDASH_VERSION : null,
				'checkout_type'     => self::checkout_type(),
				'conflicting_field' => self::has_conflicting_field(),
			)
		);
	}

	/**
	 * POST /dk/config
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_config( $request ) {
		$raw = $request->get_json_params();
		if ( ! is_array( $raw ) ) {
			$raw = $request->get_params();
		}
		$clean = DK_Config::sanitize( is_array( $raw ) ? $raw : array() );
		if ( empty( $clean ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'No recognised settings in payload.', 'storedash' ),
				)
			);
		}

		$config = DK_Config::update( $clean );

		return rest_ensure_response(
			array(
				'success'     => true,
				'config'      => $config,
				'config_hash' => $config['config_hash'],
			)
		);
	}

	/**
	 * Which checkout the store runs.
	 *
	 * @return string classic | blocks | unknown
	 */
	public static function checkout_type(): string {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return 'unknown';
		}
		$page_id = (int) wc_get_page_id( 'checkout' );
		if ( $page_id <= 0 ) {
			return 'unknown';
		}
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $page_id ) ) {
			return 'blocks';
		}
		return 'classic';
	}

	/**
	 * Is DKSync (or anything using its callback name) adding a kennitala field?
	 *
	 * @return bool
	 */
	public static function has_conflicting_field(): bool {
		return function_exists( 'has_filter' )
			&& false !== has_filter( 'woocommerce_checkout_fields', self::DKSYNC_FILTER_CALLBACK );
	}
}

// Initialize (mirrors Waitlist_Settings_Controller).
new DK_Config_Controller();
