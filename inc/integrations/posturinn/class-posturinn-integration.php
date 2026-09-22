<?php
/**
 * Posturinn Integration Handler for Storedash
 *
 * This file manages the integration between Storedash and the Posturinn plugin
 * to prevent duplicate shipment creation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Posturinn_Integration extends StoreDash_Base_Integration {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Set integration properties
		$this->id           = 'posturinn';
		$this->name         = 'Posturinn Integration';
		$this->description  = 'Prevents duplicate shipment creation for orders managed through StoreDash';
		$this->version      = '1.0.0';
		$this->plugin_class = 'POSTIS_Admin';
		$this->min_version  = '1.0.0';

		// Add a meta flag when orders are updated from WooDash
		add_action( 'woocommerce_rest_update_shop_order_object', array( $this, 'mark_woodash_managed_order' ), 10, 3 );
	}

	/**
	 * Check if Posturinn plugin is available
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'POSTIS_Admin' );
	}

	/**
	 * Get data schema for this integration
	 * This integration doesn't transform data
	 *
	 * @return array
	 */
	public function get_data_schema(): array {
		return array();
	}

	/**
	 * Transform data (pass-through for this integration)
	 *
	 * @param mixed  $data Raw data
	 * @param string $entity_type Entity type
	 * @return array
	 */
	public function transform_data( $data, string $entity_type = 'product' ): array {
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Mark orders that are updated from WooDash
	 */
	public function mark_woodash_managed_order( $order, $request, $creating ) {
		if ( ! $creating && $this->is_woodash_request() ) {
			$order->update_meta_data( '_woodash_managed', 'yes' );
			$order->update_meta_data( '_woodash_last_update', current_time( 'mysql' ) );
		}
	}

	/**
	 * Check if the current request is from WooDash
	 */
	private function is_woodash_request() {
		$user_agent  = StoreDash_Helpers::get_server_var( 'HTTP_USER_AGENT' );
		$origin      = StoreDash_Helpers::get_server_var( 'HTTP_ORIGIN', 'url' );
		$referer     = StoreDash_Helpers::get_server_var( 'HTTP_REFERER', 'url' );
		$auth_header = StoreDash_Helpers::get_server_var( 'HTTP_AUTHORIZATION' );
		$request_uri = StoreDash_Helpers::get_server_var( 'REQUEST_URI' );

		// Check various indicators that this is a WooDash request
		return (
			strpos( $user_agent, 'woo-dash' ) !== false ||
			strpos( $user_agent, 'storedash' ) !== false ||
			strpos( $origin, 'woodash.app' ) !== false ||
			strpos( $origin, 'storedash.io' ) !== false ||
			strpos( $origin, 'app.storedash.io' ) !== false ||
			strpos( $referer, 'woodash.app' ) !== false ||
			strpos( $referer, 'storedash.io' ) !== false ||
			strpos( $referer, 'app.storedash.io' ) !== false ||
			strpos( $auth_header, 'WooDash' ) !== false ||
			strpos( $auth_header, 'StoreDash' ) !== false ||
			( defined( 'REST_REQUEST' ) && REST_REQUEST && strpos( $request_uri, '/storedash/v1/' ) !== false )
		);
	}
}

// Instantiation is owned by StoreDash_Integration_Registry::discover_integrations()
// (it require's this file, then `new`s the class and calls register()). The
// constructor wires the hooks, so a single registry-owned instance is sufficient.
// The previous file-bottom `new` here caused double-instantiation (CL-6).
