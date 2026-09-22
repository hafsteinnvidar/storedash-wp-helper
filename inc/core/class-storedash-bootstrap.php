<?php
/**
 * StoreDash Bootstrap
 *
 * Handles plugin initialization and component loading.
 *
 * @package StoreDash
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap class - singleton
 */
class StoreDash_Bootstrap {

	/**
	 * Single instance
	 *
	 * @var StoreDash_Bootstrap
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return StoreDash_Bootstrap
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - private to enforce singleton
	 */
	private function __construct() {
		// Constructor intentionally empty - init() called explicitly
	}

	/**
	 * Initialize the plugin
	 */
	public function init(): void {
		// Register non-persistent cache groups to prevent Redis bloat.
		// - woodash_carts: prevents stale cart data during checkout
		// Rate-limit counters are stored in DB-backed transients (not the object
		// cache) so they persist across the per-request PHP processes that a real
		// throttle must span.
		wp_cache_add_non_persistent_groups(
			array(
				'woodash_carts',
			)
		);

		// Check for missing tables (handles plugin updates where activation hook doesn't run)
		$this->maybe_create_tables();

		// Include all files
		$this->include_files();

		// Initialize API
		$this->init_api();

		// Custom order statuses must load on init before priority 10:
		// - rest_api_init runs at init:0; WC REST may update orders before late init.
		// - HPOS / admin list queries use wc_get_order_statuses(); filters must exist
		// before WooCommerce first resolves that list.
		add_action( 'init', array( $this, 'init_custom_order_statuses_early' ), 0 );

		// Initialize components
		add_action( 'init', array( $this, 'init_components' ) );
	}

	/**
	 * Register WooCommerce custom order statuses as early as possible on init.
	 *
	 * Loading only in init_components() (priority 10) was too late: REST and list
	 * queries could treat wc-ready-pickup as unknown and hide orders until status
	 * changed back to a core slug (e.g. processing).
	 */
	public function init_custom_order_statuses_early(): void {
		if ( ! class_exists( 'StoreDash_Custom_Order_Statuses' ) ) {
			return;
		}
		StoreDash_Custom_Order_Statuses::instance();
	}

	/**
	 * Check for and create missing database tables.
	 *
	 * This runs on every load to handle cases where the plugin is updated
	 * without being deactivated/reactivated (which skips the activation hook).
	 */
	private function maybe_create_tables(): void {
		// Use a version flag to avoid checking on every single request
		$db_version      = get_option( 'storedash_db_schema_version', '0' );
		$current_version = '1.9.0'; // Bumped: rewards credit tables (storedash_credit_ledger / storedash_credit_rules)

		if ( version_compare( $db_version, $current_version, '>=' ) ) {
			return; // Already up to date
		}

		// Load the activator and run table creation
		require_once STOREDASH_PATH . 'inc/core/class-storedash-activator.php';
		StoreDash_Activator::activate();

		// Clean up the removed webhook logs feature on existing installs.
		$this->drop_webhook_logs_table();

		// Update the schema version
		update_option( 'storedash_db_schema_version', $current_version, false );

		if ( class_exists( 'StoreDash_Helpers' ) ) {
			StoreDash_Helpers::debug_log( 'Database schema upgraded to version ' . $current_version );
		}
	}

	/**
	 * Drop the legacy webhook logs table and unschedule its purge cron.
	 *
	 * The webhook logs feature was removed; this cleans up the orphaned table
	 * and daily purge event left behind on installs created before the removal.
	 */
	private function drop_webhook_logs_table(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time schema cleanup of a plugin-owned table.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}storedash_webhook_logs" );

		$timestamp = wp_next_scheduled( 'storedash_purge_webhook_logs' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'storedash_purge_webhook_logs' );
		}
		wp_clear_scheduled_hook( 'storedash_purge_webhook_logs' );
	}

	/**
	 * Include required files
	 */
	private function include_files(): void {
		// Core files
		require_once STOREDASH_PATH . 'inc/utilities/setup.php';
		require_once STOREDASH_PATH . 'inc/core/class-base-permissions.php';

		// Integration system (must load BEFORE specific integrations)
		require_once STOREDASH_PATH . 'inc/integrations/class-base-integration.php';
		require_once STOREDASH_PATH . 'inc/integrations/class-integration-registry.php';

		// Webhook services
		require_once STOREDASH_PATH . 'inc/services/webhooks/webhooks.php';
		require_once STOREDASH_PATH . 'inc/services/webhooks/webhook-payload-modifier.php';
		require_once STOREDASH_PATH . 'inc/services/webhooks/webhook-filter.php';

		// Posturinn integration
		require_once STOREDASH_PATH . 'inc/integrations/posturinn/class-posturinn-disable-auto.php';
		require_once STOREDASH_PATH . 'inc/integrations/posturinn/class-posturinn-integration.php';

		// Main API class
		require_once STOREDASH_PATH . 'inc/api/api.php';

		// Headless checkout
		require_once STOREDASH_PATH . 'inc/api/headless-checkout.php';
		require_once STOREDASH_PATH . 'inc/modules/headless-checkout.php';

		// Headless customer auth: token store, Store API bridge, REST routes.
		// The bridge must load before the Store API serves any request, which
		// include_files() (plugins_loaded, priority 20) satisfies.
		require_once STOREDASH_PATH . 'inc/core/class-storedash-customer-tokens.php';
		require_once STOREDASH_PATH . 'inc/core/class-storedash-customer-auth-bridge.php';
		require_once STOREDASH_PATH . 'inc/api/customer-auth.php';
		// Redirects password-reset and My Account links in emails to the
		// storefront. No-ops unless storedash_storefront_url is set.
		require_once STOREDASH_PATH . 'inc/modules/headless-account-emails.php';
		StoreDash_Customer_Tokens::register_hooks();
		StoreDash_Customer_Auth_Bridge::instance();

		// Additional API controllers
		require_once STOREDASH_PATH . 'inc/api/media.php';
		require_once STOREDASH_PATH . 'inc/api/emails.php';
		require_once STOREDASH_PATH . 'inc/api/orders.php';
		require_once STOREDASH_PATH . 'inc/api/coupons.php';
		// Dropp consignment writeback (records StoreDash-booked Dropp shipments
		// into the dropp-for-woocommerce plugin's table; self-registering).
		require_once STOREDASH_PATH . 'inc/api/dropp.php';
		// Additive `thumbnail_src` on product / variation / order-line images.
		require_once STOREDASH_PATH . 'inc/api/image-thumbnails.php';
		// Shipment tracking writeback (records tracking via the WC Shipment
		// Tracking extension, used by ShipStation; self-registering).
		require_once STOREDASH_PATH . 'inc/api/shipment-tracking.php';
		// Payment-gateway capabilities + per-order payment meta (self-registering).
		require_once STOREDASH_PATH . 'inc/api/payment-gateways.php';
		// Smart Coupons (StoreApps) store-credit balances by customer email
		// (self-registering).
		require_once STOREDASH_PATH . 'inc/api/smart-coupons.php';

		// Frontend features
		require_once STOREDASH_PATH . 'inc/live-chat.php';

		// Cart services
		require_once STOREDASH_PATH . 'inc/services/cart/Cart_Data.php';
		require_once STOREDASH_PATH . 'inc/services/cart/Cart_Tracking.php';
		require_once STOREDASH_PATH . 'inc/services/cart/Cart_Recovery.php';

		// Waitlist services
		// Config/stock/renderer are passive (no hooks); placement registers the
		// automatic hook, the shortcode and the footer fallback, so it must load
		// after the three it depends on.
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-waitlist-config.php';
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-waitlist-stock.php';
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-waitlist-renderer.php';
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-waitlist-placement.php';
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-waitlist-handler.php';
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-stock-monitor.php';
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-waitlist-api.php';
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-waitlist-settings-controller.php';
		require_once STOREDASH_PATH . 'inc/services/waitlist/class-yith-migration.php';

		// Enquiry services. Order matters: Enquiry_Placement instantiates itself
		// on load and reads Enquiry_Config in its constructor, so the config,
		// scope and renderer classes must already be defined.
		require_once STOREDASH_PATH . 'inc/services/enquiry/class-enquiry-config.php';
		require_once STOREDASH_PATH . 'inc/services/enquiry/class-enquiry-scope.php';
		require_once STOREDASH_PATH . 'inc/services/enquiry/class-enquiry-renderer.php';
		require_once STOREDASH_PATH . 'inc/services/enquiry/class-enquiry-placement.php';
		require_once STOREDASH_PATH . 'inc/services/enquiry/class-enquiry-handler.php';
		require_once STOREDASH_PATH . 'inc/services/enquiry/class-enquiry-settings-controller.php';

		// FBT (frequently bought together) services. Same load-order rule as
		// enquiry: FBT_Placement and FBT_Cart instantiate themselves on load
		// and read FBT_Config / FBT_Data, so those must be defined first.
		// The per-product bundle meta itself is written by the dashboard's
		// product editor; these classes are only the storefront display +
		// cart layer.
		require_once STOREDASH_PATH . 'inc/services/fbt/class-fbt-config.php';
		require_once STOREDASH_PATH . 'inc/services/fbt/class-fbt-data.php';
		require_once STOREDASH_PATH . 'inc/services/fbt/class-fbt-renderer.php';
		require_once STOREDASH_PATH . 'inc/services/fbt/class-fbt-placement.php';
		require_once STOREDASH_PATH . 'inc/services/fbt/class-fbt-cart.php';
		require_once STOREDASH_PATH . 'inc/services/fbt/class-fbt-settings-controller.php';

		// Marketing opt-in services (checkout checkbox + Elementor signup widget)
		require_once STOREDASH_PATH . 'inc/services/optin/class-optin-handler.php';
		// Block (Store API) checkout opt-in field — bridges into the handler above.
		require_once STOREDASH_PATH . 'inc/services/optin/class-blocks-optin-field.php';

		// Custom order statuses
		require_once STOREDASH_PATH . 'inc/services/class-custom-order-statuses.php';

		// Custom product statuses: widens the wc/v3 products status filters and
		// backs the /storedash/v1/statuses catalogue. Self-registers REST filters
		// only; instantiating here (plugins_loaded:20) is safely before rest_api_init.
		require_once STOREDASH_PATH . 'inc/services/class-product-status-support.php';
		StoreDash_Product_Status_Support::instance();

		// Module loader
		require_once STOREDASH_PATH . 'inc/modules/class-module-loader.php';

		// Elementor widgets
		if ( did_action( 'elementor/loaded' ) ) {
			require_once STOREDASH_PATH . 'inc/widgets/class-widgets-manager.php';
		}
	}

	/**
	 * Initialize API
	 */
	private function init_api(): void {
		try {
			// Check if main API class exists
			if ( ! class_exists( 'StoreDash_API' ) ) {
				StoreDash_Helpers::log_message( 'StoreDash_API class not found', 'error' );
				return;
			}

			// Initialize API singleton
			StoreDash_API::instance();

		} catch ( Exception $e ) {
			StoreDash_Helpers::log_message( 'Failed to initialize StoreDash API: ' . $e->getMessage(), 'error' );

			// Add admin notice for API initialization failure
			add_action(
				'admin_notices',
				function () use ( $e ) {
					?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Storedash: Failed to initialize API. Please check error logs.', 'storedash' ); ?></p>
				</div>
					<?php
				}
			);
		}
	}

	/**
	 * Initialize other components
	 */
	public function init_components(): void {
		try {
			// Initialize the integration registry (discovers + instantiates
			// integrations; each integration wires its own WP hooks in its
			// constructor). No REST endpoints are exposed by the registry.
			if ( class_exists( 'StoreDash_Integration_Registry' ) ) {
				StoreDash_Integration_Registry::init();
			} else {
				StoreDash_Helpers::log_message( 'StoreDash_Integration_Registry class not found', 'error' );
			}

			// Initialize module loader
			if ( class_exists( 'StoreDash_Module_Loader' ) ) {
				StoreDash_Module_Loader::instance();
			} else {
				StoreDash_Helpers::log_message( 'StoreDash_Module_Loader class not found', 'error' );
				return;
			}

			// Initialize setup
			if ( class_exists( 'StoreDash_Setup' ) ) {
				new StoreDash_Setup();
			} else {
				StoreDash_Helpers::log_message( 'StoreDash_Setup class not found', 'error' );
			}

			// Cart tracking: always register cron hooks (WP-Cron needs them on any request),
			// but only instantiate the full tracker on frontend or WC AJAX contexts.
			if ( class_exists( 'StoreDash\Carts\Services\Cart_Tracking' ) ) {
				\StoreDash\Carts\Services\Cart_Tracking::register_cron_hooks();

				$is_frontend = ! is_admin() || wp_doing_ajax();
				$is_wc_ajax  = defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX;
				if ( $is_frontend || $is_wc_ajax ) {
					\StoreDash\Carts\Services\Cart_Tracking::instance();
				}
			}

			// Custom order statuses: registered on init priority 0 (see init_custom_order_statuses_early).
		} catch ( Exception $e ) {
			StoreDash_Helpers::log_message( 'Critical error in component initialization: ' . $e->getMessage(), 'error' );

			// Add admin notice for critical errors
			add_action(
				'admin_notices',
				function () use ( $e ) {
					?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Storedash: Critical initialization error. Please check error logs.', 'storedash' ); ?></p>
				</div>
					<?php
				}
			);
		}
	}

	/**
	 * Prevent cloning
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization
	 */
	public function __wakeup(): void {}
}

