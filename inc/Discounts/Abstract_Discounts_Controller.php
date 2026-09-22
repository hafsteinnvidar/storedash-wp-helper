<?php
/**
 * Abstract Discounts Controller
 *
 * @package StoreDash\Discounts
 * @since   1.0.0
 */

namespace StoreDash\Discounts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Abstract base class for all discount controllers.
 *
 * Contains common functionality for discount operations.
 *
 * @since 1.0.0
 */
abstract class Abstract_Discounts_Controller {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Initialization if needed
	}

	/**
	 * Prepare discount data for database
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return array
	 */
	protected function prepare_discount_for_database( $request ) {
		$data = json_decode( $request->get_body(), true );

		// If JSON parsing failed, try to get data from request parameters
		if ( is_null( $data ) ) {
			$data = $request->get_params();
		}

		// Prepare arrays for database storage
		$id_array_fields = array( 'target_ids', 'exclude_ids', 'exclude_category_ids', 'exclude_tag_ids', 'exclude_brand_ids' );
		foreach ( $id_array_fields as $field ) {
			if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$data[ $field ] = implode( ',', array_filter( $data[ $field ] ) );
			}
		}

		// Prepare JSON fields
		if ( isset( $data['rule_config'] ) && is_array( $data['rule_config'] ) ) {
			$data['rule_config'] = wp_json_encode( $data['rule_config'] );
		}

		if ( isset( $data['conditions'] ) && is_array( $data['conditions'] ) ) {
			$data['conditions'] = wp_json_encode( $data['conditions'] );
		}

		// Convert boolean fields to integers for MySQL TINYINT
		$boolean_fields = array( 'enabled', 'disable_on_sale', 'disable_lower_priority', 'disable_with_coupons', 'apply_to_sale_price' );
		foreach ( $boolean_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				// Handle various boolean representations
				$value = $data[ $field ];
				if ( is_bool( $value ) ) {
					$data[ $field ] = $value ? 1 : 0;
				} elseif ( is_string( $value ) ) {
					$data[ $field ] = in_array( strtolower( $value ), array( 'true', '1', 'yes' ), true ) ? 1 : 0;
				} else {
					$data[ $field ] = $value ? 1 : 0;
				}
			}
		}

		// Ensure numeric fields are properly typed
		if ( isset( $data['store_id'] ) ) {
			$data['store_id'] = (int) $data['store_id'];
		}
		if ( isset( $data['priority'] ) ) {
			$data['priority'] = (int) $data['priority'];
		}
		if ( isset( $data['amount'] ) ) {
			$data['amount'] = (float) $data['amount'];
		}

		// Normalize schedule dates to UTC MySQL DATETIME. The dashboard sends
		// ISO-8601 (usually with a Z/offset); storing gmdate() output keeps the
		// column comparable with UTC_TIMESTAMP() in the active-discount queries.
		foreach ( array( 'start_date', 'end_date' ) as $date_field ) {
			if ( ! empty( $data[ $date_field ] ) && is_string( $data[ $date_field ] ) ) {
				$timestamp = strtotime( $data[ $date_field ] );
				if ( false !== $timestamp ) {
					$data[ $date_field ] = gmdate( 'Y-m-d H:i:s', $timestamp );
				}
			}
		}

		return $data;
	}

	/**
	 * Send error response
	 *
	 * @since 1.0.0
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return WP_Error
	 */
	protected function send_error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Send success response
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Response data.
	 * @return WP_REST_Response
	 */
	protected function send_success( $data ) {
		return rest_ensure_response( $data );
	}
}
