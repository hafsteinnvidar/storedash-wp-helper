<?php
/**
 * StoreDash headless customer authentication bridge.
 *
 * Resolves the X-StoreDash-Customer header into the current WordPress user for
 * WooCommerce Store API requests only, so a headless storefront's cart and
 * checkout calls run as the logged-in customer. Without this, every order placed
 * through the storefront is a guest order (customer_id = 0) and the customer's
 * order history stays permanently empty — the Store API has no login of its own.
 *
 * Deliberately SEPARATE from StoreDash_Auth_Handler: that one resolves a
 * WooCommerce consumer key to a manage_woocommerce user for the storedash/v1
 * routes. Keeping the two apart means a stolen customer token can never reach a
 * privileged route, and a bug in one cannot widen the other.
 *
 * Scope guarantees:
 * - Only fires on /wc/store/v1/ REST routes and the customer-facing
 *   storedash/v1/credit/me route (rewards credit balance for the token's user).
 * - Only resolves users whose every role is in the customer allowlist.
 * - Never elevates an already-authenticated request.
 *
 * @package StoreDash
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Customer_Auth_Bridge {

	/**
	 * Request header carrying the customer token.
	 */
	const HEADER = 'HTTP_X_STOREDASH_CUSTOMER';

	/**
	 * Singleton instance.
	 *
	 * @var StoreDash_Customer_Auth_Bridge|null
	 */
	private static $instance = null;

	/**
	 * Request-scoped memo of the resolved user, so repeated
	 * determine_current_user passes cost one query per request.
	 *
	 * @var int|false|null
	 */
	private static $resolved = null;

	/**
	 * The only Storedash route a customer token may reach: its own rewards
	 * credit balance. Everything else a token can touch is the WooCommerce
	 * Store API (`/wc/store/`).
	 */
	const CREDIT_ME_ROUTE = '/storedash/v1/credit/me';

	/**
	 * Get singleton instance.
	 *
	 * @return StoreDash_Customer_Auth_Bridge
	 */
	public static function instance(): StoreDash_Customer_Auth_Bridge {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Priority 30: after StoreDash_Auth_Handler (20), so consumer-key auth
		// always wins and this only ever sees requests it did not claim.
		add_filter( 'determine_current_user', array( $this, 'authenticate_customer' ), 30 );

		// Second lock on the route scope, evaluated on the route the REST server
		// actually dispatches (security audit 2026-10-13 #42).
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_route_scope' ), 1, 3 );
	}

	/**
	 * Refuse to carry a customer-token identity into a route the token was
	 * never meant for. Only acts when this bridge resolved the user.
	 *
	 * @since 1.24.1
	 *
	 * @param mixed           $result  Dispatch short-circuit value (null = continue).
	 * @param WP_REST_Server  $server  REST server (unused).
	 * @param WP_REST_Request $request The request being dispatched.
	 * @return mixed WP_Error 403 when the scope is violated, otherwise $result.
	 */
	public function guard_route_scope( $result, $server, $request ) {
		if ( ! self::$resolved || null !== $result ) {
			return $result;
		}

		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( self::route_allowed( $route ) ) {
			return $result;
		}

		return new WP_Error(
			'storedash_customer_token_forbidden',
			__( 'Customer tokens are only accepted on the Store API.', 'storedash' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether a REST route is one a customer token may authenticate.
	 *
	 * @param string $route Route with a leading slash.
	 * @return bool
	 */
	private static function route_allowed( string $route ): bool {
		if ( StoreDash_REST_Route::in_namespaces( $route, array( 'wc/store' ) ) ) {
			return true;
		}

		return self::CREDIT_ME_ROUTE === $route || 0 === strpos( $route, self::CREDIT_ME_ROUTE . '/' );
	}

	/**
	 * Resolve the customer token into a user ID.
	 *
	 * @param int|false $user_id Current user ID, or false when unauthenticated.
	 * @return int|false
	 */
	public function authenticate_customer( $user_id ) {
		static $authenticating = false;

		// Never override an existing authentication (cookie, consumer key, ...).
		if ( $user_id ) {
			return $user_id;
		}

		/*
		 * Re-entrancy guard. Anything we call below may invoke
		 * wp_get_current_user() (directly, or via a third-party filter), which
		 * re-fires determine_current_user because the current user is not resolved
		 * yet — unbounded recursion that exhausts PHP memory. This plugin has
		 * already been taken down this way once (see StoreDash_Auth_Handler).
		 * Inside the guarded window the request is simply anonymous.
		 */
		if ( $authenticating ) {
			return $user_id;
		}

		/*
		 * Deliberately NOT gated on the REST_REQUEST constant, unlike
		 * StoreDash_Auth_Handler.
		 *
		 * REST_REQUEST is defined in rest_api_loaded(), which runs on
		 * 'parse_request' — AFTER 'init'. WooCommerce initializes its session on
		 * 'init' priority 0, and WC_Session_Handler::init_session_cookie() calls
		 * is_user_logged_in() to decide whether to load the customer's session or
		 * mint a guest one. That call is usually the first wp_get_current_user()
		 * of the request, and its result is cached in the $current_user global for
		 * everything that follows.
		 *
		 * So a REST_REQUEST check here would make us bail at exactly the moment
		 * WooCommerce asks who the customer is: WC would build a guest session,
		 * cache user 0, and our later authentication would come too late to affect
		 * the cart — the storefront would appear logged in while still placing
		 * guest orders. is_store_api_request() reads REQUEST_URI, which is
		 * available at any point in the request, so it is the correct gate.
		 */
		if ( ! $this->is_store_api_request() ) {
			return $user_id;
		}

		if ( null !== self::$resolved ) {
			return self::$resolved ? self::$resolved : $user_id;
		}

		$token = $this->get_token_from_header();
		if ( '' === $token ) {
			self::$resolved = false;
			return $user_id;
		}

		$authenticating = true;
		try {
			$resolved_id = StoreDash_Customer_Tokens::resolve( $token );

			if ( $resolved_id ) {
				$user = get_user_by( 'id', $resolved_id );

				/*
				 * Re-check the role on every request, not just at mint time: a user
				 * promoted to shop_manager after their token was issued must stop
				 * being able to use it. user_is_eligible() reads $user->roles
				 * directly and never calls user_can() — see the note on
				 * StoreDash_Customer_Tokens::roles_are_eligible().
				 */
				if ( ! $user || ! StoreDash_Customer_Tokens::user_is_eligible( $user ) ) {
					$resolved_id = false;
				}
			}

			self::$resolved = $resolved_id ? (int) $resolved_id : false;
		} finally {
			$authenticating = false;
		}

		return self::$resolved ? self::$resolved : $user_id;
	}

	/**
	 * Whether the current request targets the WooCommerce Store API (or the
	 * customer's own credit balance route).
	 *
	 * Decided on the route WordPress will dispatch (StoreDash_REST_Route — it
	 * works before parse_request too, because the init-time call above has
	 * only REQUEST_URI / ?rest_route= to go on). The query string is never
	 * searched: `?x=/wp-json/wc/store/` on a wp/v2 request must not log a
	 * token in (security audit 2026-10-13 #42).
	 *
	 * @return bool
	 */
	private function is_store_api_request(): bool {
		$route = StoreDash_REST_Route::current();
		if ( '' === $route ) {
			return false;
		}

		// The credit route's permission_callback is is_user_logged_in();
		// consumer-key auth (priority 20) still wins when present.
		return self::route_allowed( $route );
	}

	/**
	 * Read and sanitize the customer token header.
	 *
	 * @return string Empty string when absent or malformed.
	 */
	private function get_token_from_header(): string {
		$raw = '';

		if ( isset( $_SERVER[ self::HEADER ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line.
			$raw = sanitize_text_field( wp_unslash( $_SERVER[ self::HEADER ] ) );
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			foreach ( $headers as $name => $value ) {
				if ( 0 === strcasecmp( $name, 'X-StoreDash-Customer' ) ) {
					$raw = sanitize_text_field( $value );
					break;
				}
			}
		}

		// Tokens are wp_generate_password( 64, false ) — alphanumerics and a fixed
		// punctuation set. Reject anything else outright rather than sending
		// unexpected input to the hash/lookup.
		if ( '' === $raw || strlen( $raw ) > 128 ) {
			return '';
		}

		return $raw;
	}
}
