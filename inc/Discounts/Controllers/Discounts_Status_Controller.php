<?php
declare(strict_types=1);

/**
 * Discounts Status Controller
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
 * Handles getting discount status information.
 *
 * @since 1.0.0
 */
class Discounts_Status_Controller extends Abstract_Discounts_Controller {

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
	 * Get discount status
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_status( $request ) {
		try {
			$stats = $this->db_handler->get_status_stats();

			return $this->send_success(
				array(
					'success' => true,
					'stats'   => $stats,
				)
			);
		} catch ( \Exception $e ) {
			return $this->send_error(
				'storedash_status_failed',
				$e->getMessage(),
				500
			);
		}
	}
}
