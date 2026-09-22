<?php
/**
 * StoreDash Autoloader
 *
 * Handles SPL autoloading for plugin classes.
 * Uses explicit PSR-4 mappings to ensure compatibility with case-sensitive filesystems (Linux).
 *
 * @package StoreDash
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader class
 */
class StoreDash_Loader {

	/**
	 * PSR-4 namespace to folder mappings.
	 * These must match the actual folder names (PascalCase as they exist on disk).
	 *
	 * @var array
	 */
	private $psr4_prefixes = array(
		'StoreDash\\Products\\'  => 'inc/Products/',
		'StoreDash\\Carts\\'     => 'inc/Carts/',
		'StoreDash\\Discounts\\' => 'inc/Discounts/',
		'StoreDash\\Credit\\'    => 'inc/Credit/',
	);

	/**
	 * Register autoloader
	 */
	public function register(): void {
		// Register our PSR-4 autoloader FIRST (before composer)
		// This ensures our explicit mappings take precedence
		spl_autoload_register( array( $this, 'autoload' ), true, true );

		// Load Composer autoload if available (for dev dependencies like phpcs)
		if ( file_exists( STOREDASH_PATH . 'vendor/autoload.php' ) ) {
			require_once STOREDASH_PATH . 'vendor/autoload.php';
		}
	}

	/**
	 * Autoload classes
	 *
	 * @param string $class The class name to load
	 */
	public function autoload( string $class ): void {
		// First, check explicit PSR-4 mappings (handles case-sensitivity properly)
		foreach ( $this->psr4_prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) === 0 ) {
				// Get the relative class name
				$relative_class = substr( $class, $len );
				// Convert to file path
				$file = STOREDASH_PATH . $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
				$this->require_file( $file, $class );
				return;
			}
		}

		// Fallback: Check for legacy namespace (Woodash)
		if ( strpos( $class, 'Woodash\\' ) === 0 ) {
			$this->load_class_fallback( $class, 'Woodash\\', 8 );
			return;
		}

		// Fallback: Generic StoreDash namespace (for any unmapped namespaces)
		if ( strpos( $class, 'StoreDash\\' ) === 0 ) {
			$this->load_class_fallback( $class, 'StoreDash\\', 10 );
			return;
		}
	}

	/**
	 * Require a file with security validation
	 *
	 * @param string $file The file path
	 * @param string $class The class name (for logging)
	 * @return bool Whether the file was loaded
	 */
	private function require_file( string $file, string $class ): bool {
		if ( ! file_exists( $file ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( sprintf( '[StoreDash Autoloader] File not found: %s for class %s', $file, $class ) );
			}
			return false;
		}

		// Security: Validate the resolved path is within the plugin directory.
		// Use a trailing-separator boundary (not a bare prefix) so a sibling
		// directory like "<plugin>-evil/" cannot satisfy the containment check.
		// realpath() strips any trailing separator from STOREDASH_PATH, and every
		// legitimate class file lives in a subdirectory of it, so the boundary
		// match still resolves them.
		$real_file = realpath( $file );
		$real_base = realpath( STOREDASH_PATH );

		if ( $real_file && $real_base ) {
			$base_boundary = rtrim( $real_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if ( strpos( $real_file, $base_boundary ) === 0 ) {
				require_once $real_file;
				return true;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '[StoreDash Autoloader] Security check failed for: %s', $class ) );
		}
		return false;
	}

	/**
	 * Fallback class loading (tries lowercase folder names)
	 *
	 * @param string $class The full class name
	 * @param string $namespace The namespace prefix
	 * @param int    $namespace_length Length of the namespace prefix
	 */
	private function load_class_fallback( string $class, string $namespace, int $namespace_length ): void {
		$class_path = substr( $class, $namespace_length );
		$parts      = explode( '\\', $class_path );

		// Convert first part (module name) to lowercase for folder
		if ( count( $parts ) > 0 ) {
			$parts[0] = strtolower( $parts[0] );
		}

		$file = STOREDASH_PATH . 'inc/' . implode( '/', $parts ) . '.php';
		$this->require_file( $file, $class );
	}
}
