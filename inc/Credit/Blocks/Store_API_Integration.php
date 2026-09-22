<?php
/**
 * Store API extension for rewards credit (contract C).
 *
 * Cart + checkout responses carry `extensions.storedash_credit`, and
 * `POST /wc/store/v1/cart/extensions { namespace: "storedash_credit",
 * data: { apply, amount } }` sets the session request so the next totals
 * calculation applies the fee. Money is minor-unit integer strings, like
 * WooCommerce's own totals.
 *
 * @package StoreDash\Credit\Blocks
 * @since   1.17.0
 */

namespace StoreDash\Credit\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Engine\Credit_Request;
use StoreDash\Credit\Engine\Spend_Handler;
use StoreDash\Credit\Money;

/**
 * Store API schema + update callback.
 *
 * @since 1.17.0
 */
class Store_API_Integration {

	/**
	 * Extension namespace.
	 */
	const NAMESPACE = 'storedash_credit';

	/**
	 * Spend handler.
	 *
	 * @var Spend_Handler
	 */
	protected $spend;

	/**
	 * Constructor.
	 *
	 * @param Spend_Handler $spend Spend handler.
	 */
	public function __construct( Spend_Handler $spend ) {
		$this->spend = $spend;
	}

	/**
	 * Register endpoint data + update callback (call inside woocommerce_blocks_loaded).
	 */
	public function register(): void {
		if ( function_exists( '\woocommerce_store_api_register_endpoint_data' ) ) {
			foreach ( array( 'cart', 'checkout' ) as $endpoint ) {
				\woocommerce_store_api_register_endpoint_data(
					array(
						'endpoint'        => $endpoint,
						'namespace'       => self::NAMESPACE,
						'data_callback'   => array( $this, 'data_callback' ),
						'schema_callback' => array( $this, 'schema_callback' ),
						'schema_type'     => ARRAY_A,
					)
				);
			}
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
	 * Extension data.
	 *
	 * @return array
	 */
	public function data_callback(): array {
		return self::format_state( $this->spend->cart_state(), wc_get_price_decimals() );
	}

	/**
	 * Serialize a cart state (major-unit floats) into the contract-C shape.
	 *
	 * Pure function — unit tested.
	 *
	 * @param array $state    Spend_Handler::cart_state() result.
	 * @param int   $decimals Currency minor unit.
	 * @return array
	 */
	public static function format_state( array $state, int $decimals ): array {
		$expiring = null;
		if ( ! empty( $state['expiring_next'] ) && is_array( $state['expiring_next'] ) ) {
			$expiring = array(
				'amount' => Money::to_minor( $state['expiring_next']['amount'], $decimals ),
				'at'     => Money::to_iso( $state['expiring_next']['at'] ),
			);
		}

		return array(
			'enabled'        => ! empty( $state['enabled'] ),
			'logged_in'      => ! empty( $state['logged_in'] ),
			'currency'       => (string) ( $state['currency'] ?? '' ),
			'balance'        => Money::to_minor( $state['balance'] ?? 0, $decimals ),
			'eligible'       => Money::to_minor( $state['eligible'] ?? 0, $decimals ),
			'max_applicable' => Money::to_minor( $state['max_applicable'] ?? 0, $decimals ),
			'requested'      => ( isset( $state['requested'] ) && null !== $state['requested'] ) ? Money::to_minor( $state['requested'], $decimals ) : null,
			'applied'        => Money::to_minor( $state['applied'] ?? 0, $decimals ),
			'expiring_next'  => $expiring,
		);
	}

	/**
	 * Extension schema.
	 *
	 * @return array
	 */
	public function schema_callback(): array {
		$money = static function ( $description, $nullable = false ) {
			return array(
				'description' => $description,
				'type'        => $nullable ? array( 'string', 'null' ) : 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			);
		};

		return array(
			'enabled'        => array(
				'description' => __( 'Whether rewards credit is enabled on this store.', 'storedash' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'logged_in'      => array(
				'description' => __( 'Whether the shopper is logged in (guests cannot spend credit).', 'storedash' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'currency'       => array(
				'description' => __( 'Currency code.', 'storedash' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'balance'        => $money( __( 'Spendable balance (minor units).', 'storedash' ) ),
			'eligible'       => $money( __( 'Cart amount credit may be applied to (minor units).', 'storedash' ) ),
			'max_applicable' => $money( __( 'Maximum credit applicable to this cart (minor units).', 'storedash' ) ),
			'requested'      => $money( __( 'Amount the shopper asked to apply, null for maximum (minor units).', 'storedash' ), true ),
			'applied'        => $money( __( 'Credit currently applied as a fee (minor units).', 'storedash' ) ),
			'expiring_next'  => array(
				'description' => __( 'Soonest-expiring credit, or null.', 'storedash' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'properties'  => array(
					'amount' => array( 'type' => 'string' ),
					'at'     => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * `POST /wc/store/v1/cart/extensions` handler.
	 *
	 * @param array $data { apply: bool, amount: string|null (minor units) }.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When a guest tries to apply credit.
	 */
	public function update_callback( $data ): void {
		$data  = is_array( $data ) ? $data : array();
		$apply = ! empty( $data['apply'] ) && ( true === $data['apply'] || 'true' === $data['apply'] || 1 === $data['apply'] || '1' === $data['apply'] );

		if ( $apply && ! is_user_logged_in() ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'storedash_credit_login_required',
					esc_html__( 'Please log in to use rewards credit.', 'storedash' ),
					403
				);
			}
			return;
		}

		$amount = null;
		if ( $apply && isset( $data['amount'] ) && null !== $data['amount'] && '' !== $data['amount'] ) {
			$amount = Money::from_minor( (string) $data['amount'], wc_get_price_decimals() );
		}

		Credit_Request::set( $apply, $amount );
	}
}
