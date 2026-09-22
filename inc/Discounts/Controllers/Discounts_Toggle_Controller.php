<?php
/**
 * Discounts Toggle Controller
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
 * Handles toggling discount enabled/disabled state.
 *
 * @since 1.0.0
 */
class Discounts_Toggle_Controller extends Abstract_Discounts_Controller {

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
	 * Toggle discount enabled/disabled
	 *
	 * Uses Action Scheduler for background batch processing to prevent
	 * memory exhaustion on large product sets.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function toggle_discount( $request ) {
		$discount_id = (int) $request->get_param( 'id' );
		$enabled     = $request->get_param( 'enabled' );

		if ( empty( $discount_id ) ) {
			return $this->send_error(
				'storedash_missing_discount_id',
				'Discount ID is required',
				400
			);
		}

		try {
			// Get discount
			$discount = $this->db_handler->get_discount( $discount_id );

			if ( ! $discount ) {
				return $this->send_error(
					'storedash_discount_not_found',
					'Discount not found',
					404
				);
			}

			// Update enabled status
			$updated = $this->db_handler->update_discount( $discount_id, array( 'enabled' => $enabled ? 1 : 0 ) );

			if ( ! $updated ) {
				return $this->send_error(
					'storedash_toggle_failed',
					'Failed to update discount status',
					500
				);
			}

			// Note: Since v3.0.0, all discounts are dynamic (no batch processing needed)
			// Prices are calculated at runtime via hooks

			return $this->send_success(
				array(
					'success'     => true,
					'discount_id' => $discount_id,
					'enabled'     => (bool) $enabled,
					'message'     => $enabled ? 'Discount enabled' : 'Discount disabled',
				)
			);
		} catch ( \Exception $e ) {
			return $this->send_error(
				'storedash_toggle_failed',
				$e->getMessage(),
				500
			);
		}
	}
}
