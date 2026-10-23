<?php
/**
 * Checkout Settings REST Controller
 *
 * The app→plugin contract for checkout-optimization configuration.
 *
 * Routes (namespace `storedash/v1`, `manage_woocommerce`):
 *   GET  /checkout/settings — `{ config, config_hash, checkout_type, plugin_version }`
 *   POST /checkout/settings — body = partial config → sanitize + update →
 *                             `{ success: true, config, config_hash, checkout_type, plugin_version }`;
 *                             400 when the body is not a JSON object.
 *
 * Supabase (`checkout_settings`) remains the source of truth; this endpoint
 * is the storefront's replica.
 *
 * @package StoreDash\Checkout\Controllers
 * @since   1.29.0
 */

namespace StoreDash\Checkout\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Checkout\Config;
use WP_Error;

/**
 * Settings controller.
 *
 * @since 1.29.0
 */
class Checkout_Settings_Controller {

	/**
	 * Current config.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_settings( $request ) {
		return rest_ensure_response( $this->envelope( Config::get() ) );
	}

	/**
	 * Apply a (partial) config push from the dashboard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update_settings( $request ) {
		$raw = $request->get_json_params();
		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				'storedash_checkout_invalid_body',
				__( 'Request body must be a JSON object.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		$clean  = Config::sanitize( $raw );
		$config = $clean ? Config::update( $clean ) : Config::get();

		return rest_ensure_response( array( 'success' => true ) + $this->envelope( $config ) );
	}

	/**
	 * Shared response body.
	 *
	 * @param array $config Full config.
	 * @return array
	 */
	private function envelope( array $config ): array {
		return array(
			'config'         => $config,
			'config_hash'    => (string) ( $config['config_hash'] ?? '' ),
			'checkout_type'  => self::checkout_type(),
			'plugin_version' => defined( 'STOREDASH_VERSION' ) ? (string) STOREDASH_VERSION : '',
		);
	}

	/**
	 * Which checkout the store runs (same probe as DK_Config_Controller).
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
}
