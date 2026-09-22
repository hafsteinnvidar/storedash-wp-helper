<?php

namespace StoreDash\Carts\Services;

use StoreDash\Carts\Services\Cart_Tracking;
use StoreDash\Carts\Services\Cart_Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart recovery functionality
 */
class Cart_Recovery {

	/**
	 * Instance
	 *
	 * @var Cart_Recovery
	 */
	private static $instance;

	/**
	 * Get instance
	 *
	 * @return Cart_Recovery
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
		add_action( 'rest_api_init', array( $this, 'register_recovery_route' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'maybe_apply_cart_recovery_coupon' ), 11 );

		// Coupon features
		add_action( 'wp_loaded', array( $this, 'add_coupon_code_to_cart_session' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'add_coupon_code_to_cart' ) );
	}

	/**
	 * Register REST API route for cart recovery
	 */
	public function register_recovery_route() {
		register_rest_route(
			'storedash/v1',
			'/recover-cart',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'recover_cart_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Recovery cart callback
	 *
	 * @param \WP_REST_Request $request
	 */
	public function recover_cart_callback( $request ) {
		// Rate limiting — DB-backed transient so the per-IP counter survives across
		// requests (the object cache group used previously was non-persistent).
		$ip       = \StoreDash_Helpers::get_client_ip();
		$rate_key = 'cart_recovery_' . md5( $ip );
		$attempts = (int) get_transient( $rate_key );

		if ( $attempts >= 10 ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Too many recovery attempts. Please try again later.', 'storedash' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );

		$cart_token = $request->get_param( 'token' );
		if ( empty( $cart_token ) ) {
			return new \WP_Error( 'missing_token', 'Cart token is required', array( 'status' => 400 ) );
		}

		// Initialize prerequisites
		$this->check_prerequisites();

		// Set checkout URL
		$checkout_url = $this->get_checkout_url();

		// Forward allowed params
		foreach ( $request->get_params() as $key => $val ) {
			$allowed_key_prefixes = array_merge(
				array( 'utm_', 'mtk', 'lang' ),
				apply_filters( 'woodash_cart_recovery_allowed_url_params', array() )
			);

			foreach ( $allowed_key_prefixes as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$checkout_url = add_query_arg( $key, $val, $checkout_url );
				}
			}
		}

		// Start session if needed
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		// Try to restore cart
		try {
			$this->restore_cart( $cart_token );

			// Check for coupon in recovery URL
			if ( $coupon = $request->get_param( 'coupon' ) ) {
				$checkout_url = add_query_arg( array( 'coupon' => wc_clean( $coupon ) ), $checkout_url );
			}
		} catch ( \Exception $e ) {
			\StoreDash_Helpers::log_message(
				'Cart recovery error',
				'error',
				array(
					'error' => $e->getMessage(),
					'token' => $cart_token,
				)
			);
			wc_add_notice( __( 'Sorry, we were not able to restore your cart. Please try adding your items to your cart again.', 'storedash' ), 'error' );
		}

		// Redirect to checkout
		wp_safe_redirect( $checkout_url );
		exit;
	}

	/**
	 * Check prerequisites for cart recovery
	 */
	protected function check_prerequisites() {
		if ( defined( 'WC_ABSPATH' ) ) {
			include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
			include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
		}

		if ( null === WC()->session ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- consuming the WooCommerce core woocommerce_session_handler filter.
			$session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );

			if ( false === strpos( $session_class, '\\' ) ) {
				$session_class = '\\' . $session_class;
			}

			WC()->session = new $session_class();
			WC()->session->init();
		}

		if ( null === WC()->customer ) {
			WC()->customer = new \WC_Customer( get_current_user_id(), true );
		}

		if ( null === WC()->cart ) {
			WC()->cart = new \WC_Cart();
			WC()->cart->get_cart();
		}
	}

	/**
	 * Get checkout URL
	 *
	 * @return string
	 */
	protected function get_checkout_url() {
		// Default to cart URL so user can see their restored cart
		$checkout_url = wc_get_cart_url();

		// If store prefers direct to checkout, use checkout URL
		if ( get_option( 'woodash_direct_to_checkout', false ) ) {
			$checkout_url = wc_get_checkout_url();
		}

		// Override via settings
		$override_url = get_option( 'woodash_checkout_url' );
		if ( ! empty( $override_url ) ) {
			$checkout_url = $override_url;
		}

		return apply_filters( 'woodash_recover_cart_url', $checkout_url );
	}

	/**
	 * Restore cart from token
	 *
	 * @param string $cart_token
	 * @throws \Exception
	 */
	protected function restore_cart( $cart_token ) {
		global $wpdb;
		$table_name = storedash_get_cart_table_name();

		// Get cart from database
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$cart_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE cart_token = %s",
				$cart_token
			)
		);

		if ( ! $cart_data ) {
			throw new \Exception( 'Cart not found' );
		}

		$cart_contents = json_decode( $cart_data->cart_contents, true );
		if ( empty( $cart_contents ) ) {
			throw new \Exception( 'Cart is empty' );
		}

		// Log for debugging
		\StoreDash_Helpers::debug_log(
			'Cart recovery: Starting restoration',
			array(
				'token'       => $cart_token,
				'items_count' => count( $cart_contents ),
			)
		);

		// Restore cart without triggering sync
		Cart_Tracking::without_cart_tracking(
			function () use ( $cart_contents, $cart_data, $cart_token ) {
				// Empty current cart
				WC()->cart->empty_cart();

				// Restore cart items using WooCommerce's proper method
				$restored_count = 0;
				// Cart contents is an associative array with cart keys, we need to handle that
				foreach ( $cart_contents as $cart_item_key => $cart_item ) {
					try {
						// Check if we have the required data
						if ( ! isset( $cart_item['product_id'] ) ) {
							\StoreDash_Helpers::debug_log( 'Cart recovery: Missing product_id in cart item', array( 'cart_item' => $cart_item ) );
							continue;
						}

						$product_id   = $cart_item['product_id'];
						$quantity     = isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1;
						$variation_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0;
						$variation    = ! empty( $cart_item['variation'] ) ? $cart_item['variation'] : array();

						// Check if product exists
						$product = wc_get_product( $variation_id ? $variation_id : $product_id );
						if ( ! $product ) {
							\StoreDash_Helpers::debug_log( 'Cart recovery: Product not found', array( 'product_id' => $product_id ) );
							continue;
						}

						// Check if product is purchasable
						if ( ! $product->is_purchasable() ) {
							\StoreDash_Helpers::debug_log( 'Cart recovery: Product not purchasable', array( 'product_id' => $product_id ) );
							continue;
						}

						// Check stock
						if ( ! $product->is_in_stock() ) {
							\StoreDash_Helpers::debug_log( 'Cart recovery: Product out of stock', array( 'product_id' => $product_id ) );
							continue;
						}

						// Add to cart using WooCommerce's method
						$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );

						if ( $cart_item_key ) {
							$restored_count++;
							\StoreDash_Helpers::debug_log(
								'Cart recovery: Successfully added product',
								array(
									'product_id' => $product_id,
									'quantity'   => $quantity,
								)
							);
						} else {
							\StoreDash_Helpers::debug_log( 'Cart recovery: Failed to add product', array( 'product_id' => $product_id ) );
						}
					} catch ( \Exception $e ) {
						\StoreDash_Helpers::log_message(
							'Cart recovery: Exception adding item',
							'error',
							array(
								'error'     => $e->getMessage(),
								'cart_item' => $cart_item,
							)
						);
					}
				}

				\StoreDash_Helpers::debug_log( 'Cart recovery: Restored items to cart', array( 'count' => $restored_count ) );

				// Important: Calculate totals to ensure cart is valid
				WC()->cart->calculate_totals();

				// Set cart token and recovery status
				WC()->session->set( 'woodash_cart_token', $cart_token );
				WC()->session->set( 'woodash_pending_recovery', true );

				// Set user meta if cart has user
				if ( $cart_data->customer_id ) {
					update_user_meta( $cart_data->customer_id, '_woodash_cart_token', $cart_token );
					update_user_meta( $cart_data->customer_id, '_woodash_pending_recovery', true );
				}

				// Restore customer data
				if ( ! empty( $cart_data->email ) ) {
					WC()->customer->set_email( sanitize_email( $cart_data->email ) );
					WC()->customer->set_billing_email( sanitize_email( $cart_data->email ) );
				}

				if ( ! empty( $cart_data->name ) ) {
					$names      = explode( ' ', $cart_data->name );
					$first_name = array_shift( $names );
					$last_name  = implode( ' ', $names );

					WC()->customer->set_first_name( sanitize_text_field( $first_name ) );
					WC()->customer->set_billing_first_name( sanitize_text_field( $first_name ) );

					if ( $last_name ) {
						WC()->customer->set_last_name( sanitize_text_field( $last_name ) );
						WC()->customer->set_billing_last_name( sanitize_text_field( $last_name ) );
					}
				}

				if ( ! empty( $cart_data->phone ) ) {
					WC()->customer->set_billing_phone( sanitize_text_field( $cart_data->phone ) );
				}

				WC()->customer->save();

				// Restore client session
				$client_session = json_decode( $cart_data->client_session, true );
				if ( $client_session ) {
					if ( isset( $client_session['applied_coupons'] ) ) {
						$applied_coupons = (array) $client_session['applied_coupons'];
						WC()->session->set( 'applied_coupons', $this->valid_coupons( $applied_coupons ) );
					}

					if ( isset( $client_session['chosen_shipping_methods'] ) ) {
						WC()->session->set( 'chosen_shipping_methods', (array) $client_session['chosen_shipping_methods'] );
					}

					if ( isset( $client_session['shipping_method_counts'] ) ) {
						WC()->session->set( 'shipping_method_counts', (array) $client_session['shipping_method_counts'] );
					}

					if ( isset( $client_session['chosen_payment_method'] ) ) {
						WC()->session->set( 'chosen_payment_method', $client_session['chosen_payment_method'] );
					}
				}

				// Log final cart state
				\StoreDash_Helpers::debug_log(
					'Cart recovery: Final cart state',
					array(
						'cart_count' => WC()->cart->get_cart_contents_count(),
						'cart_total' => WC()->cart->get_total( 'edit' ),
					)
				);
			}
		);

		// Update recovery status in database
		$wpdb->update(
			$table_name,
			array( 'recovery_status' => 'pending' ),
			array( 'cart_token' => $cart_token )
		);
	}

	/**
	 * Check valid coupons
	 *
	 * @param array $coupons
	 * @return array
	 */
	protected function valid_coupons( $coupons = array() ) {
		$valid_coupons = array();
		if ( empty( $coupons ) ) {
			return $valid_coupons;
		}

		$discounts = new \WC_Discounts( WC()->cart );
		foreach ( $coupons as $coupon_code ) {
			$coupon = new \WC_Coupon( $coupon_code );
			$valid  = $discounts->is_coupon_valid( $coupon );

			if ( ! is_wp_error( $valid ) ) {
				$valid_coupons[] = $coupon_code;
			}
		}

		return $valid_coupons;
	}

	/**
	 * Maybe apply recovery coupon
	 */
	public function maybe_apply_cart_recovery_coupon() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public cart recovery URL from email link
		if ( Cart_Data::cart_is_pending_recovery() && ! empty( $_REQUEST['coupon'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public cart recovery URL from email link; coupon is unslashed and sanitized via wc_clean(), but the intervening rawurldecode() hides the sanitizer from the sniff.
			$coupon_code = isset( $_REQUEST['coupon'] ) ? wc_clean( rawurldecode( wp_unslash( $_REQUEST['coupon'] ) ) ) : '';

			if ( $coupon_code && WC()->cart && ! WC()->cart->has_discount( $coupon_code ) ) {
				WC()->cart->calculate_totals();
				WC()->cart->add_discount( $coupon_code );
			}
		}
	}

	/**
	 * Add coupon code to cart session
	 */
	public function add_coupon_code_to_cart_session() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public coupon URL parameter
		if ( empty( $_GET['wdc'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public coupon URL parameter
		$coupon_code = isset( $_GET['wdc'] ) ? sanitize_text_field( wp_unslash( $_GET['wdc'] ) ) : '';
		if ( ! $coupon_code ) {
			return;
		}

		$this->check_prerequisites();

		// Start session if needed
		if ( ! WC()->session || ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		// Set coupon in session
		WC()->session->set( 'woodash_coupon', $coupon_code );

		// Apply to existing cart
		if ( WC()->cart && ! WC()->cart->is_empty() && ! WC()->cart->has_discount( $coupon_code ) ) {
			WC()->cart->calculate_totals();
			WC()->cart->add_discount( $coupon_code );

			// Remove from session
			WC()->session->__unset( 'woodash_coupon' );
		}
	}

	/**
	 * Add coupon code to cart
	 */
	public function add_coupon_code_to_cart() {
		$coupon_code = WC()->session ? WC()->session->get( 'woodash_coupon' ) : false;

		if ( ! $coupon_code || empty( $coupon_code ) ) {
			return;
		}

		if ( WC()->cart && ! WC()->cart->has_discount( $coupon_code ) ) {
			WC()->cart->calculate_totals();
			WC()->cart->add_discount( $coupon_code );

			// Remove from session
			WC()->session->__unset( 'woodash_coupon' );
		}
	}
}

// Initialize
Cart_Recovery::instance();
