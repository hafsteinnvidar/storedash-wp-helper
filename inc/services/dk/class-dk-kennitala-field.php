<?php
/**
 * Kennitala checkout field (classic + block checkout).
 *
 * Rendered only when the Storedash DK settings page has pushed
 * `kennitala_mode` = optional | required (`DK_Config`). With the mode off this
 * file registers nothing — zero cost.
 *
 * Behaviour (plan §4.8):
 *   - Position among the billing fields follows `kennitala_position` (classic).
 *     Block checkout has no per-field ordering, so the field joins the address
 *     form there.
 *   - Icelandic addresses only: for any other billing country the field is
 *     hidden (classic, JS) and never required; a value typed anyway is kept
 *     when it passes the checksum and dropped otherwise.
 *   - Saved under ONE key, `billing_kennitala` (`DK_Kennitala::META_KEY`), on
 *     the order and the customer, whichever checkout produced it. The
 *     storedash-worker invoice builder reads that key.
 *   - Coexists with DKSync: when another plugin already adds a kennitala/SSN
 *     billing field, the classic field is not added a second time.
 *
 * @package StoreDash\Services\DK
 * @since   1.27.0
 */

namespace StoreDash\Services\DK;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DK Kennitala Field Class
 */
class DK_Kennitala_Field {

	/** Classic checkout field key (also the order/customer meta key). */
	const FIELD_KEY = DK_Kennitala::META_KEY;

	/** Block checkout (Additional Checkout Fields API) id. */
	const BLOCK_FIELD_ID = 'storedash/kennitala';

	/** Meta key WooCommerce uses for an `address`-location block field. */
	const BLOCK_BILLING_META = '_wc_billing/storedash/kennitala';

	/** Billing field keys other plugins (DKSync, themes) use for the same thing. */
	const CONFLICTING_KEYS = array( 'billing_dk_ssn', 'billing_ssn', 'billing_kennitala' );

	/**
	 * Hook everything up — only when the mode is not off.
	 */
	public function __construct() {
		if ( ! DK_Config::kennitala_enabled() ) {
			return;
		}

		// Classic checkout. Priority 30: after DKSync's add (10) + move (20),
		// so the conflict check sees the final field list.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_classic_field' ), 30 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_to_order_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_customer', array( $this, 'save_to_customer_classic' ), 10, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'prefill_classic' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'print_classic_script' ), 30 );

		// Block checkout (Additional Checkout Fields API, WC 8.9+).
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_blocks_validate_location_address_fields', array( $this, 'validate_block_address' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'bridge_block_to_order_meta' ), 15 );

		// Admin order screen.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin' ) );
	}

	// ── Classic checkout ────────────────────────────────────────────────

	/**
	 * Add the billing field unless another plugin already has one.
	 *
	 * `required` is decided per request from the billing country WooCommerce
	 * knows at that moment (posted on submit, saved customer on render), so
	 * WooCommerce's own required-field validation and asterisk apply only to
	 * Icelandic addresses. The footer script keeps that in step with the
	 * country select while the customer is on the page.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function add_classic_field( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}
		$billing = isset( $fields['billing'] ) && is_array( $fields['billing'] ) ? $fields['billing'] : array();
		foreach ( self::CONFLICTING_KEYS as $key ) {
			if ( isset( $billing[ $key ] ) ) {
				return $fields;
			}
		}

		$fields['billing'][ self::FIELD_KEY ] = array(
			'type'              => 'text',
			'label'             => __( 'Kennitala', 'storedash' ),
			'placeholder'       => '000000-0000',
			'class'             => array( 'form-row-wide', 'storedash-kennitala-field' ),
			'priority'          => DK_Config::kennitala_priority(),
			'required'          => DK_Config::kennitala_required() && self::is_iceland( self::current_billing_country() ),
			'autocomplete'      => 'off',
			'custom_attributes' => array(
				'inputmode' => 'numeric',
				'maxlength' => '11',
			),
		);

		return $fields;
	}

	/**
	 * Checksum check (classic). The required check is WooCommerce's, via the
	 * field definition above.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Validation errors.
	 */
	public function validate_classic( $data, $errors ) {
		if ( ! is_array( $data ) || ! isset( $data[ self::FIELD_KEY ] ) ) {
			return;
		}
		$raw = trim( (string) $data[ self::FIELD_KEY ] );
		if ( '' === $raw ) {
			return;
		}
		if ( ! self::is_iceland( (string) ( $data['billing_country'] ?? '' ) ) ) {
			return; // Hidden for this country; whatever is there is dropped on save.
		}
		if ( ! DK_Kennitala::is_valid( $raw ) ) {
			$errors->add( 'storedash_kennitala', $this->invalid_message() );
		}
	}

	/**
	 * Persist on the order being created (classic). Runs before the first
	 * save, so no extra write.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Posted checkout data.
	 */
	public function save_to_order_classic( $order, $data ) {
		$value = $this->posted_value( $data );
		if ( '' !== $value && $order instanceof \WC_Order ) {
			$order->update_meta_data( self::FIELD_KEY, $value );
		}
	}

	/**
	 * Remember the kennitala on the customer for next time (classic).
	 *
	 * @param \WC_Customer $customer Customer.
	 * @param array        $data     Posted checkout data.
	 */
	public function save_to_customer_classic( $customer, $data ) {
		$value = $this->posted_value( $data );
		if ( '' !== $value && is_object( $customer ) && method_exists( $customer, 'update_meta_data' ) ) {
			$customer->update_meta_data( self::FIELD_KEY, $value );
		}
	}

	/**
	 * Prefill from the logged-in customer (classic).
	 *
	 * @param mixed  $value Current value.
	 * @param string $input Field key.
	 * @return mixed
	 */
	public function prefill_classic( $value, $input ) {
		if ( self::FIELD_KEY !== $input || ( null !== $value && '' !== $value ) ) {
			return $value;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $value;
		}
		$saved = DK_Kennitala::normalize( get_user_meta( $user_id, self::FIELD_KEY, true ) );
		return '' === $saved ? $value : DK_Kennitala::format( $saved );
	}

	/**
	 * Show/hide + toggle the asterisk with the billing country (classic only).
	 */
	public function print_classic_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		$required = DK_Config::kennitala_required() ? 'true' : 'false';
		$optional = __( '(optional)', 'woocommerce' );
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- tiny inline toggle, no external file.
		echo '<script>(function(){' .
			'var row=document.getElementById("billing_kennitala_field");if(!row){return;}' .
			'var country=document.getElementById("billing_country");if(!country){return;}' .
			'var req=' . esc_js( $required ) . ';var input=row.querySelector("input");var label=row.querySelector("label");' .
			'function sync(){var is=country.value==="IS";row.style.display=is?"":"none";' .
			'if(input){input.required=is&&req;}' .
			'if(!label){return;}var mark=label.querySelector(".required");var opt=label.querySelector(".optional");' .
			'if(is&&req){if(opt){opt.remove();}if(!mark){mark=document.createElement("abbr");mark.className="required";mark.title="required";mark.textContent="*";label.appendChild(document.createTextNode(" "));label.appendChild(mark);}}' .
			'else{if(mark){mark.remove();}if(!opt&&!req){opt=document.createElement("span");opt.className="optional";opt.textContent="' . esc_js( $optional ) . '";label.appendChild(document.createTextNode(" "));label.appendChild(opt);}}}' .
			'sync();country.addEventListener("change",sync);' .
			'if(window.jQuery){jQuery(document.body).on("updated_checkout country_to_state_changed",sync);}' .
			'})();</script>';
	}

	// ── Block checkout ──────────────────────────────────────────────────

	/**
	 * Register the Additional Checkout Field. Location `address` so the
	 * country is available to the validation hook below.
	 */
	public function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                => self::BLOCK_FIELD_ID,
					'label'             => __( 'Kennitala', 'storedash' ),
					'optionalLabel'     => __( 'Kennitala (optional)', 'storedash' ),
					'location'          => 'address',
					'type'              => 'text',
					'required'          => false,
					'attributes'        => array(
						'autocomplete' => 'off',
						'inputMode'    => 'numeric',
						'maxLength'    => '11',
						'pattern'      => '[0-9]{6}-?[0-9]{4}',
					),
					'sanitize_callback' => function ( $value ) {
						return trim( (string) $value );
					},
					'validate_callback' => function ( $value ) {
						$value = trim( (string) $value );
						if ( '' !== $value && ! DK_Kennitala::is_valid( $value ) ) {
							return new \WP_Error( 'storedash_kennitala_invalid', $this->invalid_message() );
						}
						return true;
					},
				)
			);
		} catch ( \Exception $e ) {
			if ( class_exists( 'StoreDash_Helpers' ) ) {
				\StoreDash_Helpers::debug_log( 'Kennitala block field registration failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Required-for-Iceland check on the billing address group (block checkout).
	 *
	 * @param \WP_Error $errors Errors to add to.
	 * @param array     $fields Address fields incl. `country` and our id.
	 * @param string    $group  `billing` | `shipping`.
	 */
	public function validate_block_address( $errors, $fields, $group ) {
		if ( 'billing' !== $group || ! DK_Config::kennitala_required() || ! is_array( $fields ) ) {
			return;
		}
		if ( ! self::is_iceland( (string) ( $fields['country'] ?? '' ) ) ) {
			return;
		}
		$value = trim( (string) ( $fields[ self::BLOCK_FIELD_ID ] ?? '' ) );
		if ( '' === $value && is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'storedash_kennitala_required', __( 'Kennitala is required for Icelandic addresses.', 'storedash' ) );
		}
	}

	/**
	 * Copy the block field value to the canonical `billing_kennitala` meta.
	 *
	 * @param \WC_Order|int $order Order.
	 */
	public function bridge_block_to_order_meta( $order ) {
		$order = wc_get_order( $order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::FIELD_KEY ) ) {
			return; // Classic path (or another plugin) already wrote it.
		}
		$raw = $order->get_meta( self::BLOCK_BILLING_META );
		if ( '' === (string) $raw ) {
			$raw = $order->get_meta( '_wc_other/' . self::BLOCK_FIELD_ID );
		}
		$value = DK_Kennitala::normalize( $raw );
		if ( '' === $value || ! DK_Kennitala::is_valid( $value ) ) {
			return;
		}
		if ( ! self::is_iceland( (string) $order->get_billing_country() ) ) {
			return;
		}
		$order->update_meta_data( self::FIELD_KEY, $value );
		$order->save();
	}

	// ── Admin ───────────────────────────────────────────────────────────

	/**
	 * Show the kennitala under the billing address in the admin order screen.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function render_admin( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$value = DK_Kennitala::normalize( $order->get_meta( self::FIELD_KEY ) );
		if ( '' === $value ) {
			return;
		}
		echo '<p><strong>' . esc_html__( 'Kennitala', 'storedash' ) . ':</strong> ' . esc_html( DK_Kennitala::format( $value ) ) . '</p>';
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Normalised, valid kennitala from posted classic data for an Icelandic
	 * billing address; '' otherwise.
	 *
	 * @param array $data Posted checkout data.
	 * @return string
	 */
	private function posted_value( $data ): string {
		if ( ! is_array( $data ) || ! isset( $data[ self::FIELD_KEY ] ) ) {
			return '';
		}
		if ( ! self::is_iceland( (string) ( $data['billing_country'] ?? '' ) ) ) {
			return '';
		}
		$value = DK_Kennitala::normalize( $data[ self::FIELD_KEY ] );
		return DK_Kennitala::is_valid( $value ) ? $value : '';
	}

	/**
	 * Billing country WooCommerce knows for this request.
	 *
	 * @return string
	 */
	private static function current_billing_country(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only peek; WooCommerce verifies the checkout nonce itself.
		if ( isset( $_POST['billing_country'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['billing_country'] ) );
		}
		// phpcs:enable
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( is_object( $wc ) && isset( $wc->customer ) && is_object( $wc->customer ) && method_exists( $wc->customer, 'get_billing_country' ) ) {
				return (string) $wc->customer->get_billing_country();
			}
		}
		return '';
	}

	/**
	 * @param string $country ISO-3166 alpha-2.
	 * @return bool
	 */
	public static function is_iceland( string $country ): bool {
		return 'IS' === strtoupper( trim( $country ) );
	}

	/**
	 * @return string
	 */
	private function invalid_message(): string {
		return __( 'Please enter a valid kennitala (10 digits).', 'storedash' );
	}
}

// Initialize (mirrors Opt_In_Handler). Registers nothing while the mode is off.
new DK_Kennitala_Field();
