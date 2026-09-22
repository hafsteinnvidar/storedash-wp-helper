<?php
/**
 * Carts Settings Controller
 *
 * Syncs cart settings from the StoreDash dashboard to WordPress options.
 *
 * @package StoreDash\Carts\Controllers
 * @since   2.0.0
 */

namespace StoreDash\Carts\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Carts\Abstract_Carts_Controller;
use StoreDash\Carts\Services\Cart_Tracking;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles cart settings sync from dashboard.
 *
 * @since 2.0.0
 */
class Carts_Settings_Controller extends Abstract_Carts_Controller {

	/**
	 * Update cart settings from dashboard.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( $request ) {
		$params = $request->get_json_params();

		// Accepts true/false/1/0/"1"/"0"/"true"/"false" (a raw (bool) cast would
		// turn the string "false" into true and make disabling silently fail).
		// Stored as '1'/'0', never a raw boolean: update_option() on a missing
		// option row short-circuits when the new value === false (get_option's
		// miss default), so an explicit disable would silently not persist and
		// the unset-option default (enabled once connected) would keep capture on.
		if ( isset( $params['enabled'] ) ) {
			update_option( 'woodash_cart_tracking_enabled', rest_sanitize_boolean( $params['enabled'] ) ? '1' : '0' );
		}

		if ( isset( $params['abandonment_timeout_minutes'] ) ) {
			$timeout = intval( $params['abandonment_timeout_minutes'] );
			$timeout = max( 10, min( 10080, $timeout ) );
			update_option( 'woodash_cart_abandon_time', $timeout );
		}

		if ( isset( $params['cart_retention_days'] ) ) {
			$retention = intval( $params['cart_retention_days'] );
			$retention = max( 1, min( 365, $retention ) );
			update_option( 'woodash_cart_retention_days', $retention );
		}

		// Checkout marketing opt-in checkbox toggle (controls both the classic
		// and block checkout opt-in, gated on the same option). Accepts a real
		// boolean from true/false/1/0/"1"/"0"; stored as '1'/'0' for the same
		// missing-row reason as above.
		if ( isset( $params['checkout_optin_enabled'] ) ) {
			update_option(
				'woodash_checkout_optin_enabled',
				rest_sanitize_boolean( $params['checkout_optin_enabled'] ) ? '1' : '0'
			);
		}

		// Custom label for the checkout opt-in checkbox (classic + block). An empty
		// string clears the override so the storefront falls back to the plugin's
		// built-in default wording. Plain text only — links/HTML are stripped.
		if ( isset( $params['checkout_optin_label'] ) ) {
			update_option(
				'woodash_checkout_optin_label',
				sanitize_text_field( wp_unslash( (string) $params['checkout_optin_label'] ) )
			);
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'settings' => array(
					'enabled'                     => Cart_Tracking::cart_tracking_enabled(),
					'abandonment_timeout_minutes' => (int) get_option( 'woodash_cart_abandon_time', 60 ),
					'cart_retention_days'         => (int) get_option( 'woodash_cart_retention_days', 30 ),
					'checkout_optin_enabled'      => (bool) get_option( 'woodash_checkout_optin_enabled', false ),
					'checkout_optin_label'        => (string) get_option( 'woodash_checkout_optin_label', '' ),
				),
			)
		);
	}

	/**
	 * Get current cart settings.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function get_settings( $request ) {
		return new WP_REST_Response(
			array(
				'enabled'                     => Cart_Tracking::cart_tracking_enabled(),
				'abandonment_timeout_minutes' => (int) get_option( 'woodash_cart_abandon_time', 60 ),
				'cart_retention_days'         => (int) get_option( 'woodash_cart_retention_days', 30 ),
				'checkout_optin_enabled'      => (bool) get_option( 'woodash_checkout_optin_enabled', false ),
				'checkout_optin_label'        => (string) get_option( 'woodash_checkout_optin_label', '' ),
			)
		);
	}
}
