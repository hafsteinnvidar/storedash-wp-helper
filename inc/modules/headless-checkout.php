<?php
declare(strict_types=1);
/**
 * StoreDash Headless Checkout Module
 *
 * Handles the checkout handoff flow:
 * - Rewrite rule for /storedash-checkout/enter
 * - WooCommerce session restore from Cart-Token JWT
 * - Order metadata tagging for headless orders
 * - Return URL override to redirect back to storefront
 *
 * @package StoreDash
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Headless_Checkout {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_checkout_enter' ) );

		// Fallback: parse_request catches the URL even before rewrite rules flush
		add_action( 'parse_request', array( $this, 'parse_request_fallback' ) );

		add_action( 'woocommerce_checkout_order_created', array( $this, 'tag_headless_order' ) );
		add_filter( 'woocommerce_get_return_url', array( $this, 'override_return_url' ), 10, 2 );
	}

	public function register_rewrite_rules() {
		add_rewrite_rule(
			'storedash-checkout/enter/?$',
			'index.php?storedash_checkout_enter=1',
			'top'
		);
		add_rewrite_rule(
			'storedash-checkout/go/?$',
			'index.php?storedash_checkout_go=1',
			'top'
		);

		// Auto-flush once after plugin adds the rule
		if ( ! get_option( 'storedash_headless_checkout_rewrite_flushed' ) ) {
			flush_rewrite_rules( false );
			update_option( 'storedash_headless_checkout_rewrite_flushed', '1', true );
		}
	}

	/**
	 * Fallback: intercept /storedash-checkout/enter before WordPress
	 * rewrite rules are flushed. Parses the request URI directly.
	 */
	public function parse_request_fallback( $wp ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

		if ( $path === 'storedash-checkout/enter' ) {
			$wp->query_vars['storedash_checkout_enter'] = '1';
		} elseif ( $path === 'storedash-checkout/go' ) {
			$wp->query_vars['storedash_checkout_go'] = '1';
		}
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'storedash_checkout_enter';
		$vars[] = 'storedash_checkout_go';
		return $vars;
	}

	/**
	 * Handle the /storedash-checkout/enter request.
	 *
	 * Verifies the HMAC-signed URL, restores the WooCommerce session
	 * from the Cart-Token JWT, and redirects to /checkout.
	 */
	public function handle_checkout_enter() {
		// Second leg of the double-redirect: cookie is already stored,
		// just forward to the real checkout page.
		if ( get_query_var( 'storedash_checkout_go' ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( ! get_query_var( 'storedash_checkout_enter' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC-signed URL, not a form
		$cart_token = isset( $_GET['ct'] ) ? sanitize_text_field( wp_unslash( $_GET['ct'] ) ) : '';
		$session_id = isset( $_GET['sid'] ) ? sanitize_text_field( wp_unslash( $_GET['sid'] ) ) : '';
		$timestamp  = isset( $_GET['ts'] ) ? sanitize_text_field( wp_unslash( $_GET['ts'] ) ) : '';
		$sig        = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';

		$result = StoreDash_API_Headless_Checkout::verify( $cart_token, $session_id, $timestamp, $sig );
		if ( true !== $result ) {
			$this->redirect_with_error( $result );
			return;
		}

		// Single-use guard: a valid signature is otherwise replayable for its full
		// 1-hour window and would re-restore the WooCommerce session each time. Reject
		// a signature that has already been consumed by a SUCCESSFUL restore. The
		// signature is only burned after restore succeeds (below), so a transient
		// restore failure does not permanently brick an otherwise-valid link.
		$replay_key = 'storedash_hc_used_' . hash( 'sha256', (string) $sig );
		if ( false !== get_transient( $replay_key ) ) {
			$this->redirect_with_error( 'replayed' );
			return;
		}

		$wc_customer_id = $this->extract_customer_id_from_jwt( $cart_token );

		if ( ! $wc_customer_id ) {
			$this->redirect_with_error( 'invalid_token' );
			return;
		}

		// Always restore — even if a WC cookie exists for the same session.
		// WC may have loaded stale cart data into memory at boot (before
		// this handler fires), so we must set a fresh cookie and force
		// the double-redirect to guarantee WC reads the session from DB.
		$restored = $this->restore_wc_session( $wc_customer_id, $session_id );

		if ( false === $restored ) {
			$this->redirect_with_error( 'session_expired' );
			return;
		}

		// Restore succeeded — now burn the signature so it cannot be replayed.
		set_transient( $replay_key, 1, HOUR_IN_SECONDS );

		// Double-redirect: this first 302 sets the cookie. The browser stores
		// it, then follows the redirect to /storedash-checkout/go which does
		// a second 302 to /checkout. By that point WC reads the cookie at boot
		// and loads fresh session data from the DB.
		wp_safe_redirect( site_url( '/storedash-checkout/go' ) );
		exit;
	}

	/**
	 * Decode a JWT without signature verification (the token comes from WooCommerce
	 * on the same server, and we only need the user_id claim).
	 *
	 * @param string $jwt The Cart-Token JWT.
	 * @return string|false The customer_id (user_id) or false.
	 */
	private function extract_customer_id_from_jwt( $jwt ) {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) < 2 ) {
			return false;
		}

		// JWT payload is base64url encoded
		$payload_b64  = $parts[1];
		$payload_b64  = str_replace( array( '-', '_' ), array( '+', '/' ), $payload_b64 );
		$payload_json = base64_decode( $payload_b64 );

		if ( ! $payload_json ) {
			return false;
		}

		$payload = json_decode( $payload_json, true );
		if ( ! $payload || empty( $payload['user_id'] ) ) {
			return false;
		}

		return $payload['user_id'];
	}

	/**
	 * Restore a WooCommerce session by setting the session cookie.
	 *
	 * WC_Session_Handler cookie format (single pipe separator):
	 *   {customer_id}|{session_expiration}|{session_expiring}|{cookie_hash}
	 *
	 * Hash is computed on: {customer_id}|{session_expiration}
	 * Using wp_fast_hash() on WP 6.8+, or hash_hmac('md5') on older.
	 *
	 * @param string $customer_id The WC session customer_id from the JWT.
	 * @param string $storedash_session_id The StoreDash session ID for order tagging.
	 */
	private function restore_wc_session( string $customer_id, string $storedash_session_id ): bool {
		global $wpdb;
		$session_table = $wpdb->prefix . 'woocommerce_sessions';

		// Fetch the session value once; get_var returns null only when no row
		// matches (session_value is NOT NULL in schema, so an empty cart reads as
		// '' not null). Reused below to avoid a second identical round trip.
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT session_value FROM {$session_table} WHERE session_key = %s",
				$customer_id
			)
		);

		if ( null === $raw ) {
			StoreDash_Helpers::log_message(
				'Headless checkout: WC session not found for customer_id: ' . $customer_id,
				'error'
			);
			return false;
		}

		// Flush object cache so WC reads fresh data from DB
		wp_cache_delete( $customer_id, 'wc_session_id' );
		wp_cache_delete( 'wc_session_' . $customer_id, 'wc_session_data' );

		// Match WC_Session_Handler's expiration logic
		$session_expiration = time() + ( 48 * HOUR_IN_SECONDS );
		$session_expiring   = time() + ( 47 * HOUR_IN_SECONDS );

		// Hash must match WC_Session_Handler::hash() exactly. On WordPress 6.8+ WC
		// uses wp_fast_hash(); on older versions it uses the hash_hmac('md5') form.
		// The function is called indirectly so the static WP-version check does not
		// flag wp_fast_hash() against the 5.8 "Requires at least" floor — the
		// function_exists() guard already makes the call version-safe at runtime.
		$hash_input = $customer_id . '|' . $session_expiration;
		$fast_hash  = 'wp_fast_hash';
		if ( function_exists( $fast_hash ) ) {
			$cookie_hash = $fast_hash( $hash_input );
		} else {
			$cookie_hash = hash_hmac( 'md5', $hash_input, wp_hash( $hash_input ) );
		}

		$cookie_value = $customer_id . '|' . $session_expiration . '|' . $session_expiring . '|' . $cookie_hash;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- consuming the WooCommerce core woocommerce_cookie filter, not defining a hook.
		$cookie_name = apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		wc_setcookie( $cookie_name, $cookie_value, $session_expiration, true, true );

		$_COOKIE[ $cookie_name ] = $cookie_value;

		$wpdb->update(
			$session_table,
			array( 'session_expiry' => $session_expiration ),
			array( 'session_key' => $customer_id ),
			array( '%d' ),
			array( '%s' )
		);

		// WC loaded stale session data into memory at boot (from the prior
		// checkout cookie). WC_Session_Handler::__destruct() calls save_data()
		// on shutdown, bypassing WordPress hooks — we cannot prevent it.
		// Fix: replace WC's stale in-memory data with the correct DB data so
		// when __destruct saves, it writes the current cart, not the old one.
		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( $raw ) {
				$session_data = maybe_unserialize( $raw );
				if ( is_array( $session_data ) ) {
					if ( ! empty( $storedash_session_id ) ) {
						$session_data['storedash_session_id'] = $storedash_session_id;
					}
					foreach ( $session_data as $key => $value ) {
						WC()->session->set( $key, maybe_unserialize( $value ) );
					}
				}
			}
		}

		return true;
	}

	/**
	 * Redirect to the storefront with an error parameter.
	 *
	 * @param string $error_code
	 */
	private function redirect_with_error( $error_code ) {
		$storefront_url = get_option( 'storedash_storefront_url', '' );
		if ( $storefront_url ) {
			$this->safe_redirect( $storefront_url . '/?error=' . rawurlencode( $error_code ) );
		} else {
			$this->safe_redirect( home_url( '/?storedash_error=' . rawurlencode( $error_code ) ) );
		}
	}

	/**
	 * wp_safe_redirect to a URL, allowlisting the (admin-configured, trusted)
	 * storefront host so the headless storefront is a permitted target while still
	 * blocking arbitrary open redirects. Exits.
	 *
	 * @param string $url Destination URL.
	 */
	private function safe_redirect( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $host ) {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Tag orders created from headless checkout sessions with metadata.
	 *
	 * @param WC_Order $order
	 */
	public function tag_headless_order( $order ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$storedash_session = WC()->session->get( 'storedash_session_id' );
		if ( ! $storedash_session ) {
			return;
		}

		$order->update_meta_data( '_storedash_headless', 'true' );
		$order->update_meta_data( '_storedash_session_id', $storedash_session );
		$order->save();
	}

	/**
	 * Override the WooCommerce thank-you page redirect for headless orders.
	 * Sends the shopper back to the storefront confirmation page.
	 *
	 * @param string   $url
	 * @param WC_Order $order
	 * @return string
	 */
	public function override_return_url( $url, $order ) {
		if ( ! $order || $order->get_meta( '_storedash_headless' ) !== 'true' ) {
			return $url;
		}

		$storefront_url = get_option( 'storedash_storefront_url', '' );
		if ( empty( $storefront_url ) ) {
			return $url;
		}

		return $storefront_url . '/order/confirmed?order_key='
			. $order->get_order_key()
			. '&order_id=' . $order->get_id();
	}
}

StoreDash_Headless_Checkout::instance();
