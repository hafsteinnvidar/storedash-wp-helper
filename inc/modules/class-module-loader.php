<?php
/**
 * Module Loader Class
 *
 * Handles the loading and initialization of plugin modules.
 * Uses singleton pattern to ensure only one instance exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Module_Loader {
	/**
	 * The single instance of the class
	 *
	 * @var StoreDash_Module_Loader|null
	 */
	private static $instance = null;

	/**
	 * Active modules
	 *
	 * @var array
	 */
	private $active_modules = array();

	/**
	 * Protected constructor to prevent creating a new instance
	 */
	protected function __construct() {
		$this->init();
	}

	/**
	 * Get the singleton instance
	 *
	 * @return StoreDash_Module_Loader
	 */
	public static function instance(): StoreDash_Module_Loader {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the module loader
	 */
	private function init(): void {
		$this->load_modules();
	}

	/**
	 * Load all available modules
	 *
	 * No modules are currently registered. The custom-tabs module was removed
	 * once StoreDash began syncing tabs via ordinary product meta_data instead
	 * of the dedicated storedash/v1/custom-tabs REST controller.
	 */
	private function load_modules(): void {
	}

	/**
	 * Check if a module is active
	 *
	 * @param string $module Module identifier
	 * @return bool
	 */
	public function is_module_active( string $module ): bool {
		return isset( $this->active_modules[ $module ] ) && $this->active_modules[ $module ];
	}

	/**
	 * Get all active modules
	 *
	 * @return array
	 */
	public function get_active_modules(): array {
		return array_keys( $this->active_modules );
	}
}
