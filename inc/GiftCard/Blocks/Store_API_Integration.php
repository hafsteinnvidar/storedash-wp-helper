<?php
/**
 * Store API extension for gift cards (contract A / D).
 *
 * - cart + checkout: `extensions.storedash_gift_card = { enabled, max_cards,
 *   cart_has_gift_card, gift_card_only, cards: [{id, last4, balance, applied}], applied_total }`
 *   (minor-unit integer strings, like WooCommerce's own totals).
 * - cart item: `extensions.storedash_gift_card = { is_gift_card, recipient_email, … }`.
 * - product: `extensions.storedash_gift_card = { is_gift_card }`.
 * - `POST /wc/store/v1/cart/extensions { namespace: "storedash_gift_card",
 *   data: { apply: "CODE" } | { remove: 7 } }`.
 *
 * @package StoreDash\GiftCard\Blocks
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Money;
use StoreDash\GiftCard\Engine\Redemption;
use StoreDash\GiftCard\Product\Gift_Card_Product;
use StoreDash\GiftCard\Product\Recipient_Fields;

/**
 * Store API schema + update callback.
 *
 * @since 1.24.0
 */
class Store_API_Integration {

	/**
	 * Extension namespace.
	 */
	const NAMESPACE = 'storedash_gift_card';

	/**
	 * Redemption.
	 *
	 * @var Redemption
	 */
	protected $redemption;

	/**
	 * Constructor.
	 *
	 * @param Redemption $redemption Redemption.
	 */
	public function __construct( Redemption $redemption ) {
		$this->redemption = $redemption;
	}

	/**
	 * Register endpoint data + update callback (inside woocommerce_blocks_loaded).
	 */
	public function register(): void {
		if ( function_exists( '\woocommerce_store_api_register_endpoint_data' ) ) {
			foreach ( array( 'cart', 'checkout' ) as $endpoint ) {
				\woocommerce_store_api_register_endpoint_data(
					array(
						'endpoint'        => $endpoint,
						'namespace'       => self::NAMESPACE,
						'data_callback'   => array( $this, 'cart_data' ),
						'schema_callback' => 'checkout' === $endpoint ? array( $this, 'checkout_schema_callback' ) : array( $this, 'cart_schema' ),
						'schema_type'     => ARRAY_A,
					)
				);
			}
			\woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => 'cart-item',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => array( $this, 'cart_item_data' ),
					'schema_callback' => array( $this, 'cart_item_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
			\woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => 'product',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => array( $this, 'product_data' ),
					'schema_callback' => array( $this, 'product_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}

		if ( function_exists( '\woocommerce_store_api_register_update_callback' ) ) {
			\woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE,
					'callback'  => array( $this, 'update_callback' ),
				)
			);
		}
	}

	/**
	 * Cart / checkout extension data.
	 *
	 * @return array
	 */
	public function cart_data(): array {
		return self::format_state( $this->redemption->cart_state(), wc_get_price_decimals() );
	}

	/**
	 * Serialize Redemption::cart_state() into the contract-D shape.
	 *
	 * Pure function — unit tested.
	 *
	 * @param array $state    Cart state (major units).
	 * @param int   $decimals Currency minor unit.
	 * @return array
	 */
	public static function format_state( array $state, int $decimals ): array {
		$cards = array();
		foreach ( (array) ( $state['cards'] ?? array() ) as $card ) {
			$cards[] = array(
				'id'      => (int) $card['id'],
				'last4'   => (string) $card['last4'],
				'balance' => Money::to_minor( $card['balance'] ?? 0, $decimals ),
				'applied' => Money::to_minor( $card['applied'] ?? 0, $decimals ),
			);
		}
		return array(
			'enabled'            => ! empty( $state['enabled'] ),
			'max_cards'          => (int) ( $state['max_cards'] ?? 0 ),
			'cart_has_gift_card' => ! empty( $state['cart_has_gift_card'] ),
			'gift_card_only'     => ! empty( $state['gift_card_only'] ),
			'cards'              => $cards,
			'applied_total'      => Money::to_minor( $state['applied_total'] ?? 0, $decimals ),
		);
	}

	/**
	 * Cart / checkout schema.
	 *
	 * @return array
	 */
	public function cart_schema(): array {
		return array(
			'enabled'            => self::prop( 'boolean', __( 'Whether gift cards are enabled on this store.', 'storedash' ) ),
			'max_cards'          => self::prop( 'integer', __( 'Maximum gift cards per order.', 'storedash' ) ),
			'cart_has_gift_card' => self::prop( 'boolean', __( 'Whether the cart contains a gift card product (gift cards cannot be applied then).', 'storedash' ) ),
			'gift_card_only'     => self::prop( 'boolean', __( 'Whether every cart line is a gift card (no billing address needed: name + email).', 'storedash' ) ),
			'cards'              => array(
				'description' => __( 'Applied gift cards, in apply order.', 'storedash' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'last4'   => array( 'type' => 'string' ),
						'balance' => array( 'type' => 'string' ),
						'applied' => array( 'type' => 'string' ),
					),
				),
			),
			'applied_total'      => self::prop( 'string', __( 'Total paid with gift cards (minor units).', 'storedash' ) ),
		);
	}

	/**
	 * Cart item extension data.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function cart_item_data( $cart_item ): array {
		$product = is_array( $cart_item ) && isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		$out     = array( 'is_gift_card' => $product ? Gift_Card_Product::is_gift_card( $product ) : false );
		$fields  = is_array( $cart_item ) && ! empty( $cart_item[ Recipient_Fields::KEY ] ) && is_array( $cart_item[ Recipient_Fields::KEY ] ) ? $cart_item[ Recipient_Fields::KEY ] : array();
		foreach ( array_keys( Recipient_Fields::FIELDS ) as $field ) {
			$out[ $field ] = isset( $fields[ $field ] ) && '' !== $fields[ $field ] ? (string) $fields[ $field ] : null;
		}
		return $out;
	}

	/**
	 * Cart item schema.
	 *
	 * @return array
	 */
	public function cart_item_schema(): array {
		$schema = array( 'is_gift_card' => self::prop( 'boolean', __( 'Whether this line is a gift card.', 'storedash' ) ) );
		foreach ( array_keys( Recipient_Fields::FIELDS ) as $field ) {
			$schema[ $field ] = self::prop( array( 'string', 'null' ), $field );
		}
		return $schema;
	}

	/**
	 * Product extension data.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function product_data( $product ): array {
		return array( 'is_gift_card' => Gift_Card_Product::is_gift_card( $product ) );
	}

	/**
	 * Product schema.
	 *
	 * @return array
	 */
	public function product_schema(): array {
		return array( 'is_gift_card' => self::prop( 'boolean', __( 'Whether this product is a gift card.', 'storedash' ) ) );
	}

	/**
	 * Checkout endpoint schema: intentionally empty.
	 *
	 * On the checkout route the `extensions` schema is also the REQUEST schema
	 * (CheckoutSchema exposes it as an input arg, and WordPress validates nested
	 * properties regardless of `readonly`). Clients that echo the cart's
	 * extension data back — or send anything else under this namespace — would
	 * get `400 rest_invalid_param` on every checkout. An empty property list
	 * accepts any input; the response still carries the data.
	 *
	 * @return array
	 */
	public function checkout_schema_callback(): array {
		return array();
	}

	/**
	 * `POST /wc/store/v1/cart/extensions` handler.
	 *
	 * @param array $data { apply: string } | { remove: int|string }.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException On a rejected code.
	 */
	public function update_callback( $data ): void {
		$data = is_array( $data ) ? $data : array();

		if ( isset( $data['remove'] ) && '' !== $data['remove'] ) {
			$this->redemption->remove( $data['remove'] );
		}

		if ( isset( $data['apply'] ) && is_scalar( $data['apply'] ) && '' !== trim( (string) $data['apply'] ) ) {
			$result = $this->redemption->apply_code( (string) $data['apply'] );
			if ( is_wp_error( $result ) && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				$error = $result->get_error_data();
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					esc_html( $result->get_error_code() ),
					esc_html( $result->get_error_message() ),
					(int) ( is_array( $error ) && isset( $error['status'] ) ? $error['status'] : 400 )
				);
			}
		}
	}

	/**
	 * Read-only schema property.
	 *
	 * @param string|array $type        Type.
	 * @param string       $description Description.
	 * @return array
	 */
	protected static function prop( $type, string $description ): array {
		return array(
			'description' => $description,
			'type'        => $type,
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
		);
	}
}
