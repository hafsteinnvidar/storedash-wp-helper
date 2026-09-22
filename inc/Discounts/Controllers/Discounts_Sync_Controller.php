<?php
/**
 * Discounts Sync Controller
 *
 * @package StoreDash\Discounts\Controllers
 * @since   1.0.0
 */

namespace StoreDash\Discounts\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Discounts\Abstract_Discounts_Controller;
use StoreDash\Discounts\Sync\Discount_Sync_Manager;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles syncing discounts from Supabase to WordPress.
 *
 * @since 1.0.0
 */
class Discounts_Sync_Controller extends Abstract_Discounts_Controller {

	/**
	 * Sync manager instance.
	 *
	 * @since 1.0.0
	 * @var Discount_Sync_Manager
	 */
	protected $sync_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();
		$this->sync_manager = new Discount_Sync_Manager();
	}

	/**
	 * Sync discount from Supabase
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function sync_discount( $request ) {
		\StoreDash_Helpers::debug_log( 'sync_discount() called' );

		$discount_data = $this->prepare_discount_for_database( $request );

		\StoreDash_Helpers::debug_log( 'Prepared discount data', array( 'supabase_id' => $discount_data['supabase_id'] ?? null ) );

		$validation = $this->validate_discount_data( $discount_data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		try {
			\StoreDash_Helpers::debug_log( 'Calling sync_from_supabase()' );
			$result = $this->sync_manager->sync_from_supabase( $discount_data );

			if ( is_wp_error( $result ) ) {
				\StoreDash_Helpers::log_message(
					'sync_from_supabase returned WP_Error',
					'error',
					array(
						'error' => $result->get_error_message(),
					)
				);
				return $result;
			}

			\StoreDash_Helpers::debug_log( 'Sync successful', array( 'wp_rule_id' => $result['wp_rule_id'] ) );

			// v3 sync is synchronous; return the result directly. (The former
			// processing_in_background / batch_status_endpoint fields advertised a
			// batch-status route that was never registered — removed, audit M7.)
			return $this->send_success(
				array(
					'success'    => true,
					'wp_rule_id' => $result['wp_rule_id'],
					'message'    => $result['message'],
				)
			);
		} catch ( \Exception $e ) {
			\StoreDash_Helpers::log_message(
				'Exception in sync_discount',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return $this->send_error(
				'storedash_sync_failed',
				$e->getMessage(),
				500
			);
		} catch ( \Throwable $t ) {
			\StoreDash_Helpers::log_message(
				'Throwable in sync_discount',
				'error',
				array(
					'error' => $t->getMessage(),
					'file'  => $t->getFile(),
					'line'  => $t->getLine(),
				)
			);
			return $this->send_error(
				'storedash_sync_failed',
				$t->getMessage(),
				500
			);
		}
	}

	/**
	 * Validate discount data before syncing.
	 *
	 * @since 2.3.0
	 *
	 * @param array $data Prepared discount data.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function validate_discount_data( array $data ) {
		if ( empty( $data['supabase_id'] ) ) {
			return new WP_Error( 'storedash_missing_supabase_id', 'Supabase ID is required for syncing', array( 'status' => 400 ) );
		}

		$valid_rule_types = array( 'store_wide', 'product', 'category', 'tag', 'brand', 'bogo', 'quantity' );
		if ( ! empty( $data['rule_type'] ) && ! in_array( $data['rule_type'], $valid_rule_types, true ) ) {
			return new WP_Error(
				'storedash_invalid_rule_type',
				sprintf( 'Invalid rule_type: %s', sanitize_text_field( $data['rule_type'] ) ),
				array( 'status' => 400 )
			);
		}

		$valid_discount_types = array( 'percentage', 'fixed_amount', 'fixed_price' );
		if ( ! empty( $data['discount_type'] ) && ! in_array( $data['discount_type'], $valid_discount_types, true ) ) {
			return new WP_Error(
				'storedash_invalid_discount_type',
				sprintf( 'Invalid discount_type: %s', sanitize_text_field( $data['discount_type'] ) ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $data['amount'] ) ) {
			if ( ! is_numeric( $data['amount'] ) || (float) $data['amount'] < 0 ) {
				return new WP_Error( 'storedash_invalid_amount', 'Amount must be a non-negative number', array( 'status' => 400 ) );
			}

			if ( isset( $data['discount_type'] ) && $data['discount_type'] === 'percentage' && (float) $data['amount'] > 100 ) {
				return new WP_Error( 'storedash_invalid_percentage', 'Percentage amount cannot exceed 100', array( 'status' => 400 ) );
			}
		}

		return true;
	}
}
