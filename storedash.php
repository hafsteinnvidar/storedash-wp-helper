<?php
/**
 * Plugin Name: Storedash Helper
 * Plugin URI: https://storedash.app
 * Update URI: https://storedash.app/wp-helper
 * Description: Helper plugin for Storedash to provide enhanced API endpoints and webhooks
 * Version: 1.20.1
 * Author: Storedash
 * Author URI: https://storedash.app
 * Text Domain: storedash
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 6.0.0
 * WC tested up to: 10.9
 * Requires Plugins: woocommerce
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'STOREDASH_VERSION', '1.20.1' );
define( 'STOREDASH_PATH', plugin_dir_path( __FILE__ ) );
define( 'STOREDASH_URL', plugin_dir_url( __FILE__ ) );

// Legacy constant support for backward compatibility
define( 'WOODASH_HELPER_VERSION', STOREDASH_VERSION );
define( 'WOODASH_HELPER_PATH', STOREDASH_PATH );
define( 'WOODASH_HELPER_URL', STOREDASH_URL );

// Helper functions (must be loaded first)
require_once STOREDASH_PATH . 'inc/utilities/helper-functions.php';

// Load text domain for translations
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'storedash', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

// Register activation hook
register_activation_hook( __FILE__, 'storedash_activate' );

/**
 * Plugin activation
 */
if ( ! function_exists( 'storedash_activate' ) ) {
	function storedash_activate() {
		require_once STOREDASH_PATH . 'inc/core/class-storedash-activator.php';
		StoreDash_Activator::activate();
	}
}

// Register deactivation hook
register_deactivation_hook( __FILE__, 'storedash_deactivate' );

/**
 * Plugin deactivation
 */
if ( ! function_exists( 'storedash_deactivate' ) ) {
	function storedash_deactivate() {
		require_once STOREDASH_PATH . 'inc/core/class-storedash-deactivator.php';
		StoreDash_Deactivator::deactivate();
	}
}

// Declare HPOS compatibility
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Increase timeout for auth callbacks to app.storedash.io (only needed during onboarding)
if ( get_option( 'woodash_webhooks_setup' ) !== 'completed' ) {
	add_filter(
		'http_request_args',
		function ( $args, $url ) {
			if ( strpos( $url, 'app.storedash.io' ) !== false ) {
				// Increase timeout to 30 seconds for auth callbacks
				if ( strpos( $url, 'woo-auth/callback' ) !== false ) {
					$args['timeout'] = 30;
					StoreDash_Helpers::debug_log( 'Setting timeout to 30s for callback URL' );
				}
			}
			return $args;
		},
		10,
		2
	);
}

// Bootstrap plugin
add_action( 'plugins_loaded', 'storedash_init', 20 );

/**
 * Initialize the plugin
 */
if ( ! function_exists( 'storedash_init' ) ) {
	function storedash_init() {
		// Load dependencies from core directory
		require_once STOREDASH_PATH . 'inc/core/class-storedash-helpers.php';
		require_once STOREDASH_PATH . 'inc/core/class-storedash-requirements.php';
		require_once STOREDASH_PATH . 'inc/core/class-storedash-loader.php';
		require_once STOREDASH_PATH . 'inc/core/class-storedash-bootstrap.php';

		// Self-hosted updates. Registered BEFORE the requirements gate so a site
		// where WooCommerce is deactivated (or the PHP/WP floor is unmet) can
		// still be offered the release that fixes it.
		require_once STOREDASH_PATH . 'inc/core/class-storedash-updater.php';
		( new StoreDash_Updater( __FILE__, STOREDASH_VERSION ) )->register();

		// Check requirements
		$requirements = new StoreDash_Requirements();
		if ( ! $requirements->met() ) {
			return; // Requirements class handles admin notices
		}

		// Auth handler self-instantiates on load (registers determine_current_user
		// + the /store-config route), so only load it once requirements pass —
		// otherwise those hooks would register on WC-inactive / sub-floor sites
		// where the rest of the plugin bails.
		require_once STOREDASH_PATH . 'inc/core/class-storedash-auth-handler.php';

		// Load unified admin interface
		if ( is_admin() ) {
			require_once STOREDASH_PATH . 'inc/admin/class-admin-components.php';
			require_once STOREDASH_PATH . 'inc/admin/class-admin-assets.php';
			require_once STOREDASH_PATH . 'inc/admin/class-storedash-admin.php';
			new StoreDash_Admin();
		}

		// Initialize loader
		$loader = new StoreDash_Loader();
		$loader->register();

		// Bootstrap application
		StoreDash_Bootstrap::instance()->init();
	}
}
