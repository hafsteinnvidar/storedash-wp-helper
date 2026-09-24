<?php
declare(strict_types=1);

/**
 * Stock Monitor
 *
 * Monitors WooCommerce stock changes and triggers waitlist notifications
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.0.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock Monitor Class
 */
class Stock_Monitor {

	/**
	 * Maximum waitlist entries to include in a single webhook payload
	 */
	const MAX_WEBHOOK_ENTRIES = 100;

	/**
	 * Track products being updated to prevent duplicate webhooks
	 *
	 * @var array
	 */
	private static $processing = array();

	/**
	 * In-memory cache for old stock status (replaces update_post_meta per save)
	 *
	 * @var array<int, string>
	 */
	private static $old_status_cache = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into product stock changes (quantity-driven events)
		add_action( 'woocommerce_product_set_stock', array( $this, 'handle_stock_change' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'handle_stock_change' ), 10, 1 );

		// Status-only changes: for manage_stock=no products an out-of-stock -> in-stock
		// toggle fires no set_stock event, so we must also watch the status hook or
		// waitlisters are never notified.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'handle_stock_status_change' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'handle_stock_status_change' ), 10, 3 );

		// Track stock status before updates (in-memory, no DB writes)
		add_action( 'woocommerce_before_product_object_save', array( $this, 'track_stock_status_before_save' ), 10, 2 );
	}

	/**
	 * Track stock status before product save.
	 * Uses in-memory static cache instead of post meta (zero DB writes).
	 */
	public function track_stock_status_before_save( $product, $data_store ) {
		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();
		if ( ! $product_id ) {
			return;
		}

		// Only cache if we haven't already (first save wins)
		if ( ! isset( self::$old_status_cache[ $product_id ] ) ) {
			self::$old_status_cache[ $product_id ] = get_post_meta( $product_id, '_stock_status', true );
		}
	}

	/**
	 * Resolve the parent product ID and variation ID for a product object.
	 *
	 * For a variation the object's own ID is the variation_id and its parent is the
	 * product_id; for a simple product the variation_id is 0.
	 *
	 * @param object $product The product (or variation) object.
	 * @param int    $own_id  The product object's own ID.
	 * @return array{0:int,1:int} [ parent_product_id, variation_id ].
	 */
	private function resolve_parent_variation( $product, $own_id ) {
		if ( $product->is_type( 'variation' ) ) {
			return array( (int) $product->get_parent_id(), (int) $own_id );
		}
		return array( (int) $own_id, 0 );
	}

	/**
	 * Handle stock change
	 */
	public function handle_stock_change( $product ) {
		if ( ! $product || ! is_object( $product ) ) {
			return;
		}

		$own_id = $product->get_id();

		// Prevent duplicate processing
		if ( isset( self::$processing[ $own_id ] ) ) {
			return;
		}
		self::$processing[ $own_id ] = true;

		// Determine the parent/variation split.
		list( $product_id, $variation_id ) = $this->resolve_parent_variation( $product, $own_id );

		// Get old status from in-memory cache, new status from product object
		$old_status = self::$old_status_cache[ $own_id ] ?? '';
		$new_status = $product->get_stock_status();

		// Clean up cache entry
		unset( self::$old_status_cache[ $own_id ] );

		// Only process actual status changes
		if ( $old_status === $new_status || empty( $old_status ) ) {
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			\StoreDash_Helpers::debug_log(
				'Stock status changed',
				array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'old'          => $old_status,
					'new'          => $new_status,
				)
			);
		}

		// Check if product came back in stock
		if ( $old_status === 'outofstock' && $new_status === 'instock' ) {
			$this->trigger_stock_available( $product, $product_id, $variation_id );
		}
	}

	/**
	 * Handle a stock STATUS change (e.g. manage_stock=no products toggled
	 * out-of-stock -> in-stock), which does not fire a set_stock quantity event.
	 *
	 * @param int    $product_id   The product (or variation) ID.
	 * @param string $stock_status The new stock status.
	 * @param object $product      The product object.
	 */
	public function handle_stock_status_change( $product_id, $stock_status, $product ) {
		if ( ! $product || ! is_object( $product ) ) {
			return;
		}

		// Dedupe with handle_stock_change, which keys self::$processing on the
		// product's own ID. A product that ALSO fires set_stock is processed once.
		$own_id = (int) $product_id;
		if ( isset( self::$processing[ $own_id ] ) ) {
			return;
		}

		// Old status from the in-memory cache captured in before_product_object_save.
		$old_status = self::$old_status_cache[ $own_id ] ?? '';

		// Only act on a genuine out-of-stock -> in-stock transition.
		if ( 'instock' !== $stock_status || 'outofstock' !== $old_status ) {
			return;
		}

		self::$processing[ $own_id ] = true;
		unset( self::$old_status_cache[ $own_id ] );

		// Resolve the parent/variation split like handle_stock_change does.
		list( $parent_id, $variation_id ) = $this->resolve_parent_variation( $product, $own_id );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			\StoreDash_Helpers::debug_log(
				'Stock status changed (status-only)',
				array(
					'product_id'   => $parent_id,
					'variation_id' => $variation_id,
					'old'          => $old_status,
					'new'          => $stock_status,
				)
			);
		}

		$this->trigger_stock_available( $product, $parent_id, $variation_id );
	}

	/**
	 * Trigger stock available webhook
	 */
	private function trigger_stock_available( $product, $product_id, $variation_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_product_waitlist';

		// Count total pending entries
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$entry_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name
				WHERE product_id = %d
				AND variation_id = %d
				AND status = 'pending'",
				$product_id,
				$variation_id
			)
		);

		// Shoppers notified on an earlier restock may be re-notified (Storedash
		// decides per store — auto re-join). Their rows are 'notified' here, so
		// counting only 'pending' would never fire for them. The local
		// notified_at is the FIRST email; Storedash caps re-notify at a 30-day
		// window x 3 emails, so the last one can land up to 60 days later —
		// 90 days covers it. The webhook payload's entries/count stay pending-only.
		if ( 0 === $entry_count ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
			$recently_notified = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $table_name
					WHERE product_id = %d
					AND variation_id = %d
					AND status = 'notified'
					AND notified_at >= DATE_SUB( %s, INTERVAL 90 DAY )",
					$product_id,
					$variation_id,
					current_time( 'mysql' )
				)
			);

			if ( 0 === $recently_notified ) {
				return;
			}
		}

		// Query pending waitlist entries (bounded to prevent oversized payloads)
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name
				WHERE product_id = %d
				AND variation_id = %d
				AND status = 'pending'
				ORDER BY created_at ASC
				LIMIT %d",
				$product_id,
				$variation_id,
				self::MAX_WEBHOOK_ENTRIES
			),
			ARRAY_A
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			\StoreDash_Helpers::debug_log(
				'Waitlist: triggering stock available',
				array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'entries'      => $entry_count,
					'truncated'    => $entry_count > self::MAX_WEBHOOK_ENTRIES,
				)
			);
		}

		// Get product details
		$product_name  = $product->get_name();
		$product_url   = get_permalink( $product_id );
		$product_image = wp_get_attachment_url( $product->get_image_id() );
		if ( empty( $product_image ) && $variation_id ) {
			$parent = wc_get_product( $product_id );
			if ( $parent ) {
				$product_image = wp_get_attachment_url( $parent->get_image_id() );
			}
		}
		$product_sku = $product->get_sku();

		// Gate on a completed Storedash connection, honoring "unset = default,
		// explicitly-empty = disabled".
		$webhook_url = \StoreDash_Helpers::resolve_webhook_url( 'storedash_waitlist_webhook_url', 'https://webhooks.storedash.io/q13udwod7lphmi' );
		if ( '' === $webhook_url ) {
			return;
		}

		$payload = array(
			'event'      => 'waitlist.stock_available',
			'store_id'   => (string) get_option( 'woodash_store_id', '' ),
			'store_url'  => get_site_url(),
			'data'       => array(
				'product_id'       => $product_id,
				'variation_id'     => $variation_id ? $variation_id : null,
				'product_name'     => $product_name,
				'product_url'      => $product_url,
				'product_image'    => $product_image,
				'product_sku'      => $product_sku,
				'stock_quantity'   => $product->get_stock_quantity(),
				'waitlist_entries' => $entries,
				'entry_count'      => $entry_count,
				'has_more'         => $entry_count > self::MAX_WEBHOOK_ENTRIES,
			),
			'webhook_id' => wp_generate_uuid4(),
			'timestamp'  => current_time( 'c' ),
			'source'     => 'woocommerce_stock_monitor',
		);

		// Sign the EXACT bytes that are sent so the Go receiver's HMAC matches.
		$body = wp_json_encode( $payload );

		// Generate signature
		$webhook_secret = get_option( 'woodash_webhook_secret' );
		$headers        = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'StoreDash-Plugin/' . STOREDASH_VERSION,
		);

		if ( $webhook_secret ) {
			$signature                      = hash_hmac( 'sha256', $body, $webhook_secret );
			$headers['X-WooDash-Signature'] = $signature;
		}

		// Send webhook (blocking so connect/DNS/TLS failures are observable — a restock
		// notification is too important to fire-and-forget). 10s ceiling keeps the
		// stock-status save responsive.
		$response = wp_remote_post(
			$webhook_url,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 10,
				'blocking'  => true,
				'sslverify' => true,
			)
		);

		$http_status   = 0;
		$error_message = '';
		$is_failure    = false;

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$is_failure    = true;
		} else {
			$http_status = (int) wp_remote_retrieve_response_code( $response );
			if ( $http_status >= 400 ) {
				$is_failure    = true;
				$error_message = 'HTTP ' . $http_status;
			}
		}

		if ( $is_failure ) {
			\StoreDash_Helpers::log_message(
				'Waitlist: stock-available webhook delivery FAILED for product '
				. $product_id . ' (variation ' . $variation_id . ') - ' . $error_message
			);
			return;
		}

		// Delivery succeeded — mark the notified entries so they are not re-sent on
		// the next restock. Without this, every out-of-stock -> in-stock toggle
		// re-notifies the same shoppers, the pending list grows unbounded, the
		// notified stats stay at 0, and a notified shopper can never re-join.
		$this->mark_entries_notified( $entries );
	}

	/**
	 * Mark waitlist entries as notified after a successful stock-available send.
	 *
	 * @since 1.3.0
	 *
	 * @param array $entries Entry rows (ARRAY_A) that were just notified.
	 */
	private function mark_entries_notified( $entries ) {
		if ( empty( $entries ) ) {
			return;
		}

		$ids    = array();
		$emails = array();
		foreach ( $entries as $entry ) {
			if ( isset( $entry['id'] ) ) {
				$ids[] = (int) $entry['id'];
			}
			if ( isset( $entry['customer_email'] ) && '' !== $entry['customer_email'] ) {
				$emails[] = (string) $entry['customer_email'];
			}
		}

		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_product_waitlist';

		// The unique index is (store_id, product_id, variation_id, customer_email,
		// status). A shopper who was notified in a prior restock, then re-joined
		// (a fresh 'pending' row), would collide when we promote that row to
		// 'notified' because their earlier 'notified' row still exists — aborting
		// the whole batch UPDATE and re-notifying everyone forever. Remove the
		// superseded 'notified' rows for these shoppers (same product/variation)
		// first so the promotion is collision-free. All entries in a batch share the
		// restocked product/variation.
		$product_id   = (int) $entries[0]['product_id'];
		$variation_id = isset( $entries[0]['variation_id'] ) ? (int) $entries[0]['variation_id'] : 0;

		if ( ! empty( $emails ) ) {
			$email_ph = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
			$id_ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is $wpdb->prefix + constant; values bound via prepared placeholders; custom table has no core API.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $table_name
					WHERE product_id = %d AND variation_id = %d AND status = 'notified'
					AND customer_email IN ( $email_ph )
					AND id NOT IN ( $id_ph )",
					array_merge( array( $product_id, $variation_id ), $emails, $ids )
				)
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is $wpdb->prefix + constant; IDs bound via prepared %d placeholders; custom table has no core API.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table_name SET status = 'notified', notified_at = %s WHERE id IN ( $placeholders )",
				array_merge( array( current_time( 'mysql' ) ), $ids )
			)
		);
	}
}

// Initialize
new Stock_Monitor();
