<?php

namespace StoreDash\Carts\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to handle cart data management
 */
class Cart_Data {

	/**
	 * Cart items
	 *
	 * @var array
	 */
	public $cart = array();

	/**
	 * Customer ID
	 *
	 * @var int|null
	 */
	public $customer_id = null;

	/**
	 * Customer email
	 *
	 * @var string|null
	 */
	public $email = null;

	/**
	 * Customer name
	 *
	 * @var string|null
	 */
	public $name = null;

	/**
	 * Customer phone
	 *
	 * @var string|null
	 */
	public $phone = null;

	/**
	 * Marketing opt-in status
	 *
	 * @var int
	 */
	public $marketing_optin = 0;

	/**
	 * Cart hash key for session storage
	 */
	const LAST_CART_HASH = 'woodash_last_cart_hash';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->cart = $this->get_enriched_cart_data();
		$this->determine_customer_data();
	}

	/**
	 * Get cart data with product information
	 *
	 * @return array
	 */
	protected function get_enriched_cart_data() {
		if ( ! isset( WC()->cart ) ) {
			return array();
		}

		$cart_data     = WC()->cart->get_cart();
		$enriched_cart = array();

		foreach ( $cart_data as $cart_item_key => $cart_item ) {
			// Get the product
			$product = $cart_item['data'];

			// Get product image
			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

			// Only store essential cart data needed for recovery
			$enriched_cart[] = array(
				'product_id'    => $cart_item['product_id'],
				'quantity'      => $cart_item['quantity'],
				'variation_id'  => isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0,
				'variation'     => isset( $cart_item['variation'] ) ? $cart_item['variation'] : array(),
				'key'           => $cart_item_key,
				'line_subtotal' => isset( $cart_item['line_subtotal'] ) ? $cart_item['line_subtotal'] : 0,
				'line_total'    => isset( $cart_item['line_total'] ) ? $cart_item['line_total'] : 0,
				// CI-98: storedash-sync parses and persists cart_items.line_tax but no
				// producer ever sent it, so the column was zero on every live row. Use
				// 'line_tax' (post-discount) and NOT 'line_subtotal_tax' (pre-discount):
				// the Go side stores the discounted 'line_total', so pairing it with the
				// pre-discount tax would persist inconsistent figures.
				'line_tax'      => isset( $cart_item['line_tax'] ) ? $cart_item['line_tax'] : 0,
				// Product display data for emails
				'data'          => array(
					'name'      => $product->get_name(),
					'image'     => $image_url,
					'permalink' => $product->get_permalink(),
					'sku'       => $product->get_sku(),
					'price'     => $product->get_price(),
				),
			);
		}

		return $enriched_cart;
	}

	/**
	 * Determine customer data from various sources
	 */
	protected function determine_customer_data() {
		$this->determine_customer_id();
		$this->determine_email();
		$this->determine_name();
		$this->determine_phone();
		$this->determine_marketing_optin();
	}

	/**
	 * Determine customer ID
	 */
	protected function determine_customer_id() {
		if ( is_user_logged_in() ) {
			$this->customer_id = get_current_user_id();
		} elseif ( ! empty( WC()->session ) &&
				! empty( WC()->session->get( 'customer' ) ) &&
				isset( WC()->session->get( 'customer' )['id'] ) &&
				absint( WC()->session->get( 'customer' )['id'] ) > 0 ) {
			$this->customer_id = absint( WC()->session->get( 'customer' )['id'] );
		}
	}

	/**
	 * Determine customer email
	 */
	protected function determine_email() {
		if ( ! empty( WC()->customer ) && is_email( WC()->customer->get_billing_email() ) ) {
			$this->email = sanitize_email( WC()->customer->get_billing_email() );
		} elseif ( ! empty( WC()->customer ) && is_email( WC()->customer->get_email() ) ) {
			$this->email = sanitize_email( WC()->customer->get_email() );
		} elseif ( is_user_logged_in() && is_email( wp_get_current_user()->user_email ) ) {
			$this->email = sanitize_email( wp_get_current_user()->user_email );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
		} elseif ( ! empty( $_POST['email'] ) && is_email( wp_unslash( $_POST['email'] ) ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
			$this->email = sanitize_email( wp_unslash( $_POST['email'] ) );
		}
	}

	/**
	 * Determine customer name
	 */
	protected function determine_name() {
		if ( ! empty( WC()->customer ) && ! empty( WC()->customer->get_billing_first_name() ) ) {
			$this->name = WC()->customer->get_billing_first_name();
			$last_name  = WC()->customer->get_billing_last_name();
			if ( $last_name ) {
				$this->name .= ' ' . $last_name;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
		} elseif ( ! empty( $_POST['first_name'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
			$this->name = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
			if ( ! empty( $_POST['last_name'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
				$this->name .= ' ' . sanitize_text_field( wp_unslash( $_POST['last_name'] ) );
			}
		} elseif ( is_user_logged_in() ) {
			$user       = wp_get_current_user();
			$this->name = $user->first_name;
			if ( $user->last_name ) {
				$this->name .= ' ' . $user->last_name;
			}
		}
	}

	/**
	 * Determine customer phone
	 */
	protected function determine_phone() {
		if ( ! empty( WC()->customer ) && ! empty( WC()->customer->get_billing_phone() ) ) {
			$this->phone = sanitize_text_field( WC()->customer->get_billing_phone() );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
		} elseif ( ! empty( $_POST['phone'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce already verified
			$this->phone = sanitize_text_field( wp_unslash( $_POST['phone'] ) );
		}
	}

	/**
	 * Convert cart data to array
	 *
	 * @return array
	 */
	public function to_array() {
		if ( $this->cart_is_empty() ) {
			return array(
				'cart_token' => $this->get_cart_token(),
				'cart'       => false,
			);
		}

		// Ensure we have latest customer data
		$this->determine_customer_data();

		return array(
			'cart_token'                   => $this->get_cart_token(),
			'cart'                         => $this->cart,
			'created_at'                   => current_time( 'mysql', true ),
			'total'                        => $this->get_cart_total(),
			'subtotal'                     => $this->get_cart_subtotal(),
			'total_tax'                    => $this->get_cart_tax(),
			'total_discount'               => $this->get_cart_discount(),
			'total_shipping'               => $this->get_cart_shipping(),
			'total_fee'                    => $this->get_cart_fee(),
			'currency'                     => get_woocommerce_currency(),
			'customer_id'                  => $this->customer_id,
			'email'                        => $this->email,
			'name'                         => $this->name,
			'phone'                        => $this->phone,
			'locale'                       => determine_locale(),
			'email_opt_out'                => $this->get_customer_email_opt_out(),
			'marketing_optin'              => $this->marketing_optin,
			'client_session'               => $this->get_client_session_data(),
			'display_prices_including_tax' => isset( WC()->cart ) && WC()->cart->display_prices_including_tax(),
		);
	}

	/**
	 * Check if cart is empty
	 *
	 * @return bool
	 */
	public function cart_is_empty() {
		// A missing cart object counts as empty — callers use this as an
		// early-exit guard before calling further WC()->cart methods.
		return ! isset( WC()->cart ) || WC()->cart->is_empty();
	}

	/**
	 * Get cart hash for comparison
	 *
	 * @return string
	 */
	public function get_hash() {
		$cart_data_for_hash = $this->to_array();
		if ( isset( $cart_data_for_hash['created_at'] ) ) {
			unset( $cart_data_for_hash['created_at'] );
		}

		return md5( wp_json_encode( $cart_data_for_hash ) );
	}

	/**
	 * Get last cart hash from session
	 *
	 * @return string|null
	 */
	public function get_last_hash() {
		return WC()->session ? WC()->session->get( self::LAST_CART_HASH ) : null;
	}

	/**
	 * Save current hash to session
	 */
	public function save_last_hash() {
		if ( WC()->session ) {
			WC()->session->set( self::LAST_CART_HASH, $this->get_hash() );
		}
	}

	/**
	 * Get or generate cart token
	 *
	 * @param int|bool $user_id
	 * @return string
	 */
	public function get_cart_token( $user_id = false ) {
		$user_id        = $user_id ?: get_current_user_id();
		$token          = null;
		$from_user_meta = false;

		if ( ! empty( $user_id ) ) {
			$token = get_user_meta( $user_id, '_woodash_cart_token', true );

			if ( ! empty( $token ) ) {
				$from_user_meta = true;
			}
		}

		if ( empty( $token ) && WC()->session ) {
			$token = WC()->session->get( 'woodash_cart_token' );
		}

		if ( empty( $token ) ) {
			$token = $this->generate_cart_token();
		}

		if ( ! empty( $user_id ) && ! $from_user_meta ) {
			update_user_meta( $user_id, '_woodash_cart_token', $token );
		}

		if ( WC()->session ) {
			WC()->session->set( 'woodash_cart_token', $token );
		}

		return $token;
	}

	/**
	 * Generate unique cart token
	 *
	 * @return string
	 */
	protected function generate_cart_token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return wp_generate_password( 32, false );
		}
	}

	/**
	 * Get customer email opt out status
	 *
	 * @param int|null $user_id
	 * @return bool
	 */
	public static function get_customer_email_opt_out( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$opt_out = false;

		if ( ! empty( $user_id ) ) {
			$opt_out = get_user_meta( $user_id, '_woodash_customer_email_opt_out', true );
		}

		if ( empty( $opt_out ) && WC()->session ) {
			$opt_out = WC()->session->get( 'woodash_customer_email_opt_out' );
		}

		return (bool) $opt_out;
	}

	/**
	 * Set customer email opt out
	 *
	 * @param bool $opt_out
	 * @return bool
	 */
	public static function set_customer_email_opt_out( $opt_out = true ) {
		if ( WC()->session ) {
			WC()->session->set( 'woodash_customer_email_opt_out', $opt_out );
		}

		if ( $user_id = get_current_user_id() ) {
			update_user_meta( $user_id, '_woodash_customer_email_opt_out', $opt_out );
		}

		return $opt_out;
	}

	/**
	 * Determine marketing opt-in status
	 */
	protected function determine_marketing_optin() {
		$this->marketing_optin = self::get_marketing_optin();
	}

	/**
	 * Get customer marketing opt-in status
	 *
	 * @param int|null $user_id
	 * @return int
	 */
	public static function get_marketing_optin( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$optin   = null;

		// First check user meta if logged in
		if ( ! empty( $user_id ) ) {
			$user_optin = get_user_meta( $user_id, '_woodash_marketing_optin', true );
			if ( $user_optin !== '' ) {
				$optin = (int) $user_optin;
			}
		}

		// If not found in user meta, check session
		if ( $optin === null && WC()->session ) {
			$session_optin = WC()->session->get( 'woodash_marketing_optin' );
			if ( $session_optin !== null ) {
				$optin = (int) $session_optin;
			}
		}

		// Default to 0 if not found anywhere
		return $optin !== null ? $optin : 0;
	}

	/**
	 * Set customer marketing opt-in
	 *
	 * @param int $optin
	 * @return int
	 */
	public static function set_marketing_optin( $optin = 0 ) {
		$optin = (int) $optin;

		if ( WC()->session ) {
			WC()->session->set( 'woodash_marketing_optin', $optin );
		}

		if ( $user_id = get_current_user_id() ) {
			update_user_meta( $user_id, '_woodash_marketing_optin', $optin );
		}

		return $optin;
	}

	/**
	 * Get cart total
	 *
	 * @return float
	 */
	protected function get_cart_total() {
		if ( ! isset( WC()->cart ) ) {
			return 0;
		}

		if ( is_checkout() || is_cart() || defined( 'WOOCOMMERCE_CHECKOUT' ) || defined( 'WOOCOMMERCE_CART' ) ) {
			return (float) WC()->cart->total;
		} else {
			return (float) WC()->cart->get_total( 'edit' );
		}
	}

	/**
	 * Get cart subtotal
	 *
	 * @return float
	 */
	protected function get_cart_subtotal() {
		if ( ! isset( WC()->cart ) ) {
			return 0;
		}

		if ( 'excl' === WC()->cart->get_tax_price_display_mode() ) {
			$subtotal = WC()->cart->get_subtotal();
		} else {
			$subtotal = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
		}

		return (float) $subtotal;
	}

	/**
	 * Get cart tax
	 *
	 * @return float
	 */
	protected function get_cart_tax() {
		if ( ! isset( WC()->cart ) ) {
			return 0;
		}

		return (float) WC()->cart->get_total_tax();
	}

	/**
	 * Get cart discount
	 *
	 * @return float
	 */
	protected function get_cart_discount() {
		if ( ! isset( WC()->cart ) ) {
			return 0;
		}

		$discount_total = WC()->cart->get_discount_total();
		$discount_tax   = WC()->cart->get_discount_tax();

		if ( 'excl' === WC()->cart->get_tax_price_display_mode() ) {
			return $discount_total;
		} else {
			return $discount_total + $discount_tax;
		}
	}

	/**
	 * Get cart shipping
	 *
	 * @return float
	 */
	protected function get_cart_shipping() {
		if ( ! isset( WC()->cart ) ) {
			return 0;
		}

		$shipping_total = WC()->cart->get_shipping_total();
		$shipping_tax   = WC()->cart->get_shipping_tax();

		if ( 'excl' === WC()->cart->get_tax_price_display_mode() ) {
			return $shipping_total;
		} else {
			return $shipping_total + $shipping_tax;
		}
	}

	/**
	 * Get cart fees
	 *
	 * @return float
	 */
	protected function get_cart_fee() {
		if ( ! isset( WC()->cart ) ) {
			return 0;
		}

		$fee_total = (float) WC()->cart->get_fee_total();
		$fee_tax   = (float) WC()->cart->get_fee_tax();

		if ( 'excl' === WC()->cart->get_tax_price_display_mode() ) {
			return $fee_total;
		} else {
			return $fee_total + $fee_tax;
		}
	}

	/**
	 * Get client session data
	 *
	 * @return array
	 */
	protected function get_client_session_data() {
		if ( ! WC()->session ) {
			return array();
		}

		return array(
			'applied_coupons'         => WC()->session->get( 'applied_coupons' ),
			'chosen_shipping_methods' => WC()->session->get( 'chosen_shipping_methods' ),
			'shipping_method_counts'  => WC()->session->get( 'shipping_method_counts' ),
			'chosen_payment_method'   => WC()->session->get( 'chosen_payment_method' ),
		);
	}

	/**
	 * Check if cart is pending recovery
	 *
	 * @param int|null $user_id
	 * @return bool
	 */
	public static function cart_is_pending_recovery( $user_id = null ) {
		$user_id          = $user_id ?: get_current_user_id();
		$pending_recovery = false;

		if ( ! empty( $user_id ) ) {
			$pending_recovery = get_user_meta( $user_id, '_woodash_pending_recovery', true );
		}

		if ( empty( $pending_recovery ) && WC()->session ) {
			$pending_recovery = WC()->session->get( 'woodash_pending_recovery' );
		}

		return (bool) $pending_recovery;
	}
}
