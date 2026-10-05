<?php
/**
 * `POST /gift-cards/sync` — settings push (contract B).
 *
 * @package StoreDash\GiftCard\Controllers
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\GiftCard\Settings;
use WP_REST_Request;

/**
 * Sync controller.
 *
 * @since 1.24.0
 */
class Gift_Card_Sync_Controller extends Abstract_Gift_Card_Controller {

	/**
	 * Store the pushed settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync( WP_REST_Request $request ) {
		$data = $this->body( $request );
		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return $this->error( 'storedash_gift_card_missing_settings', 'settings object is required' );
		}

		Settings::save( $data['settings'] );
		\StoreDash_Helpers::debug_log( 'Gift card settings synced' );

		return rest_ensure_response(
			array(
				'success' => true,
				'version' => STOREDASH_VERSION,
			)
		);
	}
}
