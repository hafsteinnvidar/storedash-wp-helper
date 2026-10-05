<?php
/**
 * Gift card recipient details (contract D).
 *
 * Optional recipient email / name, sender name, message and send date travel
 * product page → cart item data (`storedash_gift_card`) → order item meta
 * `_storedash_gc_*`, from the classic product form and from the Store API
 * (`POST /cart/add-item { …, storedash_gift_card: {…} }`). A few visible
 * meta lines are added too so the merchant and the buyer see them on the
 * order and in emails.
 *
 * @package StoreDash\GiftCard\Product
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recipient fields.
 *
 * @since 1.24.0
 */
class Recipient_Fields {

	/**
	 * Cart item data key + Store API request key.
	 */
	const KEY = 'storedash_gift_card';

	/**
	 * Fields (contract D) => order item meta key.
	 */
	const FIELDS = array(
		'recipient_email' => '_storedash_gc_recipient_email',
		'recipient_name'  => '_storedash_gc_recipient_name',
		'sender_name'     => '_storedash_gc_sender_name',
		'message'         => '_storedash_gc_message',
		'send_at'         => '_storedash_gc_send_at',
	);

	/**
	 * Order item meta holding the minted card ids (json array).
	 */
	const META_CARD_IDS = '_storedash_gc_card_ids';

	/**
	 * Max message length.
	 */
	const MESSAGE_MAX = 500;

	/**
	 * How far ahead a send date may be.
	 */
	const SEND_AT_MAX_DAYS = 365;

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_fields' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_classic' ), 20, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'classic_cart_item_data' ), 20, 2 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'store_api_cart_item_data' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_display' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_item_meta' ), 20, 4 );
	}

	// ── Pure validation ───────────────────────────────────────────────────

	/**
	 * Validate + normalize a recipient payload.
	 *
	 * Pure (bar __()) — unit tested. All fields are optional; empty strings are
	 * dropped.
	 *
	 * @param mixed  $raw   Raw payload.
	 * @param string $today Store-local date Y-m-d.
	 * @return array { data: array, errors: string[] }
	 */
	public static function validate( $raw, string $today ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$data   = array();
		$errors = array();

		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( ! isset( $raw[ $field ] ) || ! is_scalar( $raw[ $field ] ) ) {
				continue;
			}
			// wp_strip_all_tags() keeps line breaks, so messages keep theirs.
			$value = trim( wp_strip_all_tags( str_replace( "\r\n", "\n", (string) $raw[ $field ] ) ) );
			if ( '' === $value ) {
				continue;
			}
			$data[ $field ] = $value;
		}

		if ( isset( $data['recipient_email'] ) ) {
			$email = strtolower( $data['recipient_email'] );
			$valid = function_exists( 'is_email' ) ? (bool) is_email( $email ) : false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			if ( ! $valid || strlen( $email ) > 191 ) {
				$errors[] = __( 'Please enter a valid recipient email address.', 'storedash' );
			}
			$data['recipient_email'] = $email;
		}

		foreach ( array( 'recipient_name', 'sender_name' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$data[ $field ] = self::cut( $data[ $field ], 100 );
			}
		}

		if ( isset( $data['message'] ) && self::length( $data['message'] ) > self::MESSAGE_MAX ) {
			/* translators: %d: maximum characters */
			$errors[] = sprintf( __( 'The gift card message can be at most %d characters.', 'storedash' ), self::MESSAGE_MAX );
		}

		if ( isset( $data['send_at'] ) ) {
			$date = self::parse_date( $data['send_at'] );
			$max  = self::parse_date( $today );
			if ( null !== $max ) {
				$max = gmdate( 'Y-m-d', strtotime( $max . ' 00:00:00 UTC' ) + self::SEND_AT_MAX_DAYS * 86400 );
			}
			if ( null === $date || $date < $today || ( null !== $max && $date > $max ) ) {
				$errors[] = __( 'Please choose a send date between today and one year from now.', 'storedash' );
			} else {
				$data['send_at'] = $date;
			}
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Strict Y-m-d parse (also accepts a full ISO datetime and keeps its date).
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	public static function parse_date( string $value ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}

	/**
	 * Send date (Y-m-d) → UTC MySQL datetime at 09:00 store time.
	 *
	 * @param string        $date     Y-m-d.
	 * @param \DateTimeZone $timezone Store timezone.
	 * @return string|null
	 */
	public static function send_at_utc( string $date, \DateTimeZone $timezone ) {
		$date = self::parse_date( $date );
		if ( null === $date ) {
			return null;
		}
		try {
			$local = new \DateTimeImmutable( $date . ' 09:00:00', $timezone );
			return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	// ── Hooks ─────────────────────────────────────────────────────────────

	/**
	 * Classic product page fields.
	 */
	public function render_fields(): void {
		global $product;
		if ( ! $product instanceof \WC_Product || ! Gift_Card_Product::is_gift_card( $product ) ) {
			return;
		}
		$today = wp_date( 'Y-m-d' );
		$max   = wp_date( 'Y-m-d', time() + self::SEND_AT_MAX_DAYS * DAY_IN_SECONDS );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- re-filling the add-to-cart form after a validation error.
		$value = static function ( $field ) {
			return isset( $_POST[ 'storedash_gc_' . $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'storedash_gc_' . $field ] ) ) : '';
		};
		// phpcs:enable
		?>
		<div class="storedash-gift-card-fields">
			<p class="form-row">
				<label for="storedash_gc_recipient_email"><?php esc_html_e( 'Recipient email (optional)', 'storedash' ); ?></label>
				<input type="email" class="input-text" id="storedash_gc_recipient_email" name="storedash_gc_recipient_email" value="<?php echo esc_attr( $value( 'recipient_email' ) ); ?>" />
				<small><?php esc_html_e( 'Leave empty to receive the gift card yourself.', 'storedash' ); ?></small>
			</p>
			<p class="form-row">
				<label for="storedash_gc_recipient_name"><?php esc_html_e( 'Recipient name (optional)', 'storedash' ); ?></label>
				<input type="text" class="input-text" id="storedash_gc_recipient_name" name="storedash_gc_recipient_name" maxlength="100" value="<?php echo esc_attr( $value( 'recipient_name' ) ); ?>" />
			</p>
			<p class="form-row">
				<label for="storedash_gc_sender_name"><?php esc_html_e( 'From (optional)', 'storedash' ); ?></label>
				<input type="text" class="input-text" id="storedash_gc_sender_name" name="storedash_gc_sender_name" maxlength="100" value="<?php echo esc_attr( $value( 'sender_name' ) ); ?>" />
			</p>
			<p class="form-row">
				<label for="storedash_gc_message"><?php esc_html_e( 'Message (optional)', 'storedash' ); ?></label>
				<textarea class="input-text" id="storedash_gc_message" name="storedash_gc_message" rows="3" maxlength="<?php echo esc_attr( (string) self::MESSAGE_MAX ); ?>"><?php echo esc_textarea( $value( 'message' ) ); ?></textarea>
			</p>
			<p class="form-row">
				<label for="storedash_gc_send_at"><?php esc_html_e( 'Send on (optional)', 'storedash' ); ?></label>
				<input type="date" class="input-text" id="storedash_gc_send_at" name="storedash_gc_send_at" min="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $max ); ?>" value="<?php echo esc_attr( $value( 'send_at' ) ); ?>" />
			</p>
		</div>
		<?php
	}

	/**
	 * Classic POST payload, or null when the form carried none of the fields.
	 *
	 * @return array|null
	 */
	protected function classic_payload() {
		$raw = array();
		foreach ( array_keys( self::FIELDS ) as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart form (no nonce by design).
			if ( isset( $_POST[ 'storedash_gc_' . $field ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$raw[ $field ] = sanitize_textarea_field( wp_unslash( $_POST[ 'storedash_gc_' . $field ] ) );
			}
		}
		return empty( $raw ) ? null : $raw;
	}

	/**
	 * Classic add-to-cart validation.
	 *
	 * @param bool $passed     Passed so far.
	 * @param int  $product_id Product id.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public function validate_classic( $passed, $product_id, $quantity ) {
		if ( ! $passed || ! Gift_Card_Product::is_gift_card_id( (int) $product_id ) ) {
			return $passed;
		}
		$payload = $this->classic_payload();
		if ( null === $payload ) {
			return $passed;
		}
		$result = self::validate( $payload, wp_date( 'Y-m-d' ) );
		foreach ( $result['errors'] as $error ) {
			wc_add_notice( $error, 'error' );
		}
		return empty( $result['errors'] );
	}

	/**
	 * Classic: attach the fields to the cart item.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product id (parent for variations).
	 * @return array
	 */
	public function classic_cart_item_data( $cart_item_data, $product_id ) {
		if ( ! is_array( $cart_item_data ) || isset( $cart_item_data[ self::KEY ] ) || ! Gift_Card_Product::is_gift_card_id( (int) $product_id ) ) {
			return $cart_item_data;
		}
		$payload = $this->classic_payload();
		if ( null === $payload ) {
			return $cart_item_data;
		}
		$result = self::validate( $payload, wp_date( 'Y-m-d' ) );
		if ( empty( $result['errors'] ) && ! empty( $result['data'] ) ) {
			$cart_item_data[ self::KEY ] = $result['data'];
		}
		return $cart_item_data;
	}

	/**
	 * Store API add-item: read `storedash_gift_card` from the request.
	 *
	 * @param array            $data    { id, quantity, variation, cart_item_data }.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the payload is invalid.
	 */
	public function store_api_cart_item_data( $data, $request ) {
		if ( ! is_array( $data ) || ! $request instanceof \WP_REST_Request ) {
			return $data;
		}
		$payload = $request->get_param( self::KEY );
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return $data;
		}
		$product = wc_get_product( (int) ( $data['id'] ?? 0 ) );
		if ( ! $product || ! Gift_Card_Product::is_gift_card( $product ) ) {
			return $data;
		}

		$result = self::validate( $payload, wp_date( 'Y-m-d' ) );
		if ( ! empty( $result['errors'] ) && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'storedash_gift_card_recipient_invalid', esc_html( implode( ' ', $result['errors'] ) ), 400 );
		}
		if ( ! empty( $result['data'] ) ) {
			$data['cart_item_data']              = isset( $data['cart_item_data'] ) && is_array( $data['cart_item_data'] ) ? $data['cart_item_data'] : array();
			$data['cart_item_data'][ self::KEY ] = $result['data'];
		}
		return $data;
	}

	/**
	 * Visible labels for the cart / order display.
	 *
	 * @return array field => label
	 */
	protected static function labels(): array {
		return array(
			'recipient_email' => __( 'Gift card recipient', 'storedash' ),
			'recipient_name'  => __( 'Recipient name', 'storedash' ),
			'sender_name'     => __( 'From', 'storedash' ),
			'message'         => __( 'Message', 'storedash' ),
			'send_at'         => __( 'Send on', 'storedash' ),
		);
	}

	/**
	 * Cart / checkout line display.
	 *
	 * @param array $item_data Display rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function cart_item_display( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::KEY ] ) || ! is_array( $cart_item[ self::KEY ] ) ) {
			return $item_data;
		}
		$item_data = is_array( $item_data ) ? $item_data : array();
		foreach ( self::labels() as $field => $label ) {
			if ( ! empty( $cart_item[ self::KEY ][ $field ] ) ) {
				$item_data[] = array(
					'key'   => $label,
					'value' => (string) $cart_item[ self::KEY ][ $field ],
				);
			}
		}
		return $item_data;
	}

	/**
	 * Copy the fields to the order line (classic + Store API checkout).
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array                  $values        Cart item.
	 * @param \WC_Order              $order         Order.
	 */
	public function order_item_meta( $item, $cart_item_key, $values, $order ): void {
		if ( empty( $values[ self::KEY ] ) || ! is_array( $values[ self::KEY ] ) ) {
			return;
		}
		$labels = self::labels();
		foreach ( self::FIELDS as $field => $meta_key ) {
			if ( empty( $values[ self::KEY ][ $field ] ) ) {
				continue;
			}
			$value = (string) $values[ self::KEY ][ $field ];
			$item->add_meta_data( $meta_key, $value, true );
			// Visible copy for the order screen and emails.
			$item->add_meta_data( $labels[ $field ], $value, true );
		}
	}

	/**
	 * Recipient details stored on an order line.
	 *
	 * @param \WC_Order_Item_Product $item Order item.
	 * @return array field => value (only non-empty)
	 */
	public static function from_order_item( $item ): array {
		$out = array();
		foreach ( self::FIELDS as $field => $meta_key ) {
			$value = (string) $item->get_meta( $meta_key, true );
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	protected static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	/**
	 * Multibyte-safe cut.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max length.
	 * @return string
	 */
	protected static function cut( string $value, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
