<?php

namespace StoreDash\Carts\Services;

use StoreDash\Carts\Services\Cart_Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main cart tracking class
 */
class Cart_Tracking {

	/**
	 * Instance
	 *
	 * @var Cart_Tracking
	 */
	private static $instance;

	/**
	 * Track if sync has been dispatched
	 *
	 * @var bool
	 */
	private static $dispatched_sync = false;

	/**
	 * Track if sync is disabled temporarily
	 *
	 * @var bool
	 */
	private static $no_sync = false;

	/**
	 * Track order IDs already processed by mark_cart_as_converted
	 * to prevent triple execution (hooked to payment_complete, status_processing, status_completed).
	 *
	 * @var array
	 */
	private static $converted_order_ids = array();

	/**
	 * Cart tracking enabled option
	 */
	const ENABLED_OPTION = 'woodash_cart_tracking_enabled';

	/**
	 * Cached webhook URL (avoids repeated get_option calls in loops).
	 *
	 * @var string|null
	 */
	private $cached_webhook_url = null;

	/**
	 * Cached webhook secret (avoids repeated get_option calls in loops).
	 *
	 * @var string|null
	 */
	private $cached_webhook_secret = null;

	/**
	 * Get instance
	 *
	 * @return Cart_Tracking
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialize sync actions
		$this->initiate_sync_actions();

		// Customer login - link existing cart
		add_action( 'wp_login', array( $this, 'link_customer_existing_cart' ), 10, 2 );

		// Unset cart when order completed - use priority 20 to run after conversion tracking
		add_action( 'woocommerce_payment_complete', array( $this, 'unset_cart_token' ), 20 );
		add_action( 'woocommerce_thankyou', array( $this, 'unset_cart_token' ), 20 );

		// Checkout order processed - use priority 10 to run before cart token is unset
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'checkout_order_processed' ), 10 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'checkout_order_processed' ), 10 );

		// Also track when order is completed
		add_action( 'woocommerce_payment_complete', array( $this, 'mark_cart_as_converted' ), 10 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'mark_cart_as_converted' ), 10 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'mark_cart_as_converted' ), 10 );

		// AJAX endpoints for customer data capture
		add_action( 'wc_ajax_woodash_capture_customer_data', array( $this, 'capture_customer_data' ) );
		add_action( 'wc_ajax_woodash_email_opt_out', array( $this, 'opt_out' ) );
		add_action( 'wc_ajax_woodash_email_opt_in', array( $this, 'opt_in' ) );
		add_action( 'wc_ajax_woodash_update_marketing_optin', array( $this, 'update_marketing_optin' ) );

		// Enqueue frontend scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Add checkout opt-in checkbox
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'display_checkout_optin' ), 10 );
	}

	/**
	 * Register cron hooks unconditionally.
	 * WP-Cron needs cron_schedules and the action handler on every request,
	 * so these are registered without instantiating the full Cart_Tracking singleton.
	 */
	public static function register_cron_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_cron_schedules_static' ) );
		add_action( 'woodash_check_abandoned_carts', array( self::class, 'handle_abandoned_carts_cron' ) );
		add_action( 'woodash_purge_expired_carts', array( self::class, 'handle_purge_expired_carts_cron' ) );

		// Trailing flush for throttled cart.updated webhooks. Registered here
		// (not just in the constructor) so it fires in pure WP-Cron contexts
		// where the full tracker is not instantiated.
		add_action( 'storedash_flush_cart_webhook', array( self::class, 'handle_flush_cart_webhook' ), 10, 1 );

		// Self-heal the daily retention purge schedule. Runs on every request
		// (guarded by wp_next_scheduled), so it also backfills existing installs
		// whose activation predates this cron. Uses the WP built-in 'daily'
		// interval. Unscheduled on deactivation/uninstall alongside the other
		// woodash_* cron jobs.
		if ( ! wp_next_scheduled( 'woodash_purge_expired_carts' ) ) {
			wp_schedule_event( time(), 'daily', 'woodash_purge_expired_carts' );
		}
	}

	/**
	 * Static cron_schedules callback for use without full instantiation.
	 */
	public static function add_cron_schedules_static( $schedules ) {
		$schedules['woodash_ten_minutes'] = array(
			'interval' => 600,
			'display'  => __( 'Every 10 minutes', 'storedash' ),
		);
		return $schedules;
	}

	/**
	 * Static cron action handler — delegates to the singleton instance.
	 */
	public static function handle_abandoned_carts_cron(): void {
		self::instance()->check_and_mark_abandoned_carts();
	}

	/**
	 * Static cron action handler for the daily retention purge.
	 */
	public static function handle_purge_expired_carts_cron(): void {
		self::instance()->purge_expired_carts();
	}

	/**
	 * Static cron action handler for the trailing cart-webhook flush.
	 *
	 * @param string $cart_token Cart token passed through by wp_schedule_single_event().
	 */
	public static function handle_flush_cart_webhook( $cart_token ): void {
		self::instance()->flush_cart_webhook( $cart_token );
	}

	/**
	 * Initialize sync actions - CHECKOUT ONLY
	 * Only tracks carts when users reach checkout (abandoned checkout, not abandoned cart)
	 */
	protected function initiate_sync_actions() {
		if ( ! self::cart_tracking_enabled() ) {
			return;
		}

		// Checkout-only hooks for performance - only track purchase intent, not browsing
		$checkout_actions = apply_filters(
			'woodash_initiate_cart_sync_events',
			array(
				// Classic Checkout hooks
				'woocommerce_checkout_update_order_review', // AJAX updates during checkout

				// Block Checkout hooks
				'woocommerce_store_api_checkout_update_customer_from_request', // Block checkout customer updates

				// Session management
				'woocommerce_guest_session_to_user_id', // When guest becomes logged-in user
			)
		);

		foreach ( $checkout_actions as $action ) {
			add_action( $action, array( $this, 'initiate_sync' ) );
		}

		// Detect when user visits checkout page (template_redirect runs early)
		add_action( 'template_redirect', array( $this, 'maybe_sync_on_checkout_page' ), 10 );
	}

	/**
	 * Check if cart tracking is enabled.
	 *
	 * Capture is hard-gated on the Storedash connection: a never-connected
	 * store must not collect shopper carts it can never use, and the only
	 * settings writer (storedash/v1/carts/settings) is unreachable until
	 * Storedash provisions the store. Once connected, the dashboard-managed
	 * option decides (default on). This is the single source of truth for
	 * tracking state — read it instead of the raw option.
	 *
	 * @return bool
	 */
	public static function cart_tracking_enabled() {
		$enabled = \StoreDash_Helpers::is_store_connected() && get_option( self::ENABLED_OPTION, true );
		return (bool) apply_filters( 'woodash_cart_tracking_enabled', $enabled );
	}

	/**
	 * Maybe sync cart when user visits checkout page
	 * Only syncs on the checkout page to track abandoned checkout, not abandoned cart
	 */
	public function maybe_sync_on_checkout_page() {
		// Check if we're on checkout page (both Classic and Block)
		if ( ! $this->is_checkout_page() ) {
			return;
		}

		// Check if WC is loaded
		if ( ! function_exists( 'WC' ) || empty( WC()->cart ) ) {
			return;
		}

		// Don't sync empty carts
		if ( WC()->cart->is_empty() ) {
			return;
		}

		// Initiate sync for checkout page visit
		$this->initiate_sync();
	}

	/**
	 * Check if current page is checkout
	 * Supports both Classic and Block checkout
	 *
	 * @return bool
	 */
	protected function is_checkout_page() {
		// Fast path: WooCommerce's built-in check (uses page ID comparison)
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		// Check constant (set during checkout processing)
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT === true ) {
			return true;
		}

		// Block checkout: compare current page ID against WC checkout page
		// This avoids parsing post_content via has_block() on every page
		if ( function_exists( 'wc_get_page_id' ) ) {
			$checkout_page_id = wc_get_page_id( 'checkout' );
			if ( $checkout_page_id > 0 && is_page( $checkout_page_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Initiate cart sync
	 */
	public function initiate_sync() {
		// Stop if already dispatched
		if ( self::$dispatched_sync ) {
			return;
		}

		// Stop if sync disabled
		if ( self::$no_sync ) {
			return;
		}

		// Stop if cart tracking disabled
		if ( ! self::cart_tracking_enabled() ) {
			return;
		}

		// Sync on shutdown for performance
		add_action( 'shutdown', array( $this, 'sync_cart' ) );
		self::$dispatched_sync = true;
	}

	/**
	 * Sync cart data
	 */
	public function sync_cart() {
		// Stop if cart tracking disabled
		if ( ! self::cart_tracking_enabled() ) {
			return;
		}

		// Stop if sync disabled
		if ( self::$no_sync ) {
			return;
		}

		// Check if WC is loaded
		if ( ! function_exists( 'WC' ) || empty( WC()->session ) ) {
			return;
		}

		$cart_data = new Cart_Data();

		// Allow tracking of all carts, including guest carts without email
		// Commented out to enable immediate guest cart tracking
		// if (empty($cart_data->customer_id) &&
		// empty($cart_data->email) &&
		// !$cart_data::cart_is_pending_recovery()) {
		// return;
		// }

		// Throttle window (seconds). 0 disables throttling entirely — every
		// changed cart fires a webhook immediately, matching pre-1.3.1 behavior.
		$window = (int) apply_filters(
			'storedash_cart_sync_window',
			get_option( 'storedash_cart_sync_window', 120 )
		);

		// A throttled send that has not yet been delivered leaves the session
		// "dirty". When dirty we must fall through even if the cart hash is
		// unchanged, so the pending state is still delivered once the window
		// elapses (covers WP-Cron being unreliable).
		$is_dirty = (bool) WC()->session->get( 'storedash_cart_sync_dirty' );

		// Don't send if cart hasn't changed (unless a throttled send is pending).
		// A returning shopper whose basket is unchanged must still fall through
		// when the local row was marked 'abandoned'/'pending' by the cron — the
		// reset back to 'none' lives inside save_cart_data(), so early-returning
		// here would leave the row abandoned forever and Storedash would keep
		// sending recovery emails. The extra row lookup only happens on this
		// hash-match path, never when the hash differs.
		if ( ! $is_dirty
			&& ! empty( $cart_data->get_last_hash() )
			&& $cart_data->get_last_hash() === $cart_data->get_hash()
			&& ! $this->cart_needs_reactivation( $cart_data->get_cart_token() ) ) {
			return;
		}

		$cart_array = $cart_data->to_array();

		// Ensure cart_contents is properly formatted for the webhook
		if ( ! empty( $cart_array['cart'] ) ) {
			$cart_array['cart_contents'] = $cart_array['cart'];
		}

		// Stable dedupe fingerprint the Go receiver keys on. get_hash() excludes
		// the volatile created_at, so it only changes when the cart's contents,
		// customer identity or totals actually change. Set before save_cart_data()
		// and the webhook send so the local row and every payload carry the same.
		$cart_array['cart_hash'] = $cart_data->get_hash();

		// The local woodash_carts row must ALWAYS stay fresh — the trailing
		// flush and the abandoned-cart cron read the cart from this row.
		$this->save_cart_data( $cart_array );

		do_action( 'woodash_synced_cart', $cart_array );

		$cart_token = $cart_array['cart_token'];
		$last_send  = (int) WC()->session->get( 'storedash_last_cart_sync_at' );

		// Leading edge: send immediately when throttling is off, when nothing has
		// been sent yet, or when the window has fully elapsed since the last send.
		if ( $window <= 0 || 0 === $last_send || ( time() - $last_send ) >= $window ) {
			$this->trigger_cart_webhook( 'updated', $cart_array );

			// Persist the SENT hash only on an actual send (never on a throttled
			// skip) so a dirty state is never early-returned before delivery.
			$cart_data->save_last_hash();

			WC()->session->set( 'storedash_last_cart_sync_at', time() );
			WC()->session->set( 'storedash_cart_sync_dirty', false );
		} else {
			// Inside the window: throttle. Mark dirty and schedule ONE trailing
			// flush that delivers the final cart state when the window closes.
			// wp_next_scheduled() with the same args array dedupes naturally.
			WC()->session->set( 'storedash_cart_sync_dirty', true );

			if ( ! wp_next_scheduled( 'storedash_flush_cart_webhook', array( $cart_token ) ) ) {
				wp_schedule_single_event( $last_send + $window, 'storedash_flush_cart_webhook', array( $cart_token ) );
			}
		}

		if ( $cart_data->cart_is_empty() ) {
			$this->unset_cart_token();
		}
	}

	/**
	 * Whether the local cart row needs un-abandoning (recovery_status is
	 * 'abandoned' or 'pending'). Reads the DB directly — the woodash_carts
	 * object-cache entry can be stale here because the abandon cron flips
	 * recovery_status via direct SQL without invalidating it. When a reset is
	 * needed, the cache entry is deleted so save_cart_data() sees the real
	 * status and runs its reset branch.
	 *
	 * @param string $cart_token Cart token.
	 * @return bool
	 */
	protected function cart_needs_reactivation( $cart_token ) {
		if ( empty( $cart_token ) ) {
			return false;
		}

		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT recovery_status FROM $table_name WHERE cart_token = %s",
				$cart_token
			)
		);

		if ( in_array( $status, array( 'abandoned', 'pending' ), true ) ) {
			wp_cache_delete( 'woodash_cart_' . $cart_token, 'woodash_carts' );
			return true;
		}

		return false;
	}

	/**
	 * Trailing flush for a throttled cart.updated webhook.
	 *
	 * Runs on WP-Cron with NO WC session, so the cart is rebuilt from the local
	 * woodash_carts row (same field mapping as run_abandoned_cart_check()). The
	 * row's 'timestamp' is stamped fresh at send time inside trigger_cart_webhook(),
	 * so the trailing send correctly carries a later timestamp than the leading one.
	 *
	 * @param string $cart_token Cart token to flush.
	 */
	public function flush_cart_webhook( $cart_token ) {
		if ( empty( $cart_token ) ) {
			return;
		}

		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$cart = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cart_token, customer_id, email, name, phone,
						total, subtotal, total_tax, total_discount,
						total_shipping, total_fee, currency, locale,
						email_opt_out, marketing_optin, cart_contents,
						client_session, cart_hash, recovery_status, converted_order_id
				FROM $table_name WHERE cart_token = %s",
				$cart_token
			)
		);

		// No row, or a status webhook (abandoned/converted/recovered) already
		// covered this cart — the trailing 'updated' send would be redundant.
		if ( ! $cart ) {
			return;
		}
		if ( ! empty( $cart->converted_order_id ) || 'none' !== $cart->recovery_status ) {
			return;
		}

		$cart_contents = json_decode( $cart->cart_contents, true );

		$cart_data = array(
			'cart_token'      => $cart->cart_token,
			'customer_id'     => $cart->customer_id ? (int) $cart->customer_id : null,
			'email'           => $cart->email,
			'name'            => $cart->name,
			'phone'           => $cart->phone,
			'total'           => $cart->total,
			'subtotal'        => $cart->subtotal,
			'total_tax'       => $cart->total_tax,
			'total_discount'  => $cart->total_discount,
			'total_shipping'  => $cart->total_shipping,
			'total_fee'       => $cart->total_fee,
			'currency'        => $cart->currency,
			'locale'          => $cart->locale,
			'email_opt_out'   => (bool) $cart->email_opt_out,
			'marketing_optin' => (int) $cart->marketing_optin,
			'cart'            => $cart_contents,
			'client_session'  => json_decode( $cart->client_session, true ),
			'cart_contents'   => $cart_contents,
			'cart_hash'       => $cart->cart_hash,
		);

		$this->trigger_cart_webhook( 'updated', $cart_data );
	}

	/**
	 * Save cart data to database
	 *
	 * @param array $cart_data
	 */
	protected function save_cart_data( $cart_data ) {
		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		// Only use cache for read-heavy operations, not during checkout
		$cache_key = 'woodash_cart_' . $cart_data['cart_token'];
		$existing  = false;

		// Skip cache during checkout or if cart has items (critical operations)
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
		$is_checkout = ( isset( $_POST['woocommerce_checkout_place_order'] ) ||
						is_checkout() ||
						( isset( $cart_data['items'] ) && ! empty( $cart_data['items'] ) ) );

		if ( ! $is_checkout ) {
			$existing = wp_cache_get( $cache_key, 'woodash_carts' );
		}

		if ( false === $existing ) {
			// Cache miss or checkout - query database
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, recovery_status, converted_order_id FROM $table_name WHERE cart_token = %s",
					$cart_data['cart_token']
				)
			);

			// Only cache if not during checkout and cache backend is available
			if ( $existing && ! $is_checkout && function_exists( 'wp_cache_get' ) ) {
				wp_cache_set( $cache_key, $existing, 'woodash_carts', 180 ); // Reduced to 3 minutes
			}
		}

		// Don't update if cart is already converted or recovered
		if ( $existing && in_array( $existing->recovery_status, array( 'converted', 'recovered' ), true ) ) {
			return;
		}

		// Cart_Data::to_array() returns a minimal ['cart_token', 'cart' => false]
		// shape for empty/missing carts, so every other key must be defaulted.
		$data = array(
			'cart_token'      => $cart_data['cart_token'],
			'customer_id'     => ( $cart_data['customer_id'] ?? null ) ?: null,
			'email'           => ( $cart_data['email'] ?? null ) ?: null,
			'name'            => ( $cart_data['name'] ?? null ) ?: 'Guest',
			'phone'           => ( $cart_data['phone'] ?? null ) ?: null,
			'cart_contents'   => wp_json_encode( $cart_data['cart'] ?? array() ),
			'cart_hash'       => $cart_data['cart_hash'] ?? md5( wp_json_encode( $cart_data ) ),
			'total'           => $cart_data['total'] ?? 0,
			'subtotal'        => $cart_data['subtotal'] ?? 0,
			'total_tax'       => $cart_data['total_tax'] ?? 0,
			'total_discount'  => $cart_data['total_discount'] ?? 0,
			'total_shipping'  => $cart_data['total_shipping'] ?? 0,
			'total_fee'       => $cart_data['total_fee'] ?? 0,
			'currency'        => $cart_data['currency'] ?? get_woocommerce_currency(),
			'locale'          => $cart_data['locale'] ?? determine_locale(),
			'email_opt_out'   => $cart_data['email_opt_out'] ?? 0,
			'marketing_optin' => $cart_data['marketing_optin'] ?? 0,
			'client_session'  => wp_json_encode( $cart_data['client_session'] ?? array() ),
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( $existing ) {
			// Reset abandoned_at if cart is active again. 'pending' (set when a
			// shopper clicks a recovery link) must reset too — otherwise the row
			// can never re-enter the abandon pipeline after a recovery click.
			if ( in_array( $existing->recovery_status, array( 'abandoned', 'pending' ), true ) ) {
				$data['abandoned_at']    = null;
				$data['recovery_status'] = 'none';
			}
			$wpdb->update( $table_name, $data, array( 'id' => $existing->id ) );
		} else {
			$data['created_at']      = current_time( 'mysql' );
			$data['recovery_status'] = 'none';
			$wpdb->insert( $table_name, $data );
		}

		// Invalidate cache after update/insert
		$cache_key = 'woodash_cart_' . $cart_data['cart_token'];
		wp_cache_delete( $cache_key, 'woodash_carts' );
	}

	/**
	 * Link customer existing cart on login
	 *
	 * @param string   $user_login
	 * @param \WP_User $user
	 */
	public function link_customer_existing_cart( $user_login, $user ) {
		if ( empty( $user ) ) {
			return;
		}

		if ( ! self::cart_tracking_enabled() ) {
			return;
		}

		$session_token = ( WC()->session ) ? WC()->session->get( 'woodash_cart_token' ) : '';

		if ( empty( $session_token ) ) {
			return;
		}

		$existing_token = get_user_meta( $user->ID, '_woodash_cart_token', true );

		if ( ! empty( $existing_token ) && $existing_token !== $session_token ) {
			$this->delete_cart( $session_token );

			if ( WC()->session ) {
				WC()->session->set( 'woodash_cart_token', $existing_token );
			}
		}
	}

	/**
	 * Delete cart
	 *
	 * @param string $cart_token
	 */
	protected function delete_cart( $cart_token ) {
		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		$wpdb->delete( $table_name, array( 'cart_token' => $cart_token ) );
	}

	/**
	 * Process checkout order
	 *
	 * @param int|\WC_Order $order
	 */
	public function checkout_order_processed( $order ) {
		if ( ! self::cart_tracking_enabled() ) {
			return;
		}

		$order = wc_get_order( $order );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Look up existing cart token WITHOUT generating a new one.
		// Cart_Data::get_cart_token() would create a fresh token if none exists,
		// which breaks conversion tracking for the original cart.
		$cart_token = null;
		$user_id    = get_current_user_id();

		if ( ! empty( $user_id ) ) {
			$cart_token = get_user_meta( $user_id, '_woodash_cart_token', true );
		}

		if ( empty( $cart_token ) && WC()->session ) {
			$cart_token = WC()->session->get( 'woodash_cart_token' );
		}

		// Fallback: look up by billing email in the local carts table
		if ( empty( $cart_token ) ) {
			$email = $order->get_billing_email();
			if ( $email ) {
				global $wpdb;
				$table_name = storedash_get_cart_table_name();
				$now        = current_time( 'mysql' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe
				$found = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT cart_token FROM $table_name
						WHERE email = %s
						AND recovery_status NOT IN ('converted', 'recovered')
						AND created_at > DATE_SUB(%s, INTERVAL 7 DAY)
						ORDER BY created_at DESC
						LIMIT 1",
						$email,
						$now
					)
				);
				if ( $found ) {
					$cart_token = $found;
					\StoreDash_Helpers::debug_log(
						'checkout_order_processed: found cart by email fallback',
						array(
							'cart_token' => $cart_token,
							'order_id'   => $order->get_id(),
							'email'      => $email,
						)
					);
				}
			}
		}

		if ( empty( $cart_token ) ) {
			\StoreDash_Helpers::debug_log(
				'checkout_order_processed: no cart token found',
				array( 'order_id' => $order->get_id() )
			);
			return;
		}

		$order->update_meta_data( '_woodash_cart_token', $cart_token );

		// Save marketing opt-in preference from checkout form
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
		$marketing_optin = isset( $_POST['woodash_marketing_optin'] ) ? 1 : 0;
		$order->update_meta_data( '_woodash_marketing_optin', $marketing_optin );

		$order->save();

		// Update cart as converted
		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		$wpdb->update(
			$table_name,
			array(
				'converted_order_id' => $order->get_id(),
				'recovery_status'    => 'converted',
				'recovered_at'       => current_time( 'mysql' ),
			),
			array( 'cart_token' => $cart_token )
		);

		// Check if pending recovery
		if ( Cart_Data::cart_is_pending_recovery() ) {
			$this->mark_order_as_recovered( $order );
		}
	}

	/**
	 * Mark cart as converted when order is paid/completed
	 *
	 * @param int $order_id
	 */
	public function mark_cart_as_converted( $order_id ) {
		if ( ! self::cart_tracking_enabled() ) {
			return;
		}

		// Prevent triple execution within the same request
		// (hooked to payment_complete, status_processing, status_completed)
		$order_id_int = (int) $order_id;
		if ( in_array( $order_id_int, self::$converted_order_ids, true ) ) {
			return;
		}
		self::$converted_order_ids[] = $order_id_int;

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Get cart token from order meta
		$cart_token = $order->get_meta( '_woodash_cart_token' );
		if ( ! $cart_token ) {
			// Try to find cart by email as fallback
			$email = $order->get_billing_email();
			if ( $email ) {
				global $wpdb;
				$table_name = storedash_get_cart_table_name();
				$now        = current_time( 'mysql' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
				$cart = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT cart_token FROM $table_name
                    WHERE email = %s
                    AND recovery_status NOT IN ('converted', 'recovered')
                    AND created_at > DATE_SUB(%s, INTERVAL 7 DAY)
                    ORDER BY created_at DESC
                    LIMIT 1",
						$email,
						$now
					)
				);

				if ( $cart ) {
					$cart_token = $cart->cart_token;
					// Save cart token to order for future reference
					$order->update_meta_data( '_woodash_cart_token', $cart_token );
					$order->save();
					\StoreDash_Helpers::debug_log(
						'Found cart by email for order',
						array(
							'cart_token' => $cart_token,
							'order_id'   => $order_id,
							'email'      => $email,
						)
					);
				}
			}

			if ( ! $cart_token ) {
				\StoreDash_Helpers::debug_log( 'No cart token found for order', array( 'order_id' => $order_id ) );
				return;
			}
		}

		// Get cart data from database
		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$cart = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE cart_token = %s",
				$cart_token
			),
			ARRAY_A
		);

		if ( ! $cart ) {
			return;
		}

		// Only trigger the webhook if it has not already been delivered.
		// checkout_order_processed() marks a standard-checkout cart 'converted'
		// before this runs but never sends the webhook, so gating on
		// recovery_status here would suppress in-request delivery entirely. Gate
		// on converted_webhook_sent_at instead: it is set below on confirmed
		// success (and by the cron), so this fires in-request for standard
		// checkouts and the cron backstop skips rows already sent — no double-send.
		if ( empty( $cart['converted_webhook_sent_at'] ) ) {
			// Update cart status
			$wpdb->update(
				$table_name,
				array(
					'converted_order_id' => $order_id,
					'recovery_status'    => 'converted',
					'recovered_at'       => current_time( 'mysql' ),
				),
				array( 'cart_token' => $cart_token )
			);

			// Prepare cart data for webhook
			$cart_data = array(
				'cart_token'      => $cart['cart_token'],
				'customer_id'     => $cart['customer_id'] ? (int) $cart['customer_id'] : null,
				'email'           => $cart['email'],
				'name'            => $cart['name'],
				'phone'           => $cart['phone'],
				'total'           => $cart['total'],
				'subtotal'        => $cart['subtotal'],
				'total_tax'       => $cart['total_tax'],
				'total_discount'  => $cart['total_discount'],
				'total_shipping'  => $cart['total_shipping'],
				'total_fee'       => $cart['total_fee'],
				'currency'        => $cart['currency'],
				'locale'          => $cart['locale'],
				'email_opt_out'   => (bool) $cart['email_opt_out'],
				'marketing_optin' => (int) $cart['marketing_optin'],
				'cart'            => json_decode( $cart['cart_contents'], true ),
				'client_session'  => json_decode( $cart['client_session'], true ),
				'order_id'        => $order_id,
			);

			// Check if it's a recovered cart
			$is_recovered = $order->get_meta( '_woodash_cart_recovered' );

			// Trigger appropriate webhook and only mark sent on confirmed success
			$webhook_sent = false;
			if ( $is_recovered ) {
				$cart_data['recovery_status'] = 'recovered';
				$webhook_sent                 = $this->trigger_cart_webhook( 'recovered', $cart_data );
			} else {
				$webhook_sent = $this->trigger_cart_webhook( 'converted', $cart_data );
			}

			if ( $webhook_sent ) {
				$wpdb->update(
					$table_name,
					array( 'converted_webhook_sent_at' => current_time( 'mysql' ) ),
					array( 'cart_token' => $cart_token )
				);
			}
		}
	}

	/**
	 * Mark order as recovered
	 *
	 * @param int|\WC_Order $order
	 */
	protected function mark_order_as_recovered( $order ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order->update_meta_data( '_woodash_cart_recovered', true );
		$order->add_order_note( __( 'Order cart recovered by Storedash.', 'storedash' ) );
		$order->save();
	}

	/**
	 * Unset cart token
	 */
	public function unset_cart_token() {
		if ( WC()->session ) {
			unset( WC()->session->woodash_cart_token, WC()->session->woodash_pending_recovery );
		}

		if ( $user_id = get_current_user_id() ) {
			delete_user_meta( $user_id, '_woodash_cart_token' );
			delete_user_meta( $user_id, '_woodash_pending_recovery' );
		}
	}

	/**
	 * Capture customer data via AJAX
	 */
	public function capture_customer_data() {
		check_ajax_referer( 'storedash', 'security' );

		$data_changed = false;

		if ( ! empty( $_POST['email'] ) && is_email( wp_unslash( $_POST['email'] ) ) ) {
			$new_email     = sanitize_email( wp_unslash( $_POST['email'] ) );
			$current_email = WC()->customer->get_email();

			if ( $new_email !== $current_email ) {
				WC()->customer->set_email( $new_email );
				WC()->customer->set_billing_email( $new_email );
				$data_changed = true;
			}
		}

		// Customer first name
		if ( isset( $_POST['first_name'] ) ) {
			WC()->customer->set_first_name( sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) );
			WC()->customer->set_billing_first_name( sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) );
			$data_changed = true;
		}

		// Customer last name
		if ( isset( $_POST['last_name'] ) ) {
			WC()->customer->set_last_name( sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) );
			WC()->customer->set_billing_last_name( sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) );
			$data_changed = true;
		}

		// Customer phone
		if ( isset( $_POST['phone'] ) ) {
			WC()->customer->set_billing_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) );
			$data_changed = true;
		}

		WC()->customer->save();

		// Force sync if data changed
		if ( $data_changed && WC()->session ) {
			WC()->session->set( Cart_Data::LAST_CART_HASH, '' );
		}

		$this->initiate_sync();

		wp_send_json_success();
	}

	/**
	 * Process opt out
	 */
	public function opt_out() {
		check_ajax_referer( 'storedash', 'security' );

		Cart_Data::set_customer_email_opt_out();
		$this->initiate_sync();

		wp_send_json_success();
	}

	/**
	 * Process opt in
	 */
	public function opt_in() {
		check_ajax_referer( 'storedash', 'security' );

		Cart_Data::set_customer_email_opt_out( false );
		$this->initiate_sync();

		wp_send_json_success();
	}

	/**
	 * Update marketing opt-in status
	 */
	public function update_marketing_optin() {
		check_ajax_referer( 'storedash', 'security' );

		$optin = isset( $_POST['optin'] ) ? (int) $_POST['optin'] : 0;
		Cart_Data::set_marketing_optin( $optin );

		// Force a cart sync by clearing the last hash
		if ( WC()->session ) {
			WC()->session->set( Cart_Data::LAST_CART_HASH, '' );
		}

		// Reset the sync flag to allow immediate sync
		self::$dispatched_sync = false;

		// Sync cart immediately for marketing opt-in changes
		$this->sync_cart();

		wp_send_json_success( array( 'optin' => $optin ) );
	}

	/**
	 * Display checkout opt-in checkbox
	 */
	public function display_checkout_optin() {
		// Check if feature is enabled
		if ( ! get_option( 'woodash_checkout_optin_enabled', false ) ) {
			return;
		}

		// Get current opt-in status
		$optin = Cart_Data::get_marketing_optin();

		?>
		<p class="form-row storedash-marketing-optin">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" 
						name="woodash_marketing_optin" id="woodash_marketing_optin" value="1" 
						<?php checked( $optin, 1 ); ?> />
				<span class="woocommerce-form__label-text">
					<?php echo esc_html( \StoreDash_Helpers::checkout_optin_label() ); ?>
				</span>
			</label>
		</p>
		<?php
	}

	/**
	 * Enqueue frontend scripts
	 * Only loads on checkout page for performance
	 */
	public function enqueue_scripts() {
		if ( ! self::cart_tracking_enabled() ) {
			return;
		}

		// PERFORMANCE: Only load cart tracking script on checkout page
		if ( ! $this->is_checkout_page() ) {
			return;
		}

		wp_enqueue_script(
			'woodash-cart-tracking',
			WOODASH_HELPER_URL . 'assets/js/cart-tracking.js',
			array( 'jquery' ),
			WOODASH_HELPER_VERSION,
			true
		);

		wp_localize_script(
			'woodash-cart-tracking',
			'woodash_params',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'wc_ajax_url'   => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'nonce'         => wp_create_nonce( 'storedash' ),
				'cart_tracking' => array(
					'enabled'                            => true,
					'wc_ajax_capture_customer_data_url'  => \WC_AJAX::get_endpoint( 'woodash_capture_customer_data' ),
					'wc_ajax_email_opt_out_url'          => \WC_AJAX::get_endpoint( 'woodash_email_opt_out' ),
					'wc_ajax_email_opt_in_url'           => \WC_AJAX::get_endpoint( 'woodash_email_opt_in' ),
					'wc_ajax_update_marketing_optin_url' => \WC_AJAX::get_endpoint( 'woodash_update_marketing_optin' ),
				),
			)
		);
	}

	/**
	 * Run callback without cart tracking
	 *
	 * @param callable $callback
	 */
	public static function without_cart_tracking( callable $callback ) {
		self::$no_sync = true;
		$callback();
		self::$no_sync = false;
	}

	/**
	 * Resolve (and cache) the cart webhook URL.
	 *
	 * resolve_webhook_url() gates on a completed Storedash connection (so a
	 * never-connected install sends nothing) and honors "unset = default,
	 * explicitly-empty = disabled". Cached to avoid repeated get_option calls
	 * in loops. Returns an empty string when the webhook is disabled/unconnected.
	 *
	 * @return string
	 */
	private function get_cart_webhook_url() {
		if ( $this->cached_webhook_url === null ) {
			$this->cached_webhook_url    = \StoreDash_Helpers::resolve_webhook_url( 'woodash_cart_webhook_url', 'https://webhooks.storedash.io/yvrrywi5uld4jq' );
			$this->cached_webhook_secret = get_option( 'woodash_webhook_secret', '' );
		}

		return $this->cached_webhook_url;
	}

	/**
	 * Trigger cart webhook
	 *
	 * @param string $action
	 * @param array  $cart_data
	 * @param bool   $blocking When true, wait for and verify the HTTP response
	 *                         (used by the cron sweep, which needs a confirmed
	 *                         delivery before stamping the row). When false
	 *                         (default), dispatch fire-and-forget: delivery
	 *                         cannot be confirmed, so false is always returned.
	 * @return bool True only when a blocking send is confirmed delivered.
	 */
	protected function trigger_cart_webhook( $action, $cart_data, $blocking = false ) {
		$webhook_url    = $this->get_cart_webhook_url();
		$webhook_secret = $this->cached_webhook_secret;

		if ( ! $webhook_url ) {
			return false;
		}

		$payload = array(
			'action'    => 'cart.' . $action,
			'cart'      => $cart_data,
			'store_url' => get_site_url(),
			'store_id'  => get_option( 'woodash_store_id', '' ),
			'timestamp' => current_time( 'mysql', true ),
		);

		// Debug logging for critical actions
		if ( in_array( $action, array( 'converted', 'recovered' ), true ) ) {
			\StoreDash_Helpers::debug_log(
				'Sending webhook for cart',
				array(
					'action'     => $action,
					'cart_token' => $cart_data['cart_token'],
				)
			);
		}

		// Sign the EXACT bytes that are sent so the Go receiver's HMAC matches.
		$body = wp_json_encode( $payload );

		// Generate signature if secret is set
		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( $webhook_secret ) {
			$signature                      = hash_hmac( 'sha256', $body, $webhook_secret );
			$headers['X-WooDash-Signature'] = $signature;
		}

		$response = wp_remote_post(
			$webhook_url,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 10,
				'blocking'  => $blocking,
				'sslverify' => true,
			)
		);

		// A non-blocking dispatch returns before the receiver responds, so
		// delivery cannot be verified here. Report it as unconfirmed (false)
		// and let the cron sweep confirm and stamp the row later.
		if ( ! $blocking ) {
			return false;
		}

		// Handle webhook response and errors (blocking cron path only)
		$cart_token     = $cart_data['cart_token'] ?? 'unknown';
		$customer_email = $cart_data['email'] ?? 'unknown';
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$error_code    = $response->get_error_code();

			$log_message = sprintf(
				'Storedash: Webhook failed for cart %s (action: %s, customer: %s) - Error: %s [Code: %s] - CRITICAL ACTION FAILED',
				$cart_token,
				$action,
				$customer_email,
				$error_message,
				$error_code
			);

			\StoreDash_Helpers::log_message( $log_message, 'error' );

			do_action( 'woodash_webhook_failed', $action, $cart_data, $error_message );
			return false;
		}

		// Check HTTP status code
		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 ) {
			$response_body = wp_remote_retrieve_body( $response );

			$log_message = sprintf(
				'Storedash: Webhook returned error status %d for cart %s (action: %s, customer: %s) - Response: %s - CRITICAL ACTION FAILED',
				$status_code,
				$cart_token,
				$action,
				$customer_email,
				substr( $response_body, 0, 200 ) // Limit response body length
			);

			\StoreDash_Helpers::log_message( $log_message, 'error' );

			do_action( 'woodash_webhook_failed', $action, $cart_data, "HTTP {$status_code}: {$response_body}" );
			return false;
		}

		\StoreDash_Helpers::debug_log(
			'Webhook sent successfully',
			array(
				'cart_token' => $cart_token,
				'action'     => $action,
				'customer'   => $customer_email,
				'status'     => $status_code,
			)
		);

		return true;
	}

	/**
	 * Check and mark abandoned carts
	 */
	public function check_and_mark_abandoned_carts() {
		if ( ! self::cart_tracking_enabled() ) {
			\StoreDash_Helpers::debug_log( 'Cart tracking is disabled, skipping abandoned cart check' );
			return;
		}

		// Prevent concurrent cron execution
		if ( get_transient( 'storedash_abandoned_check_lock' ) ) {
			\StoreDash_Helpers::debug_log( 'Abandoned cart check already running, skipping' );
			return;
		}
		set_transient( 'storedash_abandoned_check_lock', true, 300 );

		try {
			$this->run_abandoned_cart_check();
			// Run the converted sweep under the same lock so overlapping cron
			// runs cannot both dispatch the same conversion webhooks.
			$this->check_and_send_converted_carts();
		} finally {
			delete_transient( 'storedash_abandoned_check_lock' );
		}
	}

	/**
	 * Internal abandoned cart check logic, separated for clean lock management.
	 */
	protected function run_abandoned_cart_check() {
		global $wpdb;
		$table_name   = storedash_get_cart_table_name();
		$abandon_time = get_option( 'woodash_cart_abandon_time', 60 );

		\StoreDash_Helpers::debug_log( 'Checking for abandoned carts', array( 'abandon_time_minutes' => $abandon_time ) );

		// Capture a precise batch timestamp to tag this run's rows
		$batch_timestamp = current_time( 'mysql' );

		// Atomic UPDATE — prevents TOCTOU race condition
		// Uses a fixed timestamp so the subsequent SELECT can match exactly
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table_name
				SET abandoned_at = %s,
					recovery_status = 'abandoned'
				WHERE abandoned_at IS NULL
				AND recovery_status = %s
				AND converted_order_id IS NULL
				AND updated_at < DATE_SUB(%s, INTERVAL %d MINUTE)
				AND total > 0
				LIMIT 100",
				$batch_timestamp,
				'none',
				$batch_timestamp,
				$abandon_time
			)
		);

		$updated_count = $wpdb->rows_affected;

		if ( 0 === $updated_count ) {
			\StoreDash_Helpers::debug_log( 'No carts found to mark as abandoned' );
			return;
		}

		\StoreDash_Helpers::debug_log( 'Marked carts as abandoned', array( 'count' => $updated_count ) );

		// SELECT only the rows tagged with this batch's exact timestamp
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$abandoned_carts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, cart_token, customer_id, email, name, phone,
						total, subtotal, total_tax, total_discount,
						total_shipping, total_fee, currency, locale,
						email_opt_out, marketing_optin, cart_contents,
						client_session, cart_hash, abandoned_at, updated_at
				FROM $table_name
				WHERE recovery_status = %s
				AND abandoned_at = %s
				ORDER BY id ASC
				LIMIT 100",
				'abandoned',
				$batch_timestamp
			)
		);

		foreach ( $abandoned_carts as $cart ) {
			// Re-check right before sending: a returning shopper's sync_cart()
			// may have reset the row to 'none' between the batch SELECT above
			// and this send — an 'abandoned' webhook then would be stale.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
			$current_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT recovery_status FROM $table_name WHERE id = %d",
					$cart->id
				)
			);
			if ( 'abandoned' !== $current_status ) {
				continue;
			}

			$cart_data = array(
				'cart_token'      => $cart->cart_token,
				'customer_id'     => $cart->customer_id ? (int) $cart->customer_id : null,
				'email'           => $cart->email,
				'name'            => $cart->name,
				'phone'           => $cart->phone,
				'total'           => $cart->total,
				'subtotal'        => $cart->subtotal,
				'total_tax'       => $cart->total_tax,
				'total_discount'  => $cart->total_discount,
				'total_shipping'  => $cart->total_shipping,
				'total_fee'       => $cart->total_fee,
				'currency'        => $cart->currency,
				'locale'          => $cart->locale,
				'email_opt_out'   => (bool) $cart->email_opt_out,
				'marketing_optin' => (int) $cart->marketing_optin,
				'cart'            => json_decode( $cart->cart_contents, true ),
				'client_session'  => json_decode( $cart->client_session, true ),
				'cart_hash'       => $cart->cart_hash,
				// CI-97: convert to GMT on the way out. $batch_timestamp (and so the
				// stored abandoned_at) is site-local current_time('mysql') because it
				// also anchors the abandonment window against the site-local
				// updated_at — that must NOT change. storedash-sync parses this JSON
				// field with a zoneless layout, i.e. as UTC, so shipping site-local
				// time shifted carts.abandoned_at by the site's offset on every
				// non-UTC store. get_gmt_from_date() keeps the same 'Y-m-d H:i:s'
				// shape, so the consumer's parser is unchanged.
				'abandoned_at'    => $cart->abandoned_at ? get_gmt_from_date( $cart->abandoned_at ) : null,
			);

			$this->trigger_cart_webhook( 'abandoned', $cart_data );

			\StoreDash_Helpers::debug_log(
				'Marked cart as abandoned',
				array(
					'cart_token' => $cart->cart_token,
					'customer'   => $cart->email ?: 'Guest',
					'currency'   => $cart->currency,
					'total'      => $cart->total,
				)
			);
		}
	}

	/**
	 * Check for converted carts and send webhooks if needed
	 */
	protected function check_and_send_converted_carts() {
		global $wpdb;

		// Nothing to deliver when the webhook is disabled or the store is not
		// connected. Short-circuit before querying so we don't reselect rows
		// every run only to send nothing (and never stamp them as sent).
		if ( ! $this->get_cart_webhook_url() ) {
			return;
		}

		$table_name = storedash_get_cart_table_name();

		// Find carts marked as converted but webhook not yet confirmed sent.
		// 48h window gives plenty of retries for transient failures.
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$converted_carts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name
            WHERE recovery_status = %s
            AND recovered_at > DATE_SUB(%s, INTERVAL 48 HOUR)
            AND converted_order_id IS NOT NULL
            AND converted_webhook_sent_at IS NULL
            LIMIT 50",
				'converted',
				$now
			)
		);

		if ( empty( $converted_carts ) ) {
			return;
		}

		foreach ( $converted_carts as $cart ) {
			// Check if order exists and is completed/processing
			$order = wc_get_order( $cart->converted_order_id );
			if ( ! $order || ! in_array( $order->get_status(), array( 'completed', 'processing' ), true ) ) {
				continue;
			}

			// Duplicate delivery is prevented by the converted_webhook_sent_at
			// guard in the SELECT above and the flag write below.

			// Prepare cart data for webhook
			$cart_data = array(
				'cart_token'      => $cart->cart_token,
				'customer_id'     => $cart->customer_id ? (int) $cart->customer_id : null,
				'email'           => $cart->email,
				'name'            => $cart->name,
				'phone'           => $cart->phone,
				'total'           => $cart->total,
				'subtotal'        => $cart->subtotal,
				'total_tax'       => $cart->total_tax,
				'total_discount'  => $cart->total_discount,
				'total_shipping'  => $cart->total_shipping,
				'total_fee'       => $cart->total_fee,
				'currency'        => $cart->currency,
				'locale'          => $cart->locale,
				'email_opt_out'   => (bool) $cart->email_opt_out,
				'marketing_optin' => (int) $cart->marketing_optin,
				'cart'            => json_decode( $cart->cart_contents, true ),
				'client_session'  => json_decode( $cart->client_session, true ),
				'order_id'        => $cart->converted_order_id,
			);

			// A recovered cart carries the _woodash_cart_recovered order meta;
			// emit 'recovered' vs 'converted' so recovery attribution is not lost
			// (mirrors mark_cart_as_converted()).
			if ( $order->get_meta( '_woodash_cart_recovered' ) ) {
				$cart_data['recovery_status'] = 'recovered';
				$webhook_sent                 = $this->trigger_cart_webhook( 'recovered', $cart_data, true );
			} else {
				$webhook_sent = $this->trigger_cart_webhook( 'converted', $cart_data, true );
			}

			if ( $webhook_sent ) {
				$wpdb->update(
					$table_name,
					array( 'converted_webhook_sent_at' => current_time( 'mysql' ) ),
					array( 'id' => $cart->id )
				);
			}
		}
	}

	/**
	 * Purge unconverted carts older than the configured retention window.
	 *
	 * Enforces the woodash_cart_retention_days setting (synced from the
	 * dashboard). Converted carts (converted_order_id IS NOT NULL) are kept as
	 * conversion records; only stale, never-converted carts are removed.
	 * Runs daily via the woodash_purge_expired_carts cron event.
	 */
	protected function purge_expired_carts() {
		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		// Same default and clamp as Carts_Settings_Controller.
		$retention_days = (int) get_option( 'woodash_cart_retention_days', 30 );
		$retention_days = max( 1, min( 365, $retention_days ) );

		$now           = current_time( 'mysql' );
		$total_deleted = 0;

		// Delete in bounded batches so a large backlog cannot lock the table.
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $table_name
					WHERE converted_order_id IS NULL
					AND updated_at < DATE_SUB(%s, INTERVAL %d DAY)
					LIMIT 500",
					$now,
					$retention_days
				)
			);

			if ( false === $deleted ) {
				break;
			}

			$total_deleted += $deleted;
		} while ( $deleted >= 500 );

		if ( $total_deleted > 0 ) {
			\StoreDash_Helpers::debug_log(
				'Purged expired carts',
				array(
					'count'          => $total_deleted,
					'retention_days' => $retention_days,
				)
			);
		}
	}

	/**
	 * Force check abandoned carts (for testing)
	 * Can be called via: do_action('woodash_force_check_abandoned_carts');
	 */
	public static function force_check_abandoned_carts() {
		$instance = self::instance();
		\StoreDash_Helpers::debug_log( 'Forcing abandoned cart check' );
		$instance->check_and_mark_abandoned_carts();
	}
}

// Add action for manual testing
add_action( 'woodash_force_check_abandoned_carts', array( Cart_Tracking::class, 'force_check_abandoned_carts' ) );