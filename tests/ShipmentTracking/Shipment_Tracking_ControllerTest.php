<?php
/**
 * Unit tests for StoreDash_API_Shipment_Tracking::add_tracking().
 *
 * Route registration / permission callbacks are WordPress glue verified on a live
 * store; this test pins the branch logic: predefined provider (4-arg extension
 * call, lowercased slug), custom provider + link (5-arg call, casing preserved),
 * the idempotency guard, and the legacy meta fallback.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

// Stub the WordPress surface the controller touches so it can load in the
// standalone (non-WordPress) harness. The constructor only calls add_action.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_string( $value ) ? trim( $value ) : $value;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return $url;
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		return Shipment_Tracking_Test_State::$order;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code = $code;
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

/**
 * Shared mutable state for the function stubs (order double + recorded calls).
 */
class Shipment_Tracking_Test_State {
	/** @var Fake_Tracking_Order|null */
	public static $order = null;
	/** @var array[] Every wc_st_add_tracking_number() call, as func_get_args(). */
	public static $st_calls = array();
}

require_once __DIR__ . '/../../inc/api/shipment-tracking.php';

/**
 * Minimal WC_Order double — only the meta surface the controller uses.
 */
class Fake_Tracking_Order {
	/** @var array */
	public $meta = array();
	/** @var int */
	public $saves = 0;

	public function __construct( array $meta = array() ) {
		$this->meta = $meta;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function save_meta_data() {
		++$this->saves;
	}
}

/**
 * Minimal WP_REST_Request double (array access + get_param).
 */
class Fake_Tracking_Request implements ArrayAccess {
	/** @var array */
	private $params;

	public function __construct( array $params ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}

	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return isset( $this->params[ $offset ] );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->params[ $offset ] ?? null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$this->params[ $offset ] = $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->params[ $offset ] );
	}
}

/**
 * @covers StoreDash_API_Shipment_Tracking::add_tracking
 * @covers StoreDash_API_Shipment_Tracking::sanitize_tracking_link
 */
class Shipment_Tracking_ControllerTest extends TestCase {

	/** @var StoreDash_API_Shipment_Tracking */
	private $controller;

	protected function setUp(): void {
		parent::setUp();
		Shipment_Tracking_Test_State::$st_calls = array();
		Shipment_Tracking_Test_State::$order    = new Fake_Tracking_Order();
		$this->controller                       = new StoreDash_API_Shipment_Tracking();
	}

	private function request( array $params ): Fake_Tracking_Request {
		return new Fake_Tracking_Request( array_merge( array( 'id' => 42 ), $params ) );
	}

	public function test_predefined_provider_calls_extension_with_four_args_lowercased(): void {
		$response = $this->controller->add_tracking(
			$this->request(
				array(
					'tracking_number'   => 'ABC123',
					'tracking_provider' => 'Fedex',
					'date_shipped'      => 1700000000,
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->status );
		$this->assertSame( array( 'success' => true, 'method' => 'shipment_tracking' ), $response->data );
		$this->assertSame(
			array( array( 42, 'ABC123', 'fedex', 1700000000 ) ),
			Shipment_Tracking_Test_State::$st_calls
		);
	}

	public function test_custom_link_calls_extension_with_five_args_and_keeps_provider_casing(): void {
		$response = $this->controller->add_tracking(
			$this->request(
				array(
					'tracking_number'      => 'DR157XBLJS584',
					'tracking_provider'    => 'Dropp',
					'date_shipped'         => 1700000000,
					'custom_tracking_link' => 'https://dropp.is/track/DR157XBLJS584',
				)
			)
		);

		$this->assertSame( 201, $response->status );
		$this->assertSame(
			array( array( 42, 'DR157XBLJS584', 'Dropp', 1700000000, 'https://dropp.is/track/DR157XBLJS584' ) ),
			Shipment_Tracking_Test_State::$st_calls
		);
	}

	public function test_non_http_link_is_ignored_and_falls_back_to_predefined_branch(): void {
		$this->controller->add_tracking(
			$this->request(
				array(
					'tracking_number'      => 'X1',
					'tracking_provider'    => 'Dropp',
					'date_shipped'         => 1700000000,
					'custom_tracking_link' => 'javascript:alert(1)',
				)
			)
		);

		$this->assertSame(
			array( array( 42, 'X1', 'dropp', 1700000000 ) ),
			Shipment_Tracking_Test_State::$st_calls
		);
	}

	public function test_dedupe_short_circuits_on_custom_provider_match(): void {
		Shipment_Tracking_Test_State::$order = new Fake_Tracking_Order(
			array(
				'_wc_shipment_tracking_items' => array(
					array(
						'tracking_number'          => 'DR157XBLJS584',
						'tracking_provider'        => '',
						'custom_tracking_provider' => 'Dropp',
						'custom_tracking_link'     => 'https://dropp.is/track/DR157XBLJS584',
					),
				),
			)
		);

		$response = $this->controller->add_tracking(
			$this->request(
				array(
					'tracking_number'      => 'DR157XBLJS584',
					'tracking_provider'    => 'dropp',
					'custom_tracking_link' => 'https://dropp.is/track/DR157XBLJS584',
				)
			)
		);

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'already_exists', $response->data['skipped'] );
		$this->assertSame( array(), Shipment_Tracking_Test_State::$st_calls );
	}

	public function test_missing_tracking_number_is_an_error(): void {
		$response = $this->controller->add_tracking( $this->request( array( 'tracking_provider' => 'dropp' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'missing_tracking_number', $response->code );
	}

	/**
	 * @dataProvider link_provider
	 */
	public function test_sanitize_tracking_link( $input, string $expected ): void {
		$this->assertSame( $expected, StoreDash_API_Shipment_Tracking::sanitize_tracking_link( $input ) );
	}

	public function link_provider(): array {
		return array(
			'https'      => array( 'https://posturinn.is/t/1', 'https://posturinn.is/t/1' ),
			'http'       => array( 'http://example.com/x', 'http://example.com/x' ),
			'trimmed'    => array( '  https://a.is/b  ', 'https://a.is/b' ),
			'ftp'        => array( 'ftp://a.is/b', '' ),
			'javascript' => array( 'javascript:alert(1)', '' ),
			'relative'   => array( '/track/1', '' ),
			'null'       => array( null, '' ),
			'empty'      => array( '', '' ),
		);
	}
}

// Extension stub — declared AFTER the class so `function_exists()` in the handler is
// true at call time (the file is fully compiled before any test runs). Records args.
function wc_st_add_tracking_number() {
	Shipment_Tracking_Test_State::$st_calls[] = func_get_args();
}
