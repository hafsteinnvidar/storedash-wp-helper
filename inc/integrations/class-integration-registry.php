<?php
/**
 * Integration Registry Class
 *
 * Manages all Storedash integrations, handles registration,
 * discovery, and provides a central access point for all integrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Integration_Registry {
	/**
	 * Registered integrations
	 *
	 * @var array
	 */
	private static $integrations = array();

	/**
	 * Whether integrations have been initialized
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize the registry
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		// Auto-discover integrations
		self::discover_integrations();

		// Allow external integrations to register
		do_action( 'woodash_register_integrations', __CLASS__ );

		self::$initialized = true;
	}

	/**
	 * Register an integration
	 *
	 * @param string $integration_class Class name of the integration
	 * @return bool Success
	 */
	public static function register( string $integration_class ): bool {
		// Validate class exists and extends base class
		if ( ! class_exists( $integration_class ) ) {
			\StoreDash_Helpers::debug_log( 'Integration class not found: ' . $integration_class );
			return false;
		}

		if ( ! is_subclass_of( $integration_class, 'StoreDash_Base_Integration' ) ) {
			\StoreDash_Helpers::debug_log( 'Integration must extend StoreDash_Base_Integration: ' . $integration_class );
			return false;
		}

		try {
			$integration = new $integration_class();
			$id          = $integration->get_id();

			if ( isset( self::$integrations[ $id ] ) ) {
				\StoreDash_Helpers::debug_log( 'Integration already registered: ' . $id );
				return false;
			}

			self::$integrations[ $id ] = $integration;

			// Integrations wire their own WP hooks in their constructor
			// (already run above via `new`); there is no REST surface to register.

			// Only log if the integration is actually available (plugin is installed)
			// Commented out to reduce log noise - this is a success message, not an error
			// if ($integration->is_available()) {
			// error_log('[Storedash] Successfully registered integration: ' . $id);
			// }
			return true;
		} catch ( Exception $e ) {
			\StoreDash_Helpers::log_message( 'Failed to register integration: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Auto-discover integrations in the integrations directory
	 */
	private static function discover_integrations(): void {
		$integrations_dir = WOODASH_HELPER_PATH . 'inc/integrations';

		// Get all subdirectories in the integrations folder
		$directories = glob( $integrations_dir . '/*', GLOB_ONLYDIR );

		foreach ( $directories as $dir ) {
			$dirname = basename( $dir );

			// Skip core directories
			if ( in_array( $dirname, array( 'core', 'base' ), true ) ) {
				continue;
			}

			// Look for the main integration class file
			$class_file = $dir . '/class-' . $dirname . '-integration.php';

			if ( file_exists( $class_file ) ) {
				require_once $class_file;

				// Convert directory name to class name
				$class_name = 'StoreDash_' . str_replace( '-', '_', ucwords( $dirname, '-' ) ) . '_Integration';

				// Register the integration
				self::register( $class_name );
			}
		}
	}

	/**
	 * Get an integration by ID
	 *
	 * @param string $id Integration ID
	 * @return StoreDash_Base_Integration|null
	 */
	public static function get_integration( string $id ) {
		return isset( self::$integrations[ $id ] ) ? self::$integrations[ $id ] : null;
	}

	/**
	 * Get all registered integrations
	 *
	 * @return array
	 */
	public static function get_all_integrations(): array {
		return self::$integrations;
	}

	/**
	 * Get available integrations (where the required plugin is active)
	 *
	 * @return array
	 */
	public static function get_available_integrations(): array {
		$available = array();

		foreach ( self::$integrations as $id => $integration ) {
			if ( $integration->is_available() ) {
				$available[ $id ] = $integration;
			}
		}

		return $available;
	}

	/**
	 * Check if an integration is registered
	 *
	 * @param string $id Integration ID
	 * @return bool
	 */
	public static function is_registered( string $id ): bool {
		return isset( self::$integrations[ $id ] );
	}

	/**
	 * Check if an integration is available
	 *
	 * @param string $id Integration ID
	 * @return bool
	 */
	public static function is_available( string $id ): bool {
		$integration = self::get_integration( $id );
		return $integration ? $integration->is_available() : false;
	}
}
