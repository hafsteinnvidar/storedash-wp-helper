<?php
/**
 * Custom Order Statuses
 *
 * Registers custom WooCommerce order statuses used by StoreDash.
 *
 * REST / wc/v3 order `status` slug is `ready-pickup` (post status `wc-ready-pickup`).
 * StoreDash app DB uses `ready-for-pickup`; the dashboard maps when calling Woo.
 *
 * @package StoreDash
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Custom_Order_Statuses {

	/**
	 * Single instance
	 *
	 * @var StoreDash_Custom_Order_Statuses|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hooks into WordPress
	 *
	 * Custom statuses must be registered with WordPress (register_post_status), listed
	 * via wc_order_statuses, and (for HPOS) merged via woocommerce_register_shop_order_post_statuses.
	 *
	 * This class is instantiated from StoreDash_Bootstrap::init_custom_order_statuses_early()
	 * at init priority 0 (see class-storedash-bootstrap.php). We still call register_statuses()
	 * in the constructor (not on a later init callback) so post status exists in this same request.
	 */
	private function __construct() {
		// Register post status immediately — this class is instantiated inside
		// init_components() which runs on `init` priority 10, so hooking
		// register_statuses to `init` at priority 9 would be a no-op (already past).
		$this->register_statuses();

		add_filter( 'wc_order_statuses', array( $this, 'add_to_wc_statuses' ) );

		// Bulk actions on admin orders list (HPOS + legacy)
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_actions' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_mark_ready_pickup' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_mark_ready_pickup' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'bulk_ready_pickup_admin_notice' ) );

		// HPOS: register in WooCommerce's own post statuses array
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'register_order_post_statuses' ) );

		// Include in reports / "All" count queries
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'add_to_report_statuses' ) );

		// Mark as a valid order status in various WooCommerce contexts
		add_filter( 'wc_order_is_editable', array( $this, 'mark_editable' ), 10, 2 );

		// Admin CSS for the status badge and tab
		add_action( 'admin_head', array( $this, 'admin_status_css' ) );
	}

	/**
	 * Args for wc-ready-pickup — shared by register_post_status and HPOS filter.
	 *
	 * @return array<string, mixed>
	 */
	private function get_ready_pickup_status_args(): array {
		return array(
			'label'                     => _x( 'Ready for Pickup', 'Order status', 'storedash' ),
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop(
				'Ready for Pickup <span class="count">(%s)</span>',
				'Ready for Pickup <span class="count">(%s)</span>',
				'storedash'
			),
		);
	}

	/**
	 * Register custom post statuses with WordPress
	 */
	public function register_statuses(): void {
		register_post_status( 'wc-ready-pickup', $this->get_ready_pickup_status_args() );
	}

	/**
	 * HPOS: register wc-ready-pickup in WooCommerce's shop order post status list.
	 *
	 * Without this, list queries may not treat the status as valid for the orders table.
	 *
	 * @param array<string, array<string, mixed>> $statuses Status slug => register_post_status-style args.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_order_post_statuses( array $statuses ): array {
		$statuses['wc-ready-pickup'] = $this->get_ready_pickup_status_args();
		return $statuses;
	}

	/**
	 * Add "Mark ready-pickup" to the Bulk Actions dropdown on the orders list.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( array $actions ): array {
		$actions['mark_ready-pickup'] = __( 'Change status to Ready for Pickup', 'storedash' );
		return $actions;
	}

	/**
	 * Apply bulk action: set selected orders to ready-pickup (HPOS + legacy list tables).
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action name.
	 * @param int[]  $ids         Order IDs (or post IDs for legacy CPT list).
	 * @return string
	 */
	public function handle_bulk_mark_ready_pickup( $redirect_to, $action, $ids ) {
		if ( 'mark_ready-pickup' !== $action ) {
			return $redirect_to;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$updated = 0;
		foreach ( (array) $ids as $id ) {
			$order_id = absint( $id );
			if ( ! $order_id ) {
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$order->update_status(
				'ready-pickup',
				__( 'Bulk action: status changed to Ready for Pickup.', 'storedash' ),
				true
			);
			++$updated;
		}

		return add_query_arg( 'storedash_bulk_ready_pickup', $updated, $redirect_to );
	}

	/**
	 * Success notice after bulk status change.
	 */
	public function bulk_ready_pickup_admin_notice(): void {
		if ( ! isset( $_GET['storedash_bulk_ready_pickup'] ) ) {
			return;
		}

		$count = absint( wp_unslash( $_GET['storedash_bulk_ready_pickup'] ) );
		if ( ! $count ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			esc_html(
				/* translators: %d: number of orders updated */
				_n(
					'%d order updated to Ready for Pickup.',
					'%d orders updated to Ready for Pickup.',
					$count,
					'storedash'
				)
			),
			absint( $count )
		);
		echo '</p></div>';
	}

	/**
	 * Include custom status in WooCommerce report queries and the "All" count.
	 *
	 * @param array $statuses Report statuses (slugs without wc- prefix).
	 * @return array
	 */
	public function add_to_report_statuses( array $statuses ): array {
		if ( ! in_array( 'ready-pickup', $statuses, true ) ) {
			$statuses[] = 'ready-pickup';
		}
		return $statuses;
	}

	/**
	 * Allow editing orders with the custom status.
	 *
	 * @param bool     $editable Current editable state.
	 * @param WC_Order $order    The order object.
	 * @return bool
	 */
	public function mark_editable( bool $editable, $order ): bool {
		if ( $order && 'ready-pickup' === $order->get_status() ) {
			return true;
		}
		return $editable;
	}

	/**
	 * Inject CSS for the status badge colour and tab visibility.
	 *
	 * WooCommerce renders status tabs using `.status-{slug}` classes —
	 * without a matching rule the tab is invisible even when present in the DOM.
	 */
	public function admin_status_css(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Only load on WooCommerce orders pages (HPOS and legacy)
		$order_screens = array( 'woocommerce_page_wc-orders', 'edit-shop_order' );
		if ( ! in_array( $screen->id, $order_screens, true ) ) {
			return;
		}

		echo '<style>
			/* Status badge on the orders table */
			.order-status.status-ready-pickup {
				background: #f0f4c3;
				color: #33691e;
			}
			/* Status tab above orders list — ensure count badge renders */
			.wp-list-table .column-order_status mark.ready-pickup {
				background: #f0f4c3;
				color: #33691e;
			}
		</style>';
	}

	/**
	 * Add custom statuses to WooCommerce's status list
	 *
	 * Always sets wc-ready-pickup so admin tabs and list queries see a registered label.
	 * Prefer inserting immediately after wc-processing; if that key is missing, append.
	 *
	 * @param array $statuses Existing WC statuses (slug => label).
	 * @return array Modified statuses.
	 */
	public function add_to_wc_statuses( array $statuses ): array {
		$pickup_label = _x( 'Ready for Pickup', 'Order status', 'storedash' );

		// Control insertion order: drop existing key if present, then re-add once.
		unset( $statuses['wc-ready-pickup'] );

		$new_statuses = array();

		foreach ( $statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new_statuses['wc-ready-pickup'] = $pickup_label;
			}
		}

		if ( ! isset( $new_statuses['wc-ready-pickup'] ) ) {
			$new_statuses['wc-ready-pickup'] = $pickup_label;
		}

		return $new_statuses;
	}
}
