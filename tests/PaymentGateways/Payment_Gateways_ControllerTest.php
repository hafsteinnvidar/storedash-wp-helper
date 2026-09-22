<?php
/**
 * Unit tests for StoreDash_API_Payment_Gateways mapping logic.
 *
 * format_gateway() and build_order_payment_meta() are the pure selection/mapping
 * core of the payment-gateway endpoints. The WordPress/REST glue (route
 * registration, wc_get_order lookup, permission callbacks) is verified against a
 * live store; these tests pin the mapping where the contract-shape bugs live —
 * refund-capability detection, the enabled flag, supports-list sanitization, the
 * empty-string → null normalization, and the unknown/uninstalled-gateway branch.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

// Stub the WordPress hook registrar so the controller file (which self-instantiates
// at file scope) can load in the standalone (non-WordPress) harness, plus the URL
// sanitizer the transaction-url mapper leans on.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return $url;
	}
}

require_once __DIR__ . '/../../inc/api/payment-gateways.php';

/**
 * Minimal WC_Payment_Gateway double — only the surface the mappers touch.
 */
class Fake_Payment_Gateway {

	/** @var string */
	public $id = '';
	/** @var string */
	public $enabled = 'yes';
	/** @var array */
	public $supports = array();
	/** @var string */
	private $title = '';
	/** @var string */
	private $method_title = '';
	/** @var string */
	private $transaction_url = '';

	public function __construct( array $props = array() ) {
		foreach ( $props as $key => $value ) {
			$this->{$key} = $value;
		}
	}

	public function get_title() {
		return $this->title;
	}

	public function get_method_title() {
		return $this->method_title;
	}

	public function supports( $feature ) {
		return in_array( $feature, $this->supports, true );
	}

	public function get_transaction_url( $order ) {
		return $this->transaction_url;
	}
}

/**
 * Minimal WC_Order double — only the getters the mappers read.
 */
class Fake_Payment_Order {

	/** @var string */
	private $payment_method;
	/** @var string */
	private $payment_method_title;
	/** @var string */
	private $transaction_id;

	public function __construct( string $payment_method = '', string $payment_method_title = '', string $transaction_id = '' ) {
		$this->payment_method       = $payment_method;
		$this->payment_method_title = $payment_method_title;
		$this->transaction_id       = $transaction_id;
	}

	public function get_payment_method() {
		return $this->payment_method;
	}

	public function get_payment_method_title() {
		return $this->payment_method_title;
	}

	public function get_transaction_id() {
		return $this->transaction_id;
	}
}

/**
 * @covers StoreDash_API_Payment_Gateways::format_gateway
 * @covers StoreDash_API_Payment_Gateways::build_order_payment_meta
 */
class Payment_Gateways_ControllerTest extends TestCase {

	public function test_format_gateway_maps_an_enabled_refund_capable_gateway(): void {
		$gateway = new Fake_Payment_Gateway(
			array(
				'id'           => 'stripe',
				'enabled'      => 'yes',
				'supports'     => array( 'products', 'refunds', 'tokenization' ),
				'title'        => 'Credit card (Stripe)',
				'method_title' => 'Stripe',
			)
		);

		$this->assertSame(
			array(
				'id'               => 'stripe',
				'title'            => 'Credit card (Stripe)',
				'method_title'     => 'Stripe',
				'enabled'          => true,
				'supports_refunds' => true,
				'supports'         => array( 'products', 'refunds', 'tokenization' ),
			),
			StoreDash_API_Payment_Gateways::format_gateway( $gateway )
		);
	}

	public function test_format_gateway_reports_disabled_gateways(): void {
		$gateway = new Fake_Payment_Gateway(
			array(
				'id'           => 'cod',
				'enabled'      => 'no',
				'supports'     => array( 'products' ),
				'title'        => 'Cash on delivery',
				'method_title' => 'Cash on delivery',
			)
		);

		$result = StoreDash_API_Payment_Gateways::format_gateway( $gateway );

		$this->assertFalse( $result['enabled'] );
		$this->assertFalse( $result['supports_refunds'] );
		$this->assertSame( array( 'products' ), $result['supports'] );
	}

	public function test_format_gateway_sanitizes_supports_and_nulls_empty_titles(): void {
		$gateway = new Fake_Payment_Gateway(
			array(
				'id'           => 'weird',
				'enabled'      => 'yes',
				// Non-scalar, empty and duplicate entries must be dropped.
				'supports'     => array( 'products', 'products', '', array( 'nested' ), 'refunds' ),
				'title'        => '',
				'method_title' => '',
			)
		);

		$result = StoreDash_API_Payment_Gateways::format_gateway( $gateway );

		$this->assertNull( $result['title'] );
		$this->assertNull( $result['method_title'] );
		$this->assertSame( array( 'products', 'refunds' ), $result['supports'] );
		$this->assertTrue( $result['supports_refunds'] );
	}

	public function test_build_order_payment_meta_maps_a_known_gateway_with_a_transaction(): void {
		$order   = new Fake_Payment_Order( 'stripe', 'Credit card (Stripe)', 'ch_3Nxxxx' );
		$gateway = new Fake_Payment_Gateway(
			array(
				'id'              => 'stripe',
				'enabled'         => 'yes',
				'supports'        => array( 'products', 'refunds' ),
				'method_title'    => 'Stripe',
				'transaction_url' => 'https://dashboard.stripe.com/payments/ch_3Nxxxx',
			)
		);

		$this->assertSame(
			array(
				'gateway_id'       => 'stripe',
				'gateway_title'    => 'Stripe',
				'supports_refunds' => true,
				'gateway_enabled'  => true,
				'transaction_id'   => 'ch_3Nxxxx',
				'transaction_url'  => 'https://dashboard.stripe.com/payments/ch_3Nxxxx',
			),
			StoreDash_API_Payment_Gateways::build_order_payment_meta( $order, $gateway )
		);
	}

	public function test_build_order_payment_meta_nulls_empty_transaction_fields(): void {
		$order   = new Fake_Payment_Order( 'cod', 'Cash on delivery', '' );
		$gateway = new Fake_Payment_Gateway(
			array(
				'id'              => 'cod',
				'enabled'         => 'no',
				'supports'        => array( 'products' ),
				'method_title'    => 'Cash on delivery',
				'transaction_url' => '',
			)
		);

		$result = StoreDash_API_Payment_Gateways::build_order_payment_meta( $order, $gateway );

		$this->assertNull( $result['transaction_id'] );
		$this->assertNull( $result['transaction_url'] );
		$this->assertFalse( $result['supports_refunds'] );
		$this->assertFalse( $result['gateway_enabled'] );
	}

	public function test_build_order_payment_meta_degrades_for_an_unknown_gateway(): void {
		// Gateway no longer installed: null capability object, but the order still
		// carries its historic method + title.
		$order = new Fake_Payment_Order( 'legacy_borgun', 'Borgun', 'txn_987' );

		$this->assertSame(
			array(
				'gateway_id'       => 'legacy_borgun',
				'gateway_title'    => 'Borgun',
				'supports_refunds' => false,
				'gateway_enabled'  => false,
				'transaction_id'   => 'txn_987',
				'transaction_url'  => null,
			),
			StoreDash_API_Payment_Gateways::build_order_payment_meta( $order, null )
		);
	}

	public function test_build_order_payment_meta_nulls_gateway_id_when_order_has_no_method(): void {
		$order  = new Fake_Payment_Order( '', '', '' );
		$result = StoreDash_API_Payment_Gateways::build_order_payment_meta( $order, null );

		$this->assertNull( $result['gateway_id'] );
		$this->assertNull( $result['gateway_title'] );
		$this->assertNull( $result['transaction_id'] );
	}
}
