<?php
/**
 * Marketing Opt-in Handler
 *
 * Records single opt-in marketing consent from two customer-initiated sources
 * (checkout checkbox + Elementor signup widget) by posting a signed
 * `subscription.optin` webhook to the StoreDash sync service via Hookdeck.
 *
 * The webhook envelope, signing (hex HMAC-SHA256 in the `X-WooDash-Signature`
 * header) and store-secret access mirror the existing cart / waitlist / enquiry
 * senders. ADR-019 (single opt-in for v1 — no double opt-in / confirmation).
 *
 * @package StoreDash\Services\Optin
 * @since   1.2.4
 */

namespace StoreDash\Services\Optin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt-in Handler Class
 */
class Opt_In_Handler {

	/**
	 * Default Hookdeck source URL for the opt-in webhook.
	 * Overridable via the `woodash_optin_webhook_url` option (localhost dev, etc.),
	 * mirroring how `woodash_cart_webhook_url` is handled.
	 */
	const DEFAULT_WEBHOOK_URL = 'https://webhooks.storedash.io/fs3dv9bo71f1xn';

	/**
	 * Consent sources this plugin may report. Mirrors storedash-sync's
	 * allowedOptinSources (marketing_optin.go) — the receiver coerces anything
	 * else to 'manual', which never fires a welcome automation. Add both sides.
	 */
	const ALLOWED_SOURCES = array( 'checkout_optin', 'signup_widget', 'waitlist_optin', 'enquiry_optin' );

	/**
	 * Order meta flag used to guarantee the webhook fires at most once per order.
	 */
	const ORDER_SENT_META = '_woodash_optin_webhook_sent';

	/**
	 * Order IDs already processed in the current request (per-request idempotency).
	 *
	 * @var array
	 */
	private static $processed_order_ids = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		// Fire on order placed. Priority 20 so it runs after Cart_Tracking (priority 10)
		// which may already have stored the order meta. Classic + block checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_order_optin' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'handle_order_optin' ), 20 );

		// Elementor signup widget submissions (logged-in + guests).
		add_action( 'wp_ajax_storedash_optin_submit', array( $this, 'handle_signup_submit' ) );
		add_action( 'wp_ajax_nopriv_storedash_optin_submit', array( $this, 'handle_signup_submit' ) );
	}

	/**
	 * Handle the checkout marketing opt-in on order placed.
	 *
	 * Persists the choice to order meta and (for logged-in customers) user meta,
	 * then posts the opt-in webhook when the box was checked. Self-sufficient:
	 * reads the checkbox directly from the request rather than relying on the
	 * cart-tracking path (which short-circuits when no cart token is found).
	 *
	 * @param int|\WC_Order $order Order ID or object (both hooks supported).
	 */
	public function handle_order_optin( $order ) {
		// Feature gate — same option that controls the checkbox rendering.
		if ( ! get_option( 'woodash_checkout_optin_enabled', false ) ) {
			return;
		}

		$order = wc_get_order( $order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id = (int) $order->get_id();

		// Per-request guard (the two hooks can both fire for the same order).
		if ( in_array( $order_id, self::$processed_order_ids, true ) ) {
			return;
		}
		self::$processed_order_ids[] = $order_id;

		// Cross-request guard (idempotent if the order is processed again).
		if ( $order->get_meta( self::ORDER_SENT_META ) ) {
			return;
		}

		// Determine whether the checkbox was submitted + checked. The classic
		// checkout posts `woodash_marketing_optin`; fall back to any value already
		// written to order meta by the cart-tracking path.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified upstream
		$posted_optin = isset( $_POST['woodash_marketing_optin'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified upstream
		$is_opted_in = ! empty( $_POST['woodash_marketing_optin'] )
			|| 1 === (int) $order->get_meta( '_woodash_marketing_optin' );

		// Persist the choice when the checkbox was part of this request.
		if ( $posted_optin || $is_opted_in ) {
			$value = $is_opted_in ? 1 : 0;

			$order->update_meta_data( '_woodash_marketing_optin', $value );

			$customer_id = (int) $order->get_customer_id();
			if ( $customer_id > 0 ) {
				update_user_meta( $customer_id, '_woodash_marketing_optin', $value );
			}
		}

		if ( ! $is_opted_in ) {
			$order->save();
			return;
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			$order->save();
			return;
		}

		$customer_id = (int) $order->get_customer_id();

		$sent = self::send_optin_webhook(
			array(
				'email'       => $email,
				'customer_id' => $customer_id > 0 ? $customer_id : null,
				'source'      => 'checkout_optin',
			)
		);

		// Mark sent so a later status transition can't re-send — but only when a
		// POST was actually attempted. If the webhook was a no-op (store not
		// connected / URL emptied), leave the flag unset so consent isn't
		// recorded as sent when nothing fired.
		if ( $sent ) {
			$order->update_meta_data( self::ORDER_SENT_META, 1 );
		}
		$order->save();
	}

	/**
	 * Handle the Elementor signup widget submission.
	 *
	 * Net-new single opt-in: validates the email, then posts the opt-in webhook
	 * with `source=signup_widget` and `customer_id=null`. No double opt-in.
	 */
	public function handle_signup_submit() {
		try {
			// Nonce.
			if ( ! check_ajax_referer( 'storedash_optin_submit', 'nonce', false ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Security verification failed. Please refresh the page and try again.', 'storedash' ),
					),
					403
				);
				return;
			}

			// Honeypot — silently succeed for bots.
			if ( ! empty( $_POST['website'] ) ) {
				wp_send_json_success(
					array(
						'message' => __( 'Thanks for subscribing!', 'storedash' ),
					)
				);
				return;
			}

			// Time-based trap — reject sub-3-second submissions (bots submit instantly).
			// Only treat a non-negative delta below the threshold as a bot; a client clock that
			// runs ahead of the server yields a negative delta for a genuine submission, which
			// must NOT be rejected.
			$form_ts = isset( $_POST['_ts'] ) ? absint( $_POST['_ts'] ) : 0;
			$delta   = time() - $form_ts;
			if ( $form_ts > 0 && $delta >= 0 && $delta < 3 ) {
				wp_send_json_success(
					array(
						'message' => __( 'Thanks for subscribing!', 'storedash' ),
					)
				);
				return;
			}

			// Rate limiting — 100 submissions per IP per hour.
			if ( ! $this->check_rate_limit() ) {
				wp_send_json_error(
					array(
						'message' => __( 'Too many requests. Please try again in a few minutes.', 'storedash' ),
					),
					429
				);
				return;
			}

			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

			if ( ! is_email( $email ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Please enter a valid email address.', 'storedash' ),
					),
					400
				);
				return;
			}

			$this->send_optin_webhook(
				array(
					'email'       => $email,
					'customer_id' => null,
					'source'      => 'signup_widget',
				)
			);

			wp_send_json_success(
				array(
					'message' => __( 'Thanks for subscribing!', 'storedash' ),
				)
			);

		} catch ( \Exception $e ) {
			\StoreDash_Helpers::log_message( 'Opt-in: Error processing submission - ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'An error occurred. Please try again.', 'storedash' ),
				),
				500
			);
		}
	}

	/**
	 * Build, sign and send the opt-in webhook (fire-and-forget, non-blocking).
	 *
	 * Reuses the established envelope + signing: hex HMAC-SHA256 of the exact
	 * JSON body using the store webhook secret, sent in `X-WooDash-Signature`.
	 *
	 * @param array $args {
	 *     @type string   $email       Customer email (will be lowercased + trimmed).
	 *     @type int|null $customer_id Woo customer/user id, or null for guests/signups.
	 *     @type string   $source      One of self::ALLOWED_SOURCES; anything else
	 *                                 falls back to 'checkout_optin'.
	 * }
	 *
	 * Public + static so other customer-initiated consent surfaces can reuse it
	 * without re-instantiating this class (the constructor registers hooks, so
	 * `new Opt_In_Handler()` would double-bind them). The waitlist form's consent
	 * checkbox calls this with `waitlist_optin`, the enquiry form with
	 * `enquiry_optin` (both `signup_widget` before 1.17.0).
	 *
	 * @return bool True when a POST was actually attempted (non-empty email and
	 *              webhook URL); false when the send was a no-op. Lets callers
	 *              avoid stamping "sent" state for a webhook that never fired.
	 */
	public static function send_optin_webhook( array $args ) {
		$email = strtolower( trim( (string) ( $args['email'] ?? '' ) ) );
		if ( empty( $email ) ) {
			return false;
		}

		$source = (string) ( $args['source'] ?? '' );
		if ( ! in_array( $source, self::ALLOWED_SOURCES, true ) ) {
			$source = 'checkout_optin';
		}

		$customer_id = isset( $args['customer_id'] ) && $args['customer_id']
			? (int) $args['customer_id']
			: null;

		// Gate on a completed Storedash connection, honoring "unset = default,
		// explicitly-empty = disabled". The previous code coerced an explicitly
		// cleared option back to the default, so opt-in could never be turned off.
		$webhook_url = \StoreDash_Helpers::resolve_webhook_url( 'woodash_optin_webhook_url', self::DEFAULT_WEBHOOK_URL );
		if ( '' === $webhook_url ) {
			return false;
		}

		$payload = array(
			'event'      => 'subscription.optin',
			'store_id'   => (int) get_option( 'woodash_store_id', 0 ),
			'store_url'  => get_site_url(),
			'data'       => array(
				'email'       => $email,
				'customer_id' => $customer_id,
				'source'      => $source,
				'initiator'   => 'customer',
			),
			'webhook_id' => wp_generate_uuid4(),
			'timestamp'  => current_time( 'c' ),
		);

		// Sign the EXACT bytes that are sent so the Go receiver's HMAC matches.
		$body = wp_json_encode( $payload );

		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'StoreDash-Plugin/' . STOREDASH_VERSION,
		);

		$webhook_secret = get_option( 'woodash_webhook_secret' );
		if ( $webhook_secret ) {
			$headers['X-WooDash-Signature'] = hash_hmac( 'sha256', $body, $webhook_secret );
		}

		wp_remote_post(
			$webhook_url,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 5,
				'blocking'  => false,
				'sslverify' => true,
			)
		);

		if ( class_exists( 'StoreDash_Helpers' ) ) {
			\StoreDash_Helpers::debug_log( 'Opt-in: webhook triggered (' . $source . ') for ' . $email );
		}

		return true;
	}

	/**
	 * Rate limiting — max 100 signups per IP per hour.
	 *
	 * @return bool True when within the limit.
	 */
	private function check_rate_limit() {
		$client_ip = \StoreDash_Helpers::get_client_ip();
		$cache_key = 'optin_rate_' . md5( $client_ip );

		// DB-backed transient so the per-IP counter survives across requests.
		$current_count = (int) get_transient( $cache_key );

		if ( $current_count >= 100 ) {
			return false;
		}

		set_transient( $cache_key, $current_count + 1, HOUR_IN_SECONDS );
		return true;
	}
}

// Initialize (mirrors waitlist / enquiry handlers).
new Opt_In_Handler();
