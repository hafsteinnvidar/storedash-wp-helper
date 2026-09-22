<?php
/**
 * Unit tests for StoreDash_Dropp_Order_Meta::build_payload().
 *
 * build_payload() is the pure selection/mapping core of the Dropp consignment
 * REST exposer. The WC/$wpdb glue (the filter callback + Dropp_Consignment::from_order)
 * is verified manually against a live store (see the migration's deploy note); this
 * test pins the logic where the bugs actually live: filtering unbooked rows and
 * mapping the consignment fields.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

// Stub the two WordPress hook registrars so the controller file can load in the
// standalone (non-WordPress) harness. The constructors only call these.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

require_once __DIR__ . '/../../inc/api/dropp.php';

/**
 * @covers StoreDash_Dropp_Order_Meta::build_payload
 */
class Dropp_Order_Meta_PayloadTest extends TestCase {

	/**
	 * Build a consignment-like object with arbitrary props (models the Dropp model).
	 *
	 * @param array $props Properties to set.
	 * @return object
	 */
	private function consignment( array $props ): object {
		$obj = new stdClass();
		foreach ( $props as $k => $v ) {
			$obj->{$k} = $v;
		}
		return $obj;
	}

	public function test_maps_a_booked_consignment(): void {
		$payload = StoreDash_Dropp_Order_Meta::build_payload(
			array(
				$this->consignment(
					array(
						'barcode'          => 'DR157XBLJS584',
						'dropp_order_id'   => 'af32489d-a36b-420f-b2d1-2c55a602a098',
						'return_barcode'   => 'RET123',
						'status'           => 'initial',
						'shipping_item_id' => '42',
					)
				),
			)
		);

		$this->assertCount( 1, $payload );
		$this->assertSame(
			array(
				'barcode'          => 'DR157XBLJS584',
				'dropp_order_id'   => 'af32489d-a36b-420f-b2d1-2c55a602a098',
				'return_barcode'   => 'RET123',
				'status'           => 'initial',
				'shipping_item_id' => 42,
			),
			$payload[0]
		);
	}

	public function test_keeps_consignment_with_only_dropp_order_id(): void {
		$payload = StoreDash_Dropp_Order_Meta::build_payload(
			array( $this->consignment( array( 'dropp_order_id' => 'abc-123', 'status' => 'initial' ) ) )
		);

		$this->assertCount( 1, $payload );
		$this->assertNull( $payload[0]['barcode'] );
		$this->assertSame( 'abc-123', $payload[0]['dropp_order_id'] );
		$this->assertSame( 0, $payload[0]['shipping_item_id'] );
	}

	public function test_excludes_unbooked_statuses_even_with_a_barcode(): void {
		// A 'ready' draft already carries a barcode (Dropp issues it before the
		// order is booked), and 'error'/'overweight' are failed bookings — none
		// are real shipments, so they must be dropped even though a barcode exists.
		$payload = StoreDash_Dropp_Order_Meta::build_payload(
			array(
				$this->consignment( array( 'barcode' => 'DRREADY', 'dropp_order_id' => 'r-1', 'status' => 'ready' ) ),
				$this->consignment( array( 'barcode' => 'DRERROR', 'dropp_order_id' => 'e-1', 'status' => 'error' ) ),
				$this->consignment( array( 'barcode' => 'DROVER', 'dropp_order_id' => 'o-1', 'status' => 'overweight' ) ),
				$this->consignment( array( 'barcode' => 'DROK', 'dropp_order_id' => 'b-1', 'status' => 'initial' ) ),
			)
		);

		$this->assertCount( 1, $payload );
		$this->assertSame( 'DROK', $payload[0]['barcode'] );
		$this->assertSame( 'initial', $payload[0]['status'] );
	}

	public function test_skips_consignment_with_no_barcode_and_no_order_id(): void {
		$payload = StoreDash_Dropp_Order_Meta::build_payload(
			array(
				$this->consignment( array( 'barcode' => '   ', 'status' => 'ready' ) ),
				$this->consignment( array( 'status' => 'error' ) ),
			)
		);

		$this->assertSame( array(), $payload );
	}

	public function test_preserves_multiple_consignments(): void {
		$payload = StoreDash_Dropp_Order_Meta::build_payload(
			array(
				$this->consignment( array( 'barcode' => 'AAA', 'shipping_item_id' => 1 ) ),
				$this->consignment( array( 'barcode' => 'BBB', 'shipping_item_id' => 2 ) ),
			)
		);

		$this->assertCount( 2, $payload );
		$this->assertSame( 'AAA', $payload[0]['barcode'] );
		$this->assertSame( 'BBB', $payload[1]['barcode'] );
	}

	public function test_accepts_array_rows(): void {
		// Mirrors the $wpdb->get_results( ..., ARRAY_A ) shape.
		$payload = StoreDash_Dropp_Order_Meta::build_payload(
			array(
				array(
					'barcode'          => 'CCC',
					'dropp_order_id'   => null,
					'return_barcode'   => '',
					'status'           => 'transit',
					'shipping_item_id' => 7,
				),
			)
		);

		$this->assertCount( 1, $payload );
		$this->assertSame( 'CCC', $payload[0]['barcode'] );
		$this->assertNull( $payload[0]['dropp_order_id'] );
		$this->assertNull( $payload[0]['return_barcode'] );
		$this->assertSame( 'transit', $payload[0]['status'] );
		$this->assertSame( 7, $payload[0]['shipping_item_id'] );
	}
}
