<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StoreDash API - Coupons Controller
 *
 * Provides the ONE coupon operation the WooCommerce REST API does not support:
 * setting a coupon's publish/draft status. The core `wc/v3/coupons` controller
 * reads `status` only as a list filter — it has no `status` in its write schema
 * and never calls set_status(), so a coupon created/updated via REST is always
 * `publish` (live and redeemable). All other coupon CRUD stays on the standard
 * WooCommerce REST API; this endpoint exists solely to toggle status.
 *
 * Route: POST /storedash/v1/coupons/{id}/status   body: { "status": "publish"|"draft" }
 */
class StoreDash_API_Coupons {
	/**
	 * API namespace.
	 */
	protected $namespace = 'storedash/v1';

	/**
	 * Route base.
	 */
	protected $rest_base = 'coupons';

	/**
	 * Register the routes for the coupons API.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_status' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'id'     => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && intval( $value ) > 0;
						},
					),
					'status' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'publish', 'draft' ),
					),
				),
			)
		);
	}

	/**
	 * Set a coupon's publish/draft status.
	 *
	 * Coupons are the `shop_coupon` post type; WooCommerce treats a non-published
	 * coupon as invalid at checkout, so toggling post_status is what actually
	 * enables/disables the coupon in the store.
	 *
	 * @param WP_REST_Request $request Request object ({ id } route param, { status } body).
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_status( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$status = $request->get_param( 'status' );

		$post = get_post( $id );
		if ( ! $post || 'shop_coupon' !== $post->post_type ) {
			return new WP_Error(
				'coupon_not_found',
				__( 'Coupon not found.', 'storedash' ),
				array( 'status' => 404 )
			);
		}

		// No-op if already in the requested state — avoids a needless write and
		// a spurious post_modified bump on every status sync from the dashboard.
		if ( $post->post_status === $status ) {
			return rest_ensure_response(
				array(
					'id'      => $id,
					'status'  => $status,
					'changed' => false,
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'coupon_status_update_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Drop the stale WC_Coupon/post cache so a subsequent read (or the next
		// storedash-sync pull) sees the new status immediately.
		clean_post_cache( $id );

		return rest_ensure_response(
			array(
				'id'      => $id,
				'status'  => $status,
				'changed' => true,
			)
		);
	}
}
