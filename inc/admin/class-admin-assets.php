<?php
/**
 * Admin Assets Manager
 *
 * Handles registration and enqueuing of admin CSS and JavaScript files
 *
 * @package StoreDash
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Admin_Assets {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ), 10 );
	}

	/**
	 * Register all assets
	 */
	public function register_assets() {
		// Register CSS
		wp_register_style(
			'storedash-wp-admin',
			STOREDASH_URL . 'assets/css/admin-unified.css',
			array(),
			$this->get_file_version( STOREDASH_PATH . 'assets/css/admin-unified.css' )
		);

		// Register JavaScript. jquery-ui-sortable was only needed by the removed
		// dead menu-builder module, so it is no longer a dependency.
		wp_register_script(
			'storedash-wp-admin',
			STOREDASH_URL . 'assets/js/admin-unified.js',
			array( 'jquery' ),
			$this->get_file_version( STOREDASH_PATH . 'assets/js/admin-unified.js' ),
			true
		);
	}

	/**
	 * Enqueue assets for admin pages
	 *
	 * @param string $hook Current admin page hook
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on StoreDash WP admin page
		if ( 'toplevel_page_storedash-wp' !== $hook ) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style( 'storedash-wp-admin' );

		// Enqueue JavaScript
		wp_enqueue_script( 'storedash-wp-admin' );

		// Localize script with data
		wp_localize_script(
			'storedash-wp-admin',
			'storedashWpAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'storedash-wp-admin' ),
				'strings' => array(
					'saved'      => __( 'Saved successfully!', 'storedash' ),
					'error'      => __( 'Error occurred. Please try again.', 'storedash' ),
					'confirm'    => __( 'Are you sure?', 'storedash' ),
					'loading'    => __( 'Loading...', 'storedash' ),
					'rebuilding' => __( 'Rebuilding index...', 'storedash' ),
					'processing' => __( 'Processing...', 'storedash' ),
				),
				'version' => STOREDASH_VERSION,
			)
		);
	}

	/**
	 * Get file version for cache busting
	 *
	 * @param string $file_path
	 * @return string
	 */
	private function get_file_version( $file_path ) {
		if ( file_exists( $file_path ) ) {
			return filemtime( $file_path );
		}

		return STOREDASH_VERSION;
	}

	/**
	 * Check if we're on a StoreDash WP admin page
	 *
	 * @return bool
	 */
	public function is_storedash_wp_page() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return strpos( $screen->id, 'storedash-wp' ) !== false;
	}
}
