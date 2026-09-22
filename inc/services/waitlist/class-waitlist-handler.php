<?php
/**
 * Waitlist Handler
 *
 * Handles AJAX form submissions for back-in-stock notifications
 *
 * @package StoreDash\Services\Waitlist
 * @since   1.0.0
 */

namespace StoreDash\Services\Waitlist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waitlist Handler Class
 */
class Waitlist_Handler {

	/**
	 * Minimum age, in seconds, a rendered form must reach before a submission is
	 * treated as human.
	 *
	 * Measured from SERVER render time (see `form_stamp()`), not from any
	 * client-side event. A shopper cannot receive the HTML, read that a product
	 * is out of stock, enter an email and submit inside this window; a naive bot
	 * that posts the instant it parses the form does exactly that.
	 */
	const MIN_FORM_AGE = 3;

	/**
	 * Upper bound on stamp age. Beyond this the stamp is treated as "unknown
	 * age" and the timing check is skipped rather than failed — a page served
	 * from a full-page cache legitimately carries an old stamp, and blocking
	 * those would break signups on every cached store.
	 */
	const MAX_FORM_AGE = DAY_IN_SECONDS;

	/**
	 * Burst limit: submissions per IP per minute.
	 *
	 * The hourly cap alone permits 100 rapid-fire submissions; this bounds the
	 * rate as well as the volume. No human fills this form five times a minute.
	 */
	const BURST_LIMIT  = 5;
	const BURST_WINDOW = MINUTE_IN_SECONDS;

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
		return wp_hash( $ts . '|storedash_waitlist_stamp', 'nonce' );
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		// AJAX endpoints
		add_action( 'wp_ajax_storedash_waitlist_submit', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_storedash_waitlist_submit', array( $this, 'handle_submit' ) );

		// Credential refresh for cached pages — see handle_refresh().
		add_action( 'wp_ajax_storedash_waitlist_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'wp_ajax_nopriv_storedash_waitlist_refresh', array( $this, 'handle_refresh' ) );
	}

	/**
	 * Hand a fresh nonce + form stamp to the page's own JavaScript.
	 *
	 * Full-page caching (WP Rocket, nginx cache) freezes the nonce that
	 * wp_localize_script baked into the HTML; once it ages past the nonce
	 * lifetime (~12–24h), every signup from that cached page fails with
	 * "Security verification failed". The widget script calls this endpoint once
	 * at init — admin-ajax.php is excluded from page caches by convention — and
	 * swaps in live credentials, so the cached HTML can be arbitrarily old.
	 *
	 * Deliberately unauthenticated and NOT nonce-checked (it is the nonce
	 * bootstrap). This does not weaken CSRF protection: a cross-origin attacker
	 * can trigger this request but can never READ the response — the
	 * same-origin policy is what protects nonce values, not secrecy of the
	 * endpoint. Refreshing the stamp alongside also re-arms the timing trap on
	 * cached pages, which MAX_FORM_AGE otherwise has to skip.
	 *
	 * Rate-limited per IP, but on its OWN counter — this fires on every page
	 * view that renders a form, so sharing the submission counters would let
	 * ordinary browsing exhaust a shopper's submit budget before they ever
	 * submit.
	 */
	public function handle_refresh() {
		$refresh_key   = 'storedash_wl_rf_' . md5( $this->get_client_ip() );
		$refresh_count = (int) get_transient( $refresh_key );

		// 300/hour ≈ a shopper opening five out-of-stock pages a minute, all
		// hour — far above real browsing, far below a useful farming rate.
		if ( $refresh_count >= 300 ) {
			wp_send_json_error( array( 'message' => 'rate_limited' ), 429 );
			return;
		}
		set_transient( $refresh_key, $refresh_count + 1, HOUR_IN_SECONDS );

		$stamp = self::form_stamp();

		wp_send_json_success(
			array(
				'nonce' => wp_create_nonce( 'storedash_waitlist_submit' ),
				'ts'    => $stamp['ts'],
				'tsh'   => $stamp['hash'],
			)
		);
	}

	/**
	 * Handle waitlist form submission
	 */
	public function handle_submit() {
		try {
			// Verify nonce
			if ( ! check_ajax_referer( 'storedash_waitlist_submit', 'nonce', false ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Security verification failed. Please refresh the page and try again.', 'storedash' ),
					),
					403
				);
				return;
			}

			// Honeypot check — if bot fills hidden "website" field, silently return success
			if ( ! empty( $_POST['website'] ) ) {
				// Logged because this branch answers "success" while saving nothing.
				// Without a trace, a false positive is indistinguishable from a
				// working signup that vanished.
				\StoreDash_Helpers::debug_log( 'Waitlist: submission discarded by honeypot.' );
				wp_send_json_success(
					array(
						'message' => __( 'Thank you! We\'ll notify you when this product is back in stock.', 'storedash' ),
					)
				);
				return;
			}

			/*
			 * Timing trap.
			 *
			 * Unlike the honeypot above, this heuristic can be WRONG about a
			 * real person, so it must never answer "success" and drop the
			 * signup — that leaves a shopper believing they are on a waitlist
			 * they were never added to, with no trace for anyone to find. It
			 * returns a retryable error instead: a human simply submits again,
			 * by which point the form is comfortably older than the threshold,
			 * while a bot gains nothing and burns rate-limit budget.
			 */
			$form_ts  = isset( $_POST['_ts'] ) ? absint( $_POST['_ts'] ) : 0;
			$form_tsh = isset( $_POST['_tsh'] ) ? sanitize_text_field( wp_unslash( $_POST['_tsh'] ) ) : '';

			// A form with no stamp at all predates this protection (e.g. HTML
			// served from a page cache built by an older plugin build). Skip the
			// check rather than reject — failing open on rollout beats blocking
			// genuine signups.
			if ( $form_ts > 0 && '' === $form_tsh ) {
				/*
				 * A timestamp with NO signature at all is a legacy client: JS
				 * from a build that stamped `_ts` itself (cached under an old
				 * `?ver=`), posting to this newer handler. Rejecting these broke
				 * every submission on stores with cached assets.
				 *
				 * Skipping costs no security: a bot can already bypass the
				 * timing check by omitting `_ts` entirely (deliberate fail-open
				 * above), so "ts without hash" grants nothing that omission
				 * doesn't. The hash only exists to stop back-dating by clients
				 * that DO participate.
				 */
				\StoreDash_Helpers::debug_log( 'Waitlist: legacy unsigned _ts — timing check skipped (stale cached JS).' );
			} elseif ( $form_ts > 0 ) {
				if ( ! hash_equals( self::stamp_hash( $form_ts ), $form_tsh ) ) {
					// The timestamp was altered or fabricated. No legitimate
					// client can do this; a bot back-dating `_ts` to defeat the
					// window does exactly this.
					\StoreDash_Helpers::debug_log( 'Waitlist: submission rejected — invalid form stamp signature.' );
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
					// cache is also serving a stale nonce, which will start
					// failing submissions outright.
					\StoreDash_Helpers::debug_log(
						sprintf( 'Waitlist: form stamp older than max age (%ds) — timing check skipped, page likely cached.', $delta )
					);
				} elseif ( $delta >= 0 && $delta < self::MIN_FORM_AGE ) {
					\StoreDash_Helpers::debug_log(
						sprintf( 'Waitlist: submission rejected by timing trap (%ds since render).', $delta )
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

			// Sanitize input. Validation lives in create_entry() so the REST
			// ingress (headless storefronts) and this AJAX ingress share it.
			$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$variation_id   = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
			$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
			$customer_name  = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
			$customer_phone = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '';

			/*
			 * Marketing consent (ADR-019) is read here but deliberately plays no
			 * part in validation. Joining a waitlist is a transactional request;
			 * the back-in-stock notification must send whether or not the
			 * shopper wants marketing email. The two are separate records and
			 * separate permissions, and conflating them is how people stop
			 * receiving notifications they explicitly asked for.
			 */
			$marketing_optin = ! empty( $_POST['marketing_optin'] );

			$result = self::create_entry(
				array(
					'product_id'      => $product_id,
					'variation_id'    => $variation_id,
					'customer_email'  => $customer_email,
					'customer_name'   => $customer_name,
					'customer_phone'  => $customer_phone,
					'marketing_optin' => $marketing_optin,
					'customer_id'     => get_current_user_id() ?: null,
					'source'          => 'waitlist_widget',
				)
			);

			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
					),
					$status
				);
				return;
			}

			// Success response
			wp_send_json_success(
				array(
					'message' => __( 'Thank you! We\'ll notify you when this product is back in stock.', 'storedash' ),
				)
			);

		} catch ( \Exception $e ) {
			\StoreDash_Helpers::log_message( 'Waitlist: Error processing submission - ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'An error occurred. Please try again.', 'storedash' ),
				),
				500
			);
		}
	}

	/**
	 * Create a waitlist entry: validate, insert, and fire the outbound webhook.
	 *
	 * Shared by the AJAX widget and the authenticated REST ingress
	 * (`POST storedash/v1/waitlist/entries`, used by headless storefronts).
	 * Everything that makes a signup *correct* lives here — product must
	 * exist and be out of stock, one pending row per email/product/variation,
	 * webhook after the row is durable. Everything that makes an ingress
	 * *safe* (nonce, honeypot, rate limits, API-key auth) stays with the
	 * caller, because each ingress has a different trust model.
	 *
	 * Writes to THIS table, not only Supabase, on purpose: the stock monitor
	 * that fires `waitlist.stock_available` counts pending rows here, so an
	 * entry that only exists in Supabase would never be notified.
	 *
	 * @param array $input {
	 *     Already-sanitized input.
	 *
	 *     @type int         $product_id      Parent (or simple) product id. Required.
	 *     @type int         $variation_id    Variation id, 0 for none.
	 *     @type string      $customer_email  Required.
	 *     @type string      $customer_name   Optional; stored as 'Guest' when empty.
	 *     @type string      $customer_phone  Optional.
	 *     @type string      $language        Optional 2-letter code; defaults to the site locale.
	 *     @type bool        $marketing_optin Optional; fires a separate opt-in webhook.
	 *     @type int|null    $customer_id     Optional WP user id for the opt-in record.
	 *     @type string      $source          Ingress label stored on the row.
	 * }
	 * @return array|\WP_Error `{ id: int }` on success; WP_Error carrying `status` in its data.
	 */
	public static function create_entry( array $input ) {
		$product_id      = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$variation_id    = isset( $input['variation_id'] ) ? absint( $input['variation_id'] ) : 0;
		$customer_email  = isset( $input['customer_email'] ) ? sanitize_email( $input['customer_email'] ) : '';
		$customer_name   = isset( $input['customer_name'] ) ? sanitize_text_field( $input['customer_name'] ) : '';
		$customer_phone  = isset( $input['customer_phone'] ) ? sanitize_text_field( $input['customer_phone'] ) : '';
		$marketing_optin = ! empty( $input['marketing_optin'] );
		$customer_id     = ! empty( $input['customer_id'] ) ? (int) $input['customer_id'] : null;
		$source          = isset( $input['source'] ) ? sanitize_key( $input['source'] ) : 'waitlist_widget';

		$language = isset( $input['language'] ) ? strtolower( sanitize_text_field( $input['language'] ) ) : '';
		if ( ! preg_match( '/^[a-z]{2}$/', $language ) ) {
			$language = self::get_user_language();
		}

		// Column widths (see StoreDash_Activator): email 100, name 100, phone 20.
		// $wpdb->insert() would otherwise fail on overlong input and surface as
		// a generic 500 instead of a validation error.
		$customer_name  = mb_substr( $customer_name, 0, 100 );
		$customer_phone = mb_substr( $customer_phone, 0, 20 );

		if ( ! $product_id ) {
			return new \WP_Error(
				'storedash_waitlist_invalid_product',
				__( 'Invalid product.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_email( $customer_email ) || mb_strlen( $customer_email ) > 100 ) {
			return new \WP_Error(
				'storedash_waitlist_invalid_email',
				__( 'Please enter a valid email address.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		// Verify product exists and is out of stock
		$product = \wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return new \WP_Error(
				'storedash_waitlist_product_not_found',
				__( 'Product not found.', 'storedash' ),
				array( 'status' => 404 )
			);
		}

		// A variation id must actually belong to the product it was sent with.
		// Otherwise a caller could file an entry under one product while the
		// stock monitor (which keys on the variation's real parent) never
		// matches it, and the row would sit pending forever.
		if ( $variation_id ) {
			if ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id ) {
				return new \WP_Error(
					'storedash_waitlist_invalid_product',
					__( 'Invalid product.', 'storedash' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( $product->is_type( 'variation' ) ) {
			return new \WP_Error(
				'storedash_waitlist_invalid_product',
				__( 'Invalid product.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		// Only allow signup if product is out of stock
		if ( $product->is_in_stock() ) {
			return new \WP_Error(
				'storedash_waitlist_in_stock',
				__( 'This product is currently in stock.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		// Check for duplicates
		if ( self::check_duplicate( $product_id, $variation_id, $customer_email ) ) {
			return new \WP_Error(
				'storedash_waitlist_duplicate',
				__( 'You\'re already on the waitlist for this product.', 'storedash' ),
				array( 'status' => 409 )
			);
		}

		// Prepare data
		$store_id = get_option( 'woodash_store_id', 0 );

		// Get product details
		$product_sku          = $product->get_sku();
		$product_name         = $product->get_name();
		$variation_attributes = null;

		if ( $product->is_type( 'variation' ) ) {
			$raw_attrs = $product->get_variation_attributes();
			$readable  = array();
			foreach ( $raw_attrs as $key => $value ) {
				if ( empty( $value ) ) {
					continue;
				}
				$taxonomy = str_replace( 'attribute_', '', $key );
				$label    = wc_attribute_label( $taxonomy );
				if ( taxonomy_exists( $taxonomy ) ) {
					$term  = get_term_by( 'slug', $value, $taxonomy );
					$value = $term && ! is_wp_error( $term ) ? $term->name : ucfirst( $value );
				} else {
					$value = ucfirst( $value );
				}
				$readable[ $label ] = $value;
			}
			$variation_attributes = ! empty( $readable ) ? wp_json_encode( $readable ) : null;
		}

		// Insert into database
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_product_waitlist';

		$data = array(
			'store_id'             => $store_id,
			'product_id'           => $product_id,
			'variation_id'         => $variation_id ? (int) $variation_id : 0,
			'customer_email'       => $customer_email,
			'customer_name'        => $customer_name ? $customer_name : 'Guest',
			'customer_phone'       => $customer_phone ? $customer_phone : null,
			'language'             => $language,
			'product_sku'          => $product_sku,
			'product_name'         => $product_name,
			'variation_attributes' => $variation_attributes,
			'status'               => 'pending',
			'source'               => $source,
			'created_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( $table_name, $data );

		if ( false === $result ) {
			// A concurrent double-submit can lose the check_duplicate() race and hit the
			// UNIQUE index. Treat a duplicate-key error as "already on the waitlist"
			// (the same friendly 409 check_duplicate() returns) rather than a generic 500.
			$last_error = (string) $wpdb->last_error;
			if ( false !== stripos( $last_error, 'Duplicate entry' ) || false !== strpos( $last_error, '1062' ) ) {
				return new \WP_Error(
					'storedash_waitlist_duplicate',
					__( 'You\'re already on the waitlist for this product.', 'storedash' ),
					array( 'status' => 409 )
				);
			}

			\StoreDash_Helpers::log_message( 'Waitlist: Database insert failed - ' . $last_error );
			return new \WP_Error(
				'storedash_waitlist_db_error',
				__( 'Failed to save your request. Please try again.', 'storedash' ),
				array( 'status' => 500 )
			);
		}

		$waitlist_id = (int) $wpdb->insert_id;

		// Trigger webhook (non-blocking)
		self::trigger_waitlist_webhook(
			array(
				'id'                   => $waitlist_id,
				'store_id'             => $store_id,
				'product_id'           => $product_id,
				'variation_id'         => $variation_id ? $variation_id : null,
				'customer_email'       => $customer_email,
				'customer_name'        => $customer_name ? $customer_name : 'Guest',
				'customer_phone'       => $customer_phone ? $customer_phone : null,
				'language'             => $language,
				'product_sku'          => $product_sku,
				'product_name'         => $product_name,
				'variation_attributes' => $variation_attributes ? json_decode( $variation_attributes, true ) : null,
				'created_at'           => current_time( 'c' ),
			)
		);

		/*
		 * Record marketing consent as a SEPARATE event, after the waitlist
		 * entry is safely persisted. Ordering matters: if the opt-in webhook
		 * were to fail, the waitlist signup — the thing the shopper actually
		 * asked for — is already saved. The call is non-blocking and its
		 * outcome never affects the response.
		 *
		 * Source is `waitlist_optin` (since 1.17.0; previously reused
		 * `signup_widget`) so merchants can tell a waitlist consent apart from
		 * the newsletter signup widget when targeting automations and lists.
		 */
		if ( $marketing_optin && class_exists( '\StoreDash\Services\Optin\Opt_In_Handler' ) ) {
			\StoreDash\Services\Optin\Opt_In_Handler::send_optin_webhook(
				array(
					'email'       => $customer_email,
					'customer_id' => $customer_id,
					'source'      => 'waitlist_optin',
				)
			);
		}

		return array( 'id' => $waitlist_id );
	}

	/**
	 * Check for duplicate entries
	 */
	private static function check_duplicate( $product_id, $variation_id, $email ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'storedash_product_waitlist';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name
            WHERE product_id = %d
            AND COALESCE(variation_id, 0) = %d
            AND customer_email = %s
            AND status = 'pending'",
				$product_id,
				$variation_id,
				$email
			)
		);

		return $count > 0;
	}

	/**
	 * Rate limiting check
	 * Max 100 signups per IP per hour
	 */
	private function check_rate_limit() {
		// Allow bypassing rate limit for testing/development
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && apply_filters( 'storedash_waitlist_disable_rate_limit', false ) ) {
			return true;
		}

		$client_ip = $this->get_client_ip();

		/*
		 * Burst window first. The hourly cap below bounds VOLUME but not RATE —
		 * it happily allows 100 submissions in five seconds. This bounds the
		 * rate too, which is what actually characterises scripted abuse. Five a
		 * minute is far above any human's interaction with this form and far
		 * below a useful attack rate.
		 */
		$burst_key   = 'storedash_wl_burst_' . md5( $client_ip );
		$burst_count = (int) get_transient( $burst_key );

		if ( $burst_count >= self::BURST_LIMIT ) {
			\StoreDash_Helpers::debug_log( "Waitlist: Burst limit hit for IP {$client_ip} ({$burst_count}/min)" );
			return false;
		}

		set_transient( $burst_key, $burst_count + 1, self::BURST_WINDOW );

		// Use a DB-backed transient so the per-IP counter survives between
		// admin-ajax.php requests. The object cache group used elsewhere is
		// registered non-persistent (see StoreDash_Bootstrap::init), so a
		// wp_cache_* counter never persists and the cap never fires.
		$transient_key = 'storedash_wl_rl_' . md5( $client_ip );
		$current_count = (int) get_transient( $transient_key );

		// Rate limit: 100 requests per hour per IP
		if ( $current_count >= 100 ) {
			\StoreDash_Helpers::debug_log( "Waitlist: Rate limit hit for IP {$client_ip} (count: {$current_count})" );
			return false;
		}

		// Increment counter; the transient TTL rolls forward on each write, so the
		// window resets one hour after the last counted request.
		set_transient( $transient_key, $current_count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Get client IP address.
	 *
	 * Delegates to the centralized non-spoofable resolver
	 * ( \StoreDash_Helpers::get_client_ip() ). The legacy
	 * `storedash_waitlist_trust_proxy_headers` filter is still honored for
	 * backward compatibility: when set, it opts this call into the shared
	 * `storedash_trust_proxy_headers` behavior.
	 */
	private function get_client_ip() {
		$waitlist_trust = (bool) apply_filters( 'storedash_waitlist_trust_proxy_headers', false );

		if ( $waitlist_trust ) {
			add_filter( 'storedash_trust_proxy_headers', '__return_true' );
			$ip = \StoreDash_Helpers::get_client_ip();
			remove_filter( 'storedash_trust_proxy_headers', '__return_true' );
			return $ip;
		}

		return \StoreDash_Helpers::get_client_ip();
	}

	/**
	 * Get user language
	 */
	private static function get_user_language() {
		$locale = get_locale();

		// Convert locale to language code (e.g., en_US -> en)
		$parts = explode( '_', $locale );
		return $parts[0];
	}

	/**
	 * Trigger waitlist webhook
	 */
	private static function trigger_waitlist_webhook( $data ) {
		// Gate on a completed Storedash connection, honoring "unset = default,
		// explicitly-empty = disabled". Returns '' before setup completes so no
		// shopper data leaves the store on a never-connected install.
		$webhook_url = \StoreDash_Helpers::resolve_webhook_url( 'storedash_waitlist_webhook_url', 'https://webhooks.storedash.io/q13udwod7lphmi' );
		if ( '' === $webhook_url ) {
			return;
		}

		// Prepare webhook payload (like cart webhooks)
		$payload = array(
			'event'      => 'waitlist.created',
			'store_id'   => (string) get_option( 'woodash_store_id', '' ), // String at top level
			'store_url'  => get_site_url(),
			'data'       => $data,
			'webhook_id' => wp_generate_uuid4(),
			'timestamp'  => current_time( 'c' ),
			'source'     => 'waitlist_widget',
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
				'blocking'  => false, // Non-blocking for performance
				'sslverify' => true,
			)
		);

		// Log for debugging
		\StoreDash_Helpers::debug_log( 'Waitlist: Webhook triggered for ' . $data['customer_email'] );
	}
}

// Initialize
new Waitlist_Handler();
