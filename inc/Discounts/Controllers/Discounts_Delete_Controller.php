<?php
/**
 * Discounts Delete Controller
 *
 * @package StoreDash\Discounts\Controllers
 * @since   1.0.0
 */

namespace StoreDash\Discounts\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Discounts\Abstract_Discounts_Controller;
use StoreDash\Discounts\Sync\Discount_DB_Handler;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles deleting discounts from WordPress.
 *
 * @since 1.0.0
 */
class Discounts_Delete_Controller extends Abstract_Discounts_Controller {

	/**
	 * DB handler instance.
	 *
	 * @since 1.0.0
	 * @var Discount_DB_Handler
	 */
	protected $db_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();
		$this->db_handler = new Discount_DB_Handler();
	}

	/**
	 * Delete discount completely from WordPress.
	 *
	 * Since v3.0.0 all discounts are dynamic (applied at cart-time),
	 * so deletion is immediate with no product price restoration needed.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_discount( $request ) {
		$discount_id = (int) $request->get_param( 'id' );

		if ( empty( $discount_id ) ) {
			return $this->send_error(
				'storedash_missing_discount_id',
				'Discount ID is required',
				400
			);
		}

		try {
			// Get discount details before deletion
			$discount = $this->db_handler->get_discount( $discount_id );

			if ( ! $discount ) {
				// Discount not found - might already be deleted, return success
				return $this->send_success(
					array(
						'success'     => true,
						'discount_id' => $discount_id,
						'message'     => 'Discount not found (may already be deleted)',
					)
				);
			}

			// Since v3.0.0, all discounts are dynamic — no product prices need restoration
			$deleted = $this->db_handler->delete_discount( $discount_id );

			if ( ! $deleted ) {
				return $this->send_error(
					'storedash_delete_failed',
					'Failed to delete discount from database',
					500
				);
			}

			\StoreDash_Helpers::debug_log(
				'Successfully deleted discount',
				array(
					'discount_id' => $discount_id,
				)
			);

			return $this->send_success(
				array(
					'success'     => true,
					'discount_id' => $discount_id,
					'message'     => 'Discount deleted successfully',
				)
			);
		} catch ( \Exception $e ) {
			\StoreDash_Helpers::log_message(
				'Exception deleting discount',
				'error',
				array(
					'discount_id' => $discount_id,
					'error'       => $e->getMessage(),
				)
			);

			return $this->send_error(
				'storedash_delete_failed',
				$e->getMessage(),
				500
			);
		}
	}
}
