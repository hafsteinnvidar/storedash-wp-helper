<?php
/**
 * DK Legacy Settings REST Controller
 *
 * Read-only export of the DKSync plugin's settings so the Storedash DK settings
 * page can offer "Import from DKSync": the merchant's existing payment-gateway,
 * shipping and salesperson mappings are prefilled, reviewed and saved in
 * Storedash. Storedash never writes DKSync options and never calls DK from WP.
 *
 * Route (namespace `storedash/v1`):
 *   GET /dk/legacy-settings
 *
 * Response 200:
 *   {
 *     "dksync_active":    bool,         // is_plugin_active() on DKSync's file
 *     "dksync_version":   string|null,  // plugin header Version, null when not installed
 *     "options":          { "<option_name>": mixed, ... },
 *     "redacted_options": string[]      // names withheld as secrets (values never sent)
 *   }
 * Response 404 when no `dksync_*` option exists: `{ "code": "dksync_not_found", ... }`.
 *
 * DKSync option names (DKSync 1.2.0, `includes/class-dksync-settings.php` +
 * `includes/class-dksync-constants.php`):
 *   Mappings
 *     dksync_api_payment_id_{gateway_id}   DK PaymentId per WC gateway id
 *     dksync_shipping_{method_id}          DK item code per WC shipping method id;
 *                                          also dksync_shipping_postis and the
 *                                          Pósturinn services dksync_shipping_{DPH|DPP|DPO|DPL|OLP|OIJ|DPT}
 *     dksync_shipping_default              fallback shipping item code
 *     dksync_shipping                      'yes' = send shipping line to DK
 *     dksync_api_salesperson               DK salesperson Number
 *     dksync_sendkost_*_itemcode           legacy per-service shipping item codes (pre-1.2)
 *   Behaviour
 *     dksync_price_field                   UnitPrice1WithTax | UnitPrice2WithTax | UnitPrice3WithTax
 *     dksync_warehouse_field               DK warehouse code, or 'TotalQuantityInWarehouse'
 *     dksync_order_ssn                     fixed kennitala for every invoice; empty = checkout field
 *     dksync_invoice_reference             All | Email + Name | Name + Phone | Phone | Name
 *     dksync_invoice_settings              order | invoice | invoice-active
 *     dksync_api_currency                  ISK
 *     dksync_backorders                    no | notify | yes
 *     dksync_background_sync               yes | no
 *     dksync_refunds                       no | full | all
 *     dksync_stock_catchup                 yes | no
 *     dksync_create_products_automatically, dksync_products_sync_on_save, dksync_sync_on_product_save
 *   Internal state (returned as-is, harmless)
 *     dksync_tasks_run_task, dksync_webhook_created, dksync_stock_catchup_last_run,
 *     dksync_log_retention, dksync_log_success_gets, dksync_log_db_version
 *
 * Never returned:
 *   - dksync_api_key — the DK API token. It is DKSync's ONLY secret option (it
 *     is also what DKSync uses as its webhook Authorization value). Any option
 *     whose name contains key / token / secret / password is withheld, so a
 *     future DKSync secret is covered without a code change.
 *   - dksync_lock_{order_id} — per-order send locks, not settings.
 *   DKSync's transients (`dksync_options_temp`, `dksync_products_cache`) live
 *   under `_transient_*` names and are not matched.
 *
 * Cost when unused: one `rest_api_init` callback that registers the route.
 *
 * @package StoreDash\Services\DK
 * @since   1.25.0
 */

namespace StoreDash\Services\DK;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DK Legacy Settings Controller Class
 */
class DK_Legacy_Settings_Controller {

	/**
	 * API namespace.
	 */
	const NAMESPACE = 'storedash/v1';

	/**
	 * Prefix of every DKSync option.
	 */
	const OPTION_PREFIX = 'dksync_';

	/**
	 * Per-order lock options DKSync writes while sending an order.
	 */
	const LOCK_PREFIX = 'dksync_lock_';

	/**
	 * Option-name fragments that mark a secret. Matched case-insensitively.
	 *
	 * @var string[]
	 */
	const SECRET_NAME_FRAGMENTS = array( 'key', 'token', 'secret', 'password' );

	/**
	 * DKSync's main plugin file name. The folder name depends on how the
	 * merchant installed it (zip name), so the plugin is found by file name.
	 */
	const PLUGIN_FILE = 'dksync.php';

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
		register_rest_route(
			self::NAMESPACE,
			'/dk/legacy-settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_legacy_settings' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Permission check — same capability as `store-config` and the other
	 * Storedash admin routes.
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Return DKSync's settings, secrets withheld.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_legacy_settings() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prefix scan of wp_options; admin-only and rare.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				$wpdb->esc_like( self::LOCK_PREFIX ) . '%'
			),
			ARRAY_A
		);

		$raw = array();
		foreach ( (array) $rows as $row ) {
			$raw[ (string) $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
		}

		$filtered = self::filter_options( $raw );
		if ( array() === $filtered['options'] && array() === $filtered['redacted'] ) {
			return new \WP_Error(
				'dksync_not_found',
				__( 'No DKSync settings were found on this store.', 'storedash' ),
				array( 'status' => 404 )
			);
		}

		$plugin = self::locate_plugin();

		return rest_ensure_response(
			array(
				'dksync_active'    => $plugin['active'],
				'dksync_version'   => $plugin['version'],
				'options'          => (object) $filtered['options'],
				'redacted_options' => $filtered['redacted'],
			)
		);
	}

	/**
	 * Split raw `dksync_*` options into the exportable ones and the names of
	 * withheld secrets. Pure — unit-tested.
	 *
	 * @param array<string, mixed> $raw Option name => unserialized value.
	 * @return array{options: array<string, mixed>, redacted: string[]}
	 */
	public static function filter_options( array $raw ): array {
		$options  = array();
		$redacted = array();

		foreach ( $raw as $name => $value ) {
			$name = (string) $name;
			if ( 0 !== strpos( $name, self::OPTION_PREFIX ) || 0 === strpos( $name, self::LOCK_PREFIX ) ) {
				continue;
			}
			if ( self::is_secret_option_name( $name ) ) {
				$redacted[] = $name;
				continue;
			}
			$options[ $name ] = $value;
		}

		ksort( $options );
		sort( $redacted );

		return array(
			'options'  => $options,
			'redacted' => $redacted,
		);
	}

	/**
	 * Whether an option name looks like it holds a credential.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_secret_option_name( string $name ): bool {
		$name = strtolower( $name );
		foreach ( self::SECRET_NAME_FRAGMENTS as $fragment ) {
			if ( false !== strpos( $name, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pick DKSync's entry out of a `get_plugins()` list. An active copy wins
	 * over an inactive one. Pure apart from the injected active check.
	 *
	 * @param array<string, array> $plugins   Plugin file => header data (`get_plugins()`).
	 * @param callable             $is_active `function( string $plugin_file ): bool`.
	 * @return array{file: string|null, active: bool, version: string|null}
	 */
	public static function pick_plugin( array $plugins, callable $is_active ): array {
		$found = array(
			'file'    => null,
			'active'  => false,
			'version' => null,
		);

		foreach ( $plugins as $file => $data ) {
			$file = (string) $file;
			if ( self::PLUGIN_FILE !== strtolower( basename( $file ) ) ) {
				continue;
			}

			$active  = (bool) $is_active( $file );
			$version = isset( $data['Version'] ) && '' !== (string) $data['Version'] ? (string) $data['Version'] : null;

			if ( null === $found['file'] || ( $active && ! $found['active'] ) ) {
				$found = array(
					'file'    => $file,
					'active'  => $active,
					'version' => $version,
				);
			}
		}

		return $found;
	}

	/**
	 * Locate DKSync among the installed plugins.
	 *
	 * @return array{file: string|null, active: bool, version: string|null}
	 */
	private static function locate_plugin(): array {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return self::pick_plugin( get_plugins(), 'is_plugin_active' );
	}
}

// Initialize
new DK_Legacy_Settings_Controller();
