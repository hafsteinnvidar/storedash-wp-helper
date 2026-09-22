<?php
declare(strict_types=1);

/**
 * YITH Waitlist Migration REST API
 *
 * Provides REST API endpoint to extract YITH WooCommerce Waitlist data
 * for migration into StoreDash's native waitlist system.
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.5.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * YITH Migration API Class
 */
class YITH_Migration {

	/**
	 * API namespace
	 */
	const NAMESPACE = 'storedash/v1';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/migration/yith-waitlist',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'mode'     => array(
						'default'           => 'check',
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'check', 'fetch' ), true );
						},
					),
					'page'     => array(
						'default'           => 1,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
					'per_page' => array(
						'default'           => 100,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0 && $param <= 500;
						},
					),
				),
			)
		);
	}

	/**
	 * Check permissions
	 */
	public function check_permissions( $request ) {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Handle request based on mode
	 */
	public function handle_request( $request ) {
		$mode = $request->get_param( 'mode' ) ?: 'check';

		if ( ! $this->yith_tables_exist() ) {
			return new \WP_REST_Response(
				array(
					'success'           => true,
					'yith_tables_exist' => false,
					'summary'           => array(
						'total_entries'  => 0,
						'total_products' => 0,
					),
					'data'              => array(),
				),
				200
			);
		}

		if ( 'check' === $mode ) {
			return $this->handle_check();
		}

		return $this->handle_fetch( $request );
	}

	/**
	 * Check if YITH waitlist tables exist
	 */
	private function yith_tables_exist(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$table = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $wpdb->prefix . 'yith_wcwtl_waitlists' )
			)
		);
		return ! empty( $table );
	}

	/**
	 * Handle check mode — return summary with stock breakdown.
	 *
	 * Migrates ALL entries regardless of stock status because YITH's
	 * auto-notify often doesn't work (disabled, cron failure, direct stock updates).
	 * Users on in-stock product waitlists are real subscribers who were never notified.
	 */
	private function handle_check() {
		global $wpdb;
		$waitlists_table = $wpdb->prefix . 'yith_wcwtl_waitlists';
		$users_table     = $wpdb->prefix . 'yith_wcwtl_users';

		// Total subscriptions
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_entries = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $users_table u
			INNER JOIN $waitlists_table w ON u.list_id = w.list_id"
		);

		// Unique products/variations with waitlist subscribers
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_products = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT w.list_id) FROM $waitlists_table w
			INNER JOIN $users_table u ON u.list_id = w.list_id"
		);

		// Unique subscribers
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$unique_users = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.user_email) FROM $users_table u
			INNER JOIN $waitlists_table w ON u.list_id = w.list_id"
		);

		// Get stock status breakdown per waitlist for summary
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$waitlists = $wpdb->get_results(
			"SELECT w.product_id, w.variation_id, w.list_id,
				(SELECT COUNT(*) FROM $users_table u2 WHERE u2.list_id = w.list_id) as user_count
			FROM $waitlists_table w
			INNER JOIN $users_table u ON u.list_id = w.list_id
			GROUP BY w.list_id",
			ARRAY_A
		);

		$in_stock_entries     = 0;
		$out_of_stock_entries = 0;

		foreach ( $waitlists as $row ) {
			$target_id = ! empty( $row['variation_id'] ) ? (int) $row['variation_id'] : (int) $row['product_id'];
			$product   = wc_get_product( $target_id );
			$count     = (int) $row['user_count'];

			if ( $product && $product->is_in_stock() ) {
				$in_stock_entries += $count;
			} else {
				$out_of_stock_entries += $count;
			}
		}

		return new \WP_REST_Response(
			array(
				'success'           => true,
				'yith_tables_exist' => true,
				'summary'           => array(
					'total_entries'        => $total_entries,
					'total_products'       => $total_products,
					'unique_users'         => $unique_users,
					'in_stock_entries'     => $in_stock_entries,
					'out_of_stock_entries' => $out_of_stock_entries,
				),
			),
			200
		);
	}

	/**
	 * Handle fetch mode — return ALL paginated entries with stock status.
	 */
	private function handle_fetch( $request ) {
		global $wpdb;
		$waitlists_table = $wpdb->prefix . 'yith_wcwtl_waitlists';
		$users_table     = $wpdb->prefix . 'yith_wcwtl_users';

		$per_page = (int) ( $request->get_param( 'per_page' ) ?: 100 );
		$page     = (int) ( $request->get_param( 'page' ) ?: 1 );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $users_table u
			INNER JOIN $waitlists_table w ON u.list_id = w.list_id"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					w.product_id,
					w.variation_id,
					w.product_name,
					u.user_email,
					u.user_id,
					u.registration_date_gmt
				FROM $users_table u
				INNER JOIN $waitlists_table w ON u.list_id = w.list_id
				ORDER BY u.registration_date_gmt ASC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$data = array();
		foreach ( $rows as $row ) {
			$product_id   = (int) $row['product_id'];
			$variation_id = ! empty( $row['variation_id'] ) ? (int) $row['variation_id'] : null;

			$customer_name = $this->resolve_customer_name( $row['user_email'], $row['user_id'] );

			$product_sku          = null;
			$variation_attributes = null;
			$stock_status         = 'unknown';

			$target_id = $variation_id ?: $product_id;
			$product   = wc_get_product( $target_id );

			if ( $product ) {
				$product_sku  = $product->get_sku() ?: null;
				$stock_status = $product->is_in_stock() ? 'instock' : 'outofstock';

				if ( $variation_id && $product->is_type( 'variation' ) ) {
					$attrs = $product->get_attributes();
					if ( ! empty( $attrs ) ) {
						$variation_attributes = $attrs;
					}
				}
			} else {
				$stock_status = 'deleted';
			}

			$data[] = array(
				'product_id'           => $product_id,
				'variation_id'         => $variation_id,
				'product_name'         => $row['product_name'],
				'product_sku'          => $product_sku,
				'variation_attributes' => $variation_attributes,
				'customer_email'       => $row['user_email'],
				'customer_name'        => $customer_name,
				'stock_status'         => $stock_status,
				'created_at'           => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $row['registration_date_gmt'] ) ),
			);
		}

		return new \WP_REST_Response(
			array(
				'success'           => true,
				'yith_tables_exist' => true,
				'summary'           => array(
					'total_entries'  => $total,
					'total_products' => 0,
				),
				'data'              => $data,
				'pagination'        => array(
					'total'       => $total,
					'page'        => $page,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total / $per_page ),
				),
			),
			200
		);
	}

	/**
	 * Resolve customer name from WP user ID or extract from email
	 */
	private function resolve_customer_name( string $email, $user_id ): string {
		if ( ! empty( $user_id ) ) {
			$user = get_userdata( (int) $user_id );
			if ( $user && ! empty( $user->display_name ) ) {
				return $user->display_name;
			}
		}

		// Fallback: extract name from email prefix
		$prefix = strstr( $email, '@', true );
		if ( $prefix ) {
			return ucwords( str_replace( array( '.', '_', '-' ), ' ', $prefix ) );
		}

		return '';
	}
}

// Initialize
new YITH_Migration();
