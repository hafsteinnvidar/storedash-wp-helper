<?php
/**
 * WooCommerce Settings Fallback REST Controller
 *
 * Serves the core `wc/v3/settings/{group}` read + batch-write for the four
 * groups the Storedash "WooCommerce settings" page mirrors, on stores where a
 * third-party plugin has broken WooCommerce's own settings REST groups.
 *
 * Why it exists: WooCommerce builds its settings REST groups from the admin
 * settings pages (`WC_Admin_Settings::get_settings_pages()` → the
 * `woocommerce_get_settings_pages` filter). A filter callback that returns a
 * single page object instead of the array it was given (DKSync's
 * `dksync_add_settings()` does exactly this) leaves WooCommerce with no pages
 * to register, so every `/wc/v3/settings/*` request answers 404
 * `rest_setting_setting_group_invalid`. WP-admin keeps working — the core
 * pages' tab hooks were already attached — so merchants never notice.
 *
 * What it does: when, and only when, a group is missing, it builds that core
 * page (`WC_Settings_General` etc.) and hands it to WooCommerce's own
 * `WC_Register_WP_Admin_Settings`, then calls WooCommerce's own
 * `WC_REST_Setting_Options_Controller` methods directly. Response shapes,
 * validation, sanitizing and saving are therefore WooCommerce's — this class
 * adds no option-writing logic of its own. Registration lasts for this request
 * only; nothing on the store changes outside these two routes.
 *
 * The WooCommerce controller is called in-process (not via rest_do_request)
 * because StoreDash_Auth_Handler::guard_route_scope() refuses a consumer-key
 * identity on any dispatched route outside storedash/.
 *
 * Routes (namespace `storedash/v1`), same bodies/responses as core:
 *   GET  /wc-settings/{group}        → wc/v3/settings/{group}
 *   POST /wc-settings/{group}/batch  → wc/v3/settings/{group}/batch
 *   {group} ∈ general | products | tax | shipping
 *
 * Callers (storedash-sync, woo-dash) try core first and use these routes only
 * on that 404, so stores with working core groups never touch this code.
 *
 * Cost when unused: one `rest_api_init` callback that registers two routes.
 *
 * @package StoreDash\Services\WC_Settings
 * @since   1.28.0
 */

namespace StoreDash\Services\WC_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Settings Fallback Controller Class
 */
class WC_Settings_Fallback_Controller {

	/**
	 * API namespace.
	 */
	const NAMESPACE = 'storedash/v1';

	/**
	 * Group id → [core settings page class, file under includes/admin/settings/].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	const GROUPS = array(
		'general'  => array( 'WC_Settings_General', 'class-wc-settings-general.php' ),
		'products' => array( 'WC_Settings_Products', 'class-wc-settings-products.php' ),
		'tax'      => array( 'WC_Settings_Tax', 'class-wc-settings-tax.php' ),
		'shipping' => array( 'WC_Settings_Shipping', 'class-wc-settings-shipping.php' ),
	);

	/**
	 * Groups this request already ensured, so repeat calls stay cheap.
	 *
	 * @var array<string, bool>
	 */
	private $ensured = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$group_pattern = '(?P<group_id>' . implode( '|', array_keys( self::GROUPS ) ) . ')';

		register_rest_route(
			self::NAMESPACE,
			'/wc-settings/' . $group_pattern,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wc-settings/' . $group_pattern . '/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'batch_items' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Permission check — same capability as `store-config` and the other
	 * Storedash admin routes. WooCommerce's own settings permission check runs
	 * again inside each callback.
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET — every setting in the group, exactly as core returns it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$controller = $this->wc_controller();
		if ( is_wp_error( $controller ) ) {
			return $controller;
		}

		$allowed = $controller->get_items_permissions_check( $request );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$this->ensure_group_registered( (string) $request['group_id'] );

		return $controller->get_items( $request );
	}

	/**
	 * POST batch — `{ "update": [ { "id": "...", "value": "..." } ] }`, exactly
	 * as core accepts it. Each item is validated and saved by WooCommerce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error|array
	 */
	public function batch_items( $request ) {
		$controller = $this->wc_controller();
		if ( is_wp_error( $controller ) ) {
			return $controller;
		}

		$allowed = $controller->update_items_permissions_check( $request );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$this->ensure_group_registered( (string) $request['group_id'] );

		return rest_ensure_response( $controller->batch_items( $request ) );
	}

	/**
	 * Register the group's core settings page with WooCommerce's REST
	 * registrar when nothing currently answers for it. A group that already
	 * has settings is left alone, so a healthy store is never double-registered.
	 *
	 * @param string $group_id Group id (validated by the route pattern).
	 * @return void
	 */
	public function ensure_group_registered( string $group_id ): void {
		if ( isset( $this->ensured[ $group_id ] ) || ! isset( self::GROUPS[ $group_id ] ) ) {
			return;
		}
		$this->ensured[ $group_id ] = true;

		if ( $this->group_has_settings( $group_id ) ) {
			return;
		}

		$page = $this->core_page( $group_id );
		if ( null === $page || ! class_exists( '\WC_Register_WP_Admin_Settings' ) ) {
			return;
		}

		// The constructor attaches the woocommerce_settings_groups and
		// woocommerce_settings-{group} filters for this request.
		new \WC_Register_WP_Admin_Settings( $page, 'page' );
	}

	/**
	 * Does WooCommerce currently answer for this group? Same test core's
	 * get_group_settings() uses before returning rest_setting_setting_group_invalid.
	 *
	 * @param string $group_id Group id.
	 * @return bool
	 */
	protected function group_has_settings( string $group_id ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook, read not owned.
		return ! empty( apply_filters( 'woocommerce_settings-' . $group_id, array() ) );
	}

	/**
	 * A fresh core settings page object for the group. The page constructors
	 * only attach wp-admin screen hooks (tabs, output, save, notices), none of
	 * which fire during a REST request.
	 *
	 * @param string $group_id Group id.
	 * @return object|null
	 */
	private function core_page( string $group_id ) {
		list( $class, $file ) = self::GROUPS[ $group_id ];

		if ( ! class_exists( $class, false ) ) {
			if ( ! defined( 'WC_ABSPATH' ) ) {
				return null;
			}
			$dir = WC_ABSPATH . 'includes/admin/settings/';
			if ( ! class_exists( 'WC_Settings_Page', false ) ) {
				include_once $dir . 'class-wc-settings-page.php';
			}
			// Core page files end with `return new WC_Settings_X();`.
			$page = include_once $dir . $file;
			if ( is_object( $page ) ) {
				return $page;
			}
		}

		return class_exists( $class, false ) ? new $class() : null;
	}

	/**
	 * WooCommerce's v3 setting-options controller (not route-registered; used
	 * for its methods only).
	 *
	 * @return \WC_REST_Setting_Options_Controller|\WP_Error
	 */
	private function wc_controller() {
		if ( ! class_exists( '\WC_REST_Setting_Options_Controller' ) || ! class_exists( '\WC_Admin_Settings' ) ) {
			return new \WP_Error(
				'storedash_wc_settings_unavailable',
				__( 'WooCommerce settings API is not available on this store.', 'storedash' ),
				array( 'status' => 501 )
			);
		}

		return new \WC_REST_Setting_Options_Controller();
	}
}

// Initialize
new WC_Settings_Fallback_Controller();
