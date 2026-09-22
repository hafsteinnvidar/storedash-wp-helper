<?php
/**
 * StoreDash Customer Auth API
 *
 * Password login, registration and password recovery for headless storefronts.
 * Called server-to-server by the storefront's Next.js server, never by a browser.
 *
 * Why these routes are NOT public: the storefront already authenticates to
 * storedash/v1 with a WooCommerce consumer key (as the checkout handoff does),
 * so the shopper's email and password arrive as parameters on an already-
 * authenticated request. That keeps the plugin's rule intact — /ping and
 * /recover-cart remain the only unauthenticated routes — and means no login
 * surface is exposed on the WordPress origin at all.
 *
 * @package StoreDash
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_API_Customer_Auth {

	/**
	 * Singleton instance.
	 *
	 * @var StoreDash_API_Customer_Auth|null
	 */
	private static $instance = null;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = 'storedash/v1';

	/**
	 * Rate limits: array( attempts, window_seconds ).
	 */
	const LIMIT_LOGIN_IP       = array( 30, 300 );
	const LIMIT_LOGIN_IDENT    = array( 10, 300 );
	const LIMIT_REGISTER_IP    = array( 10, 3600 );
	const LIMIT_LOSTPASS_IP    = array( 10, 3600 );
	const LIMIT_LOSTPASS_IDENT = array( 3, 3600 );

	/**
	 * Get singleton instance.
	 *
	 * @return StoreDash_API_Customer_Auth
	 */
	public static function instance(): StoreDash_API_Customer_Auth {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Permission callback shared by every route here.
	 *
	 * The caller is the storefront server holding a consumer key, exactly like
	 * every other authenticated storedash/v1 route.
	 *
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permission_check' );

		register_rest_route(
			$this->namespace,
			'/customer-auth/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'login' ),
				'permission_callback' => $permission,
				'args'                => array(
					'email'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'password'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'cart_token' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/customer-auth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'register_customer' ),
				'permission_callback' => $permission,
				'args'                => array(
					'email'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'password'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'first_name' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'last_name'  => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/customer-auth/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => $permission,
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/customer-auth/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => $permission,
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/customer-auth/lost-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lost_password' ),
				'permission_callback' => $permission,
				'args'                => array(
					'email' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/customer-auth/reset-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_password' ),
				'permission_callback' => $permission,
				'args'                => array(
					'key'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'login'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Authenticate an email/password pair and issue a token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( $request ) {
		$email    = (string) $request->get_param( 'email' );
		$password = (string) $request->get_param( 'password' );

		$limited = $this->check_rate_limit( 'login', $email, self::LIMIT_LOGIN_IP, self::LIMIT_LOGIN_IDENT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( '' === $email || '' === $password ) {
			return $this->invalid_credentials();
		}

		/*
		 * wp_authenticate() rather than wp_check_password(): it runs the full
		 * 'authenticate' filter chain and fires 'wp_login_failed', so existing
		 * brute-force/lockout plugins (Wordfence et al.) keep seeing storefront
		 * login attempts exactly as they see wp-login.php ones.
		 */
		$user = wp_authenticate( $email, $password );

		if ( is_wp_error( $user ) ) {
			// Deliberately generic: never reveal whether the account exists.
			return $this->invalid_credentials();
		}

		if ( ! StoreDash_Customer_Tokens::user_is_eligible( $user ) ) {
			// A staff/admin account. Same generic error — do not confirm that this
			// address belongs to a privileged user.
			return $this->invalid_credentials();
		}

		return $this->issue_session( $user, (string) $request->get_param( 'cart_token' ) );
	}

	/**
	 * Create a customer account and log it straight in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_customer( $request ) {
		$email = (string) $request->get_param( 'email' );

		$limited = $this->check_rate_limit( 'register', '', self::LIMIT_REGISTER_IP, null );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			return new WP_Error( 'storedash_wc_missing', __( 'WooCommerce is not available.', 'storedash' ), array( 'status' => 500 ) );
		}

		$args  = array();
		$first = (string) $request->get_param( 'first_name' );
		$last  = (string) $request->get_param( 'last_name' );
		if ( '' !== $first ) {
			$args['first_name'] = $first;
		}
		if ( '' !== $last ) {
			$args['last_name'] = $last;
		}

		$user_id = wc_create_new_customer( $email, '', (string) $request->get_param( 'password' ), $args );

		if ( is_wp_error( $user_id ) ) {
			// WooCommerce's own messages are already customer-safe and localized
			// (e.g. "An account is already registered with ..."), so pass them
			// through for the storefront to display.
			return new WP_Error(
				$user_id->get_error_code(),
				$user_id->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// Attach any past guest orders placed with this email to the new account,
		// so order history is populated from day one.
		if ( function_exists( 'wc_update_new_customer_past_orders' ) ) {
			wc_update_new_customer_past_orders( $user_id );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'storedash_register_failed', __( 'Could not create account.', 'storedash' ), array( 'status' => 500 ) );
		}

		return $this->issue_session( $user, (string) $request->get_param( 'cart_token' ) );
	}

	/**
	 * Resolve a token to its customer profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate( $request ) {
		$user_id = StoreDash_Customer_Tokens::resolve( (string) $request->get_param( 'token' ) );

		if ( ! $user_id ) {
			return new WP_Error( 'storedash_invalid_token', __( 'Session expired.', 'storedash' ), array( 'status' => 401 ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! StoreDash_Customer_Tokens::user_is_eligible( $user ) ) {
			return new WP_Error( 'storedash_invalid_token', __( 'Session expired.', 'storedash' ), array( 'status' => 401 ) );
		}

		return rest_ensure_response( array( 'customer' => $this->customer_payload( $user ) ) );
	}

	/**
	 * Revoke a token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function logout( $request ) {
		StoreDash_Customer_Tokens::revoke( (string) $request->get_param( 'token' ) );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Send a password reset email.
	 *
	 * Always reports success: a differing response for known vs unknown addresses
	 * turns this into an account-enumeration oracle.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function lost_password( $request ) {
		$email = (string) $request->get_param( 'email' );

		$limited = $this->check_rate_limit( 'lostpass', $email, self::LIMIT_LOSTPASS_IP, self::LIMIT_LOSTPASS_IDENT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( '' !== $email ) {
			// retrieve_password() sends the mail itself and returns true|WP_Error;
			// the result is intentionally discarded.
			$result = retrieve_password( $email );

			if ( is_wp_error( $result ) ) {
				StoreDash_Helpers::debug_log(
					'Customer lost-password request failed',
					array( 'code' => $result->get_error_code() )
				);
			}
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Complete a password reset with the emailed key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_password( $request ) {
		$key      = (string) $request->get_param( 'key' );
		$login    = (string) $request->get_param( 'login' );
		$password = (string) $request->get_param( 'password' );

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'storedash_invalid_reset_key',
				__( 'This password reset link has expired. Please request a new one.', 'storedash' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $password ) {
			return new WP_Error( 'storedash_missing_password', __( 'Please choose a password.', 'storedash' ), array( 'status' => 400 ) );
		}

		// Fires after_password_reset, which revokes every existing token for this
		// user (see StoreDash_Customer_Tokens::register_hooks).
		reset_password( $user, $password );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Mint a token and build the login response.
	 *
	 * @param WP_User $user       Authenticated customer.
	 * @param string  $cart_token Guest Cart-Token to merge from, if any.
	 * @return WP_REST_Response|WP_Error
	 */
	private function issue_session( $user, string $cart_token ) {
		$session = StoreDash_Customer_Tokens::mint( (int) $user->ID );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$this->flag_cart_merge( (int) $user->ID, $cart_token );

		return rest_ensure_response(
			array(
				'token'      => $session['token'],
				'expires_at' => $session['expires_at'],
				'customer'   => $this->customer_payload( $user ),
			)
		);
	}

	/**
	 * Ask WooCommerce to merge the saved cart on the next authenticated request.
	 *
	 * WC_Cart_Session::get_cart_from_session() checks the
	 * _woocommerce_load_saved_cart_after_login user meta and, when set, merges the
	 * persistent cart into the session cart instead of replacing it. Setting the
	 * flag here means the shopper's guest cart and their saved cart combine on the
	 * first cart request after login, using WooCommerce's own merge logic rather
	 * than a hand-rolled re-add loop.
	 *
	 * @param int    $user_id    Customer.
	 * @param string $cart_token Guest Cart-Token (currently informational only).
	 * @return void
	 */
	private function flag_cart_merge( int $user_id, string $cart_token ): void {
		update_user_meta( $user_id, '_woocommerce_load_saved_cart_after_login', 1 );

		if ( '' !== $cart_token ) {
			StoreDash_Helpers::debug_log(
				'Customer login flagged cart merge',
				array( 'user_id' => $user_id )
			);
		}
	}

	/**
	 * Public-safe customer fields for the storefront.
	 *
	 * @param WP_User $user User.
	 * @return array<string,mixed>
	 */
	private function customer_payload( $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'email'        => $user->user_email,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'display_name' => $user->display_name,
		);
	}

	/**
	 * The single credential-failure response.
	 *
	 * @return WP_Error
	 */
	private function invalid_credentials(): WP_Error {
		return new WP_Error(
			'storedash_invalid_credentials',
			__( 'Incorrect email address or password.', 'storedash' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Per-IP and per-identifier rate limiting.
	 *
	 * The per-identifier counter is the one that matters: a distributed
	 * credential-stuffing run against a single account defeats a per-IP limit
	 * entirely. DB-backed transients, matching Cart_Recovery.
	 *
	 * @param string     $bucket     Namespace for the counters.
	 * @param string     $identifier Email/username being targeted; '' to skip that counter.
	 * @param array{0:int,1:int} $ip_limit    Attempts and window for the IP counter.
	 * @param array{0:int,1:int}|null $ident_limit Attempts and window for the identifier counter.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( string $bucket, string $identifier, array $ip_limit, ?array $ident_limit ) {
		$ip = $this->get_shopper_ip();

		// Only rate-limit by IP when we actually know the shopper's IP. Without
		// the forwarded header every request carries the storefront server's
		// address, so a single counter would be shared by the entire store —
		// one busy minute would lock out every customer at once. The
		// per-identifier limit below is the meaningful protection either way.
		if ( '' !== $ip && ! $this->bump_counter( 'sd_auth2_' . $bucket . '_ip_' . md5( $ip ), $ip_limit[0], $ip_limit[1] ) ) {
			return $this->rate_limited();
		}

		if ( null !== $ident_limit && '' !== $identifier ) {
			$key = 'sd_auth2_' . $bucket . '_id_' . md5( strtolower( $identifier ) );
			if ( ! $this->bump_counter( $key, $ident_limit[0], $ident_limit[1] ) ) {
				return $this->rate_limited();
			}
		}

		return true;
	}

	/**
	 * The end shopper's IP address, as reported by the storefront.
	 *
	 * These routes are always called server-to-server, so PHP's own view of the
	 * client is the storefront server. The storefront forwards the real visitor
	 * IP in X-StoreDash-Client-IP; trusting it is safe here because the caller
	 * has already authenticated with a manage_woocommerce consumer key, and the
	 * header is only ever used to bucket rate limits — never for authorization.
	 *
	 * @return string Validated IP, or '' when absent/malformed.
	 */
	private function get_shopper_ip(): string {
		if ( empty( $_SERVER['HTTP_X_STOREDASH_CLIENT_IP'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed and validated below.
		$raw = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_STOREDASH_CLIENT_IP'] ) );

		$ip = filter_var( $raw, FILTER_VALIDATE_IP );

		return $ip ? $ip : '';
	}

	/**
	 * Increment a transient counter, reporting whether the request may proceed.
	 *
	 * @param string $key    Transient key.
	 * @param int    $max    Maximum attempts in the window.
	 * @param int    $window Window length in seconds.
	 * @return bool False when the limit is already reached.
	 */
	private function bump_counter( string $key, int $max, int $window ): bool {
		$attempts = (int) get_transient( $key );

		if ( $attempts >= $max ) {
			return false;
		}

		set_transient( $key, $attempts + 1, $window );

		return true;
	}

	/**
	 * The rate-limit response.
	 *
	 * @return WP_Error
	 */
	private function rate_limited(): WP_Error {
		return new WP_Error(
			'storedash_rate_limited',
			__( 'Too many attempts. Please try again shortly.', 'storedash' ),
			array( 'status' => 429 )
		);
	}
}

StoreDash_API_Customer_Auth::instance();
