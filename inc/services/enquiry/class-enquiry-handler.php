<?php
/**
 * Enquiry Handler
 *
 * Handles AJAX form submissions for product enquiries.
 *
 * @package StoreDash\Services\Enquiry
 * @since   1.0.0
 */

namespace StoreDash\Services\Enquiry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enquiry Handler Class
 */
class Enquiry_Handler {

	/**
	 * Minimum age, in seconds, a rendered form must reach before a submission is
	 * treated as human.
	 *
	 * Measured from SERVER render time (see `form_stamp()`), not from any
	 * client-side event. A shopper cannot receive the HTML, read the product
	 * page, type a question and submit inside this window; a naive bot that
	 * posts the instant it parses the form does exactly that.
	 */
	const MIN_FORM_AGE = 3;

	/**
	 * Upper bound on stamp age. Beyond this the stamp is treated as "unknown
	 * age" and the timing check is skipped rather than failed — a page served
	 * from a full-page cache legitimately carries an old stamp, and blocking
	 * those would break enquiries on every cached store.
	 */
	const MAX_FORM_AGE = DAY_IN_SECONDS;

	/**
	 * Burst limit: submissions per IP per minute.
	 *
	 * The hourly cap alone permits 20 rapid-fire submissions; this bounds the
	 * rate as well as the volume. No human sends this form five times a minute.
	 */
	const BURST_LIMIT  = 5;
	const BURST_WINDOW = MINUTE_IN_SECONDS;

	/**
	 * Hourly submission cap per IP.
	 */
	const HOURLY_LIMIT = 20;

	/**
	 * Maximum message length. Mirrored by Enquiry_Renderer's `maxlength`.
	 */
	const MAX_MESSAGE_LENGTH = 2000;

	/**
	 * Build the anti-bot stamp embedded in a rendered form.
	 *
	 * Returns a render timestamp plus an HMAC over it. The signature is what
	 * makes the timing check meaningful: without it a bot simply posts a
	 * back-dated `_ts` and walks through. With it, the only way to obtain a
	 * valid stamp is to fetch the page — which is precisely the cost we want to
	 * impose, and which naturally ages the stamp.
	 *
	 * Stamping happens in PHP, never in JavaScript. A JS-set timestamp measures
	 * "time since the script ran", so a slow connection starts the clock late
	 * and a legitimate shopper can trip a trap they never should have.
	 *
	 * @return array{ts:int,hash:string}
	 */
	public static function form_stamp(): array {
		$ts = time();

		return array(
			'ts'   => $ts,
			'hash' => self::stamp_hash( $ts ),
		);
	}

	/**
	 * HMAC for a stamp timestamp, salted with the site's nonce keys.
	 *
	 * @param int $ts Render timestamp.
	 * @return string
	 */
	private static function stamp_hash( int $ts ): string {
		return wp_hash( $ts . '|storedash_enquiry_stamp', 'nonce' );
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		// AJAX endpoints
		add_action( 'wp_ajax_storedash_enquiry_submit', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_storedash_enquiry_submit', array( $this, 'handle_submit' ) );

		// Nonce refresh for cached pages — see handle_refresh().
		add_action( 'wp_ajax_storedash_enquiry_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'wp_ajax_nopriv_storedash_enquiry_refresh', array( $this, 'handle_refresh' ) );
	}

	/**
	 * Hand a fresh nonce to the page's own JavaScript.
	 *
	 * Full-page caching (WP Rocket, nginx cache) freezes the nonce that
	 * wp_localize_script baked into the HTML; once it ages past the nonce
	 * lifetime (~12–24h), every enquiry from that cached page fails with
	 * "Security verification failed". admin-ajax.php is excluded from page
	 * caches by convention, so one request here restores a working nonce on
	 * arbitrarily old cached HTML.
	 *
	 * Two properties of this endpoint are load-bearing and must not be
	 * "improved":
	 *
	 * 1. It returns the NONCE ONLY — never a new form stamp. The waitlist
	 *    equivalent reissues both, which is safe there because it is called
	 *    once at page init. This one is called on first INTERACTION (see
	 *    point 2), and reissuing the stamp at that moment would restart the
	 *    MIN_FORM_AGE window exactly when a fast human is about to submit.
	 *    That is precisely the bug that silently discarded a real waitlist
	 *    signup on a live store. The PHP-rendered stamp stays untouched, and
	 *    MAX_FORM_AGE already fails open for genuinely cached pages.
	 *
	 * 2. The widget script calls this on first interaction, NOT at page init.
	 *    The enquiry form renders on every product page, so an init-time call
	 *    would mean one uncached PHP request per product page view — a full
	 *    WordPress bootstrap serving 200 bytes of JSON, on traffic that is
	 *    otherwise entirely served from cache. Deferring to first interaction
	 *    turns "50,000 requests per 50,000 pageviews" into "one request per
	 *    person who actually engages".
	 *
	 * Deliberately unauthenticated and NOT nonce-checked (it is the nonce
	 * bootstrap). This does not weaken CSRF protection: a cross-origin attacker
	 * can trigger this request but can never READ the response — the
	 * same-origin policy is what protects nonce values, not secrecy of the
	 * endpoint.
	 *
	 * Rate-limited per IP on its OWN counter — sharing the submission counters
	 * would let ordinary browsing exhaust a shopper's submit budget before they
	 * ever submit.
	 *
	 * @return void
	 */
	public function handle_refresh() {
		$refresh_key   = 'storedash_eq_rf_' . md5( $this->get_client_ip() );
		$refresh_count = (int) get_transient( $refresh_key );

		// 100/hour. Because this only fires on interaction rather than on every
		// pageview, real usage sits far below this; a shared office NAT opening
		// enquiry forms all day still will not reach it.
		if ( $refresh_count >= 100 ) {
			wp_send_json_error( array( 'message' => 'rate_limited' ), 429 );
			return;
		}
		set_transient( $refresh_key, $refresh_count + 1, HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'nonce' => wp_create_nonce( 'storedash_enquiry_submit' ),
			)
		);
	}

	/**
	 * Handle enquiry form submission
	 *
	 * @return void
	 */
	public function handle_submit() {
		try {
			// Verify nonce
			if ( ! check_ajax_referer( 'storedash_enquiry_submit', 'nonce', false ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Security verification failed. Please refresh the page and try again.', 'storedash' ),
					),
					403
				);
				return;
			}

			// Billing gate. A form served from a page cache built before the
			// plan changed can still post here; refuse rather than accept an
			// enquiry the merchant's dashboard can no longer show or answer.
			// An error, never success — the customer must know it was not sent.
			if ( ! Enquiry_Config::is_entitled() ) {
				wp_send_json_error(
					array(
						'message' => __( 'Product enquiries are not available right now.', 'storedash' ),
					),
					403
				);
				return;
			}

			// Honeypot check — if a bot fills the hidden "website" field, answer
			// success and save nothing. This is the ONE branch allowed to do
			// that: a filled hidden field has no false-positive mode.
			if ( ! empty( $_POST['website'] ) ) {
				// Logged because this branch answers "success" while saving
				// nothing. Without a trace, a false positive is
				// indistinguishable from a working enquiry that vanished.
				\StoreDash_Helpers::debug_log( 'Enquiry: submission discarded by honeypot.' );
				wp_send_json_success(
					array(
						'message' => __( 'Thank you! Your enquiry has been submitted. We\'ll get back to you soon.', 'storedash' ),
					)
				);
				return;
			}

			/*
			 * Timing trap.
			 *
			 * Unlike the honeypot above, this heuristic can be WRONG about a
			 * real person, so it must never answer "success" and drop the
			 * enquiry — that leaves a customer believing they asked a question
			 * nobody will ever see, with no trace for anyone to find. (That is
			 * exactly what the previous version of this handler did, and the
			 * identical bug in the waitlist swallowed a real signup on a live
			 * store.) It returns a retryable error instead: a human simply
			 * submits again, by which point the form is comfortably older than
			 * the threshold, while a bot gains nothing and burns rate-limit
			 * budget.
			 */
			$form_ts  = isset( $_POST['_ts'] ) ? absint( $_POST['_ts'] ) : 0;
			$form_tsh = isset( $_POST['_tsh'] ) ? sanitize_text_field( wp_unslash( $_POST['_tsh'] ) ) : '';

			// A form with no stamp at all predates this protection (e.g. HTML
			// served from a page cache built by an older plugin build). Skip the
			// check rather than reject — failing open on rollout beats blocking
			// genuine enquiries.
			if ( $form_ts > 0 && '' === $form_tsh ) {
				/*
				 * A timestamp with NO signature at all is a legacy client: JS
				 * from a build that stamped `_ts` itself (cached under an old
				 * `?ver=`), posting to this newer handler. Rejecting these broke
				 * every submission on stores with cached assets when the
				 * waitlist shipped the same change.
				 *
				 * Skipping costs no security: a bot can already bypass the
				 * timing check by omitting `_ts` entirely (deliberate fail-open
				 * above), so "ts without hash" grants nothing that omission
				 * doesn't. The hash only exists to stop back-dating by clients
				 * that DO participate.
				 */
				\StoreDash_Helpers::debug_log( 'Enquiry: legacy unsigned _ts — timing check skipped (stale cached JS).' );
			} elseif ( $form_ts > 0 ) {
				if ( ! hash_equals( self::stamp_hash( $form_ts ), $form_tsh ) ) {
					// The timestamp was altered or fabricated. No legitimate
					// client can do this; a bot back-dating `_ts` to defeat the
					// window does exactly this.
					\StoreDash_Helpers::debug_log( 'Enquiry: submission rejected — invalid form stamp signature.' );
					wp_send_json_error(
						array(
							'message' => __( 'Something went wrong. Please refresh the page and try again.', 'storedash' ),
						),
						400
					);
					return;
				}

				$delta = time() - $form_ts;

				if ( $delta > self::MAX_FORM_AGE ) {
					// Served from a long-lived page cache. The stamp's age says
					// nothing about this visitor, so the timing check is skipped
					// entirely. Logged because if this is common on a store, its
					// cache is also serving a stale nonce — which the
					// interaction-time refresh is there to repair.
					\StoreDash_Helpers::debug_log(
						sprintf( 'Enquiry: form stamp older than max age (%ds) — timing check skipped, page likely cached.', $delta )
					);
				} elseif ( $delta >= 0 && $delta < self::MIN_FORM_AGE ) {
					\StoreDash_Helpers::debug_log(
						sprintf( 'Enquiry: submission rejected by timing trap (%ds since render).', $delta )
					);
					wp_send_json_error(
						array(
							'message' => __( 'Please try again.', 'storedash' ),
							'retry'   => true,
						),
						429
					);
					return;
				}
			}

			// Rate limiting
			if ( ! $this->check_rate_limit() ) {
				wp_send_json_error(
					array(
						'message' => __( 'Too many requests. Please try again in a few minutes.', 'storedash' ),
					),
					429
				);
				return;
			}

			// Sanitize and validate input
			$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
			$customer_name  = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
			$customer_phone = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '';
			$message        = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

			/*
			 * Marketing consent (ADR-019) is read here but deliberately plays no
			 * part in validation below. An enquiry is a support request; the
			 * merchant's reply must send whether or not the customer wants
			 * marketing email. The two are separate records and separate
			 * permissions, and conflating them is how people stop receiving
			 * replies they explicitly asked for.
			 */
			$marketing_optin = ! empty( $_POST['marketing_optin'] );

			// Validation
			if ( ! $product_id ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid product.', 'storedash' ),
					),
					400
				);
				return;
			}

			if ( ! is_email( $customer_email ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Please enter a valid email address.', 'storedash' ),
					),
					400
				);
				return;
			}

			if ( empty( $message ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Please enter a message.', 'storedash' ),
					),
					400
				);
				return;
			}

			// Enforce max message length
			if ( mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
				wp_send_json_error(
					array(
						'message' => __( 'Message is too long. Please keep it under 2000 characters.', 'storedash' ),
					),
					400
				);
				return;
			}

			// Link spam filter — legitimate enquiries rarely contain multiple URLs
			$url_count = preg_match_all( '/https?:\/\/|www\./i', $message );
			if ( $url_count > 1 ) {
				wp_send_json_error(
					array(
						'message' => __( 'Messages cannot contain multiple links.', 'storedash' ),
					),
					400
				);
				return;
			}

			// Verify product exists
			$product = \wc_get_product( $product_id );
			if ( ! $product ) {
				wp_send_json_error(
					array(
						'message' => __( 'Product not found.', 'storedash' ),
					),
					404
				);
				return;
			}

			// Prepare data
			$store_id = get_option( 'woodash_store_id', 0 );

			// Get product details
			$product_sku  = $product->get_sku();
			$product_name = $product->get_name();

			// Insert into database
			global $wpdb;
			$table_name = $wpdb->prefix . 'storedash_product_enquiries';

			$data = array(
				'store_id'       => $store_id,
				'product_id'     => $product_id,
				'customer_email' => $customer_email,
				'customer_name'  => $customer_name,
				'message'        => $message,
				'status'         => 'open',
				'sync_status'    => 'pending',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			);

			$result = $wpdb->insert( $table_name, $data );

			if ( false === $result ) {
				\StoreDash_Helpers::log_message( 'Enquiry: Database insert failed - ' . $wpdb->last_error );
				wp_send_json_error(
					array(
						'message' => __( 'Failed to save your enquiry. Please try again.', 'storedash' ),
					),
					500
				);
				return;
			}

			$enquiry_id = $wpdb->insert_id;

			// Trigger webhook (non-blocking)
			$this->trigger_enquiry_webhook(
				array(
					'id'             => $enquiry_id,
					'store_id'       => $store_id,
					'product_id'     => $product_id,
					'customer_email' => $customer_email,
					'customer_name'  => $customer_name ? $customer_name : '',
					'customer_phone' => $customer_phone ? $customer_phone : null,
					'message'        => $message,
					'product_name'   => $product_name,
					'product_sku'    => $product_sku,
					'created_at'     => current_time( 'c' ),
				)
			);

			/*
			 * Record marketing consent as a SEPARATE event, after the enquiry is
			 * safely persisted. Ordering matters: if the opt-in webhook were to
			 * fail, the enquiry — the thing the customer actually asked for — is
			 * already saved. The call is non-blocking and its outcome never
			 * affects the response.
			 *
			 * Source is `enquiry_optin` (since 1.17.0; previously reused
			 * `signup_widget`) so merchants can tell an enquiry consent apart
			 * from the newsletter signup widget in automations and lists.
			 */
			if ( $marketing_optin && class_exists( '\StoreDash\Services\Optin\Opt_In_Handler' ) ) {
				$current_user_id = get_current_user_id();

				\StoreDash\Services\Optin\Opt_In_Handler::send_optin_webhook(
					array(
						'email'       => $customer_email,
						'customer_id' => $current_user_id ? $current_user_id : null,
						'source'      => 'enquiry_optin',
					)
				);
			}

			// Success response
			wp_send_json_success(
				array(
					'message' => __( 'Thank you! Your enquiry has been submitted. We\'ll get back to you soon.', 'storedash' ),
				)
			);

		} catch ( \Exception $e ) {
			\StoreDash_Helpers::log_message( 'Enquiry: Error processing submission - ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'An error occurred. Please try again.', 'storedash' ),
				),
				500
			);
		}
	}

	/**
	 * Rate limiting check.
	 *
	 * Deliberately has NO duplicate check: asking a second question about the
	 * same product is legitimate behaviour, unlike joining the same waitlist
	 * twice. Volume and rate caps carry the abuse load on their own.
	 *
	 * @return bool
	 */
	private function check_rate_limit() {
		// Allow bypassing rate limit for testing/development
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && apply_filters( 'storedash_enquiry_disable_rate_limit', false ) ) {
			return true;
		}

		$client_ip = $this->get_client_ip();

		/*
		 * Burst window first. The hourly cap below bounds VOLUME but not RATE —
		 * it happily allows 20 submissions in five seconds. This bounds the
		 * rate too, which is what actually characterises scripted abuse. Five a
		 * minute is far above any human's interaction with this form and far
		 * below a useful attack rate.
		 */
		$burst_key   = 'storedash_eq_burst_' . md5( $client_ip );
		$burst_count = (int) get_transient( $burst_key );

		if ( $burst_count >= self::BURST_LIMIT ) {
			\StoreDash_Helpers::debug_log( "Enquiry: Burst limit hit for IP {$client_ip} ({$burst_count}/min)" );
			return false;
		}

		set_transient( $burst_key, $burst_count + 1, self::BURST_WINDOW );

		// DB-backed transient so the per-IP counter survives across requests.
		$cache_key     = 'enquiry_rate_' . md5( $client_ip );
		$current_count = (int) get_transient( $cache_key );

		if ( $current_count >= self::HOURLY_LIMIT ) {
			\StoreDash_Helpers::debug_log( "Enquiry: Rate limit hit for IP {$client_ip} (count: {$current_count})" );
			return false;
		}

		// Increment counter; the transient TTL rolls forward on each write, so
		// the window resets one hour after the last counted request.
		set_transient( $cache_key, $current_count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Get client IP address.
	 *
	 * Delegates to the centralized non-spoofable resolver
	 * ( \StoreDash_Helpers::get_client_ip() ), which trusts CF-Connecting-IP
	 * only when the peer is a published Cloudflare edge, and XFF/X-Real-IP only
	 * when the peer is a local proxy. Without that, a store behind Cloudflare
	 * sees one IP for every visitor and the caps above become sitewide rather
	 * than per-person.
	 *
	 * The legacy `storedash_enquiry_trust_proxy_headers` filter is still
	 * honored for backward compatibility: when set, it opts this call into the
	 * shared `storedash_trust_proxy_headers` behavior.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$enquiry_trust = (bool) apply_filters( 'storedash_enquiry_trust_proxy_headers', false );

		if ( $enquiry_trust ) {
			add_filter( 'storedash_trust_proxy_headers', '__return_true' );
			$ip = \StoreDash_Helpers::get_client_ip();
			remove_filter( 'storedash_trust_proxy_headers', '__return_true' );
			return $ip;
		}

		return \StoreDash_Helpers::get_client_ip();
	}

	/**
	 * Trigger enquiry webhook
	 *
	 * @param array $data Enquiry payload.
	 * @return void
	 */
	private function trigger_enquiry_webhook( $data ) {
		// Gate on a completed Storedash connection, honoring "unset = default,
		// explicitly-empty = disabled".
		$webhook_url = \StoreDash_Helpers::resolve_webhook_url( 'storedash_enquiry_webhook_url', 'https://webhooks.storedash.io/ba2gvnfgru4hwe' );
		if ( '' === $webhook_url ) {
			return;
		}

		// Prepare webhook payload
		$payload = array(
			'event'      => 'enquiry.created',
			'store_id'   => (string) get_option( 'woodash_store_id', '' ),
			'store_url'  => get_site_url(),
			'data'       => $data,
			'webhook_id' => wp_generate_uuid4(),
			'timestamp'  => current_time( 'c' ),
			'source'     => 'woocommerce_enquiry',
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

		// Send webhook (non-blocking)
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

		// Log for debugging
		\StoreDash_Helpers::debug_log( 'Enquiry: Webhook triggered for ' . $data['customer_email'] );
	}
}

// Initialize
new Enquiry_Handler();
