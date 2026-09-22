<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StoreDash API - Orders Controller
 *
 * Provides bulk operations for order data that WooCommerce REST API doesn't support natively.
 * Currently: bulk notes fetching to eliminate N+1 API calls during sync.
 */
class StoreDash_API_Orders {
	/**
	 * API namespace.
	 */
	protected $namespace = 'storedash/v1';

	/**
	 * Route base.
	 */
	protected $rest_base = 'orders';

	/**
	 * Register the routes for orders API.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/notes/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_bulk_notes' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'order_ids' => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array( 'type' => 'integer' ),
						'maxItems'          => 100,
						'validate_callback' => array( $this, 'validate_order_ids' ),
						'sanitize_callback' => array( $this, 'sanitize_order_ids' ),
					),
				),
			)
		);
	}

	/**
	 * Validate order_ids parameter.
	 *
	 * @param mixed           $value   Parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return bool|WP_Error
	 */
	public function validate_order_ids( $value, $request, $param ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'invalid_order_ids',
				__( 'order_ids must be an array.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $value ) ) {
			return new WP_Error(
				'empty_order_ids',
				__( 'order_ids must not be empty.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $value ) > 100 ) {
			return new WP_Error(
				'too_many_order_ids',
				__( 'order_ids must contain at most 100 items.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $value as $id ) {
			if ( ! is_numeric( $id ) || intval( $id ) <= 0 ) {
				return new WP_Error(
					'invalid_order_id',
					/* translators: %s: order ID */
					sprintf( __( 'Invalid order ID: %s', 'storedash' ), $id ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Sanitize order_ids to array of integers.
	 *
	 * @param array $value Raw order IDs.
	 * @return array
	 */
	public function sanitize_order_ids( $value ) {
		return array_map( 'absint', $value );
	}

	/**
	 * Fetch notes for multiple orders in a single request.
	 *
	 * Uses wc_get_order_notes() per order which is future-proof for HPOS migration.
	 * Even with 100 orders, local PHP function calls are ~50ms total vs ~30s for 100 HTTP calls.
	 *
	 * @param WP_REST_Request $request Request object containing order_ids array.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_bulk_notes( $request ) {
		$order_ids = $request->get_param( 'order_ids' );
		$notes     = array();

		// Batch-validate all order IDs with a single query instead of N wc_get_order() calls
		$valid_orders    = wc_get_orders(
			array(
				'include' => $order_ids,
				'limit'   => count( $order_ids ),
				'return'  => 'ids',
			)
		);
		$valid_order_ids = array_flip( $valid_orders );

		foreach ( $order_ids as $order_id ) {
			if ( ! isset( $valid_order_ids[ $order_id ] ) ) {
				$notes[ $order_id ] = array();
				continue;
			}

			$order_notes = wc_get_order_notes( array( 'order_id' => $order_id ) );
			$formatted   = array();

			foreach ( $order_notes as $note ) {
				$formatted[] = array(
					'id'               => $note->id,
					'author'           => $note->added_by,
					'date_created'     => $note->date_created ? $note->date_created->date( 'Y-m-d\TH:i:s' ) : '',
					'date_created_gmt' => $note->date_created ? wc_rest_prepare_date_response( $note->date_created, true ) : '',
					'note'             => $note->content,
					'customer_note'    => (bool) $note->customer_note,
				);
			}

			$notes[ $order_id ] = $formatted;
		}

		return rest_ensure_response( array( 'notes' => $notes ) );
	}
}
