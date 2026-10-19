<?php
/**
 * Shared helpers for the SEO REST controllers.
 *
 * Auth lives in Route_Registry (Base_Permissions → manage_woocommerce, the
 * consumer-key user from StoreDash_Auth_Handler), like every Storedash module.
 *
 * @package StoreDash\Seo\Controllers
 * @since   1.26.0
 */

namespace StoreDash\Seo\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Base controller.
 *
 * @since 1.26.0
 */
abstract class Abstract_Seo_Controller {

	/**
	 * Decode the JSON body (falls back to request params).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	protected function body( $request ): array {
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Error response.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	protected function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Success response.
	 *
	 * @param array $data Payload.
	 * @return mixed WP_REST_Response.
	 */
	protected function success( array $data ) {
		return rest_ensure_response( $data );
	}
}
