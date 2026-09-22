<?php
/**
 * Unit tests for StoreDash\Products\Controllers\Products_Bulk_Controller.
 *
 * Route registration, permissions and the WooCommerce controller hand-off are
 * WordPress glue verified on a live store; this test pins the pure logic:
 * payload normalization, budget clamping, and the budgeted loop (deadline,
 * first-item guarantee, per-item error capture, remaining reporting).
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Products\Controllers\Products_Bulk_Controller;

require_once __DIR__ . '/../../inc/Products/Controllers/Products_Bulk_Controller.php';

/**
 * @covers \StoreDash\Products\Controllers\Products_Bulk_Controller
 */
class Products_Bulk_ControllerTest extends TestCase {

	protected function setUp(): void {
		StoreDash_Helpers::$logs = array();
	}

	// ── normalize_items ─────────────────────────────────────────────────

	public function test_normalize_accepts_products_and_variations(): void {
		$result = Products_Bulk_Controller::normalize_items(
			array(
				array( 'id' => '10', 'data' => array( 'status' => 'draft' ) ),
				array( 'id' => 55, 'parent_id' => '10', 'data' => array( 'regular_price' => '9.99' ) ),
			)
		);

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame(
			array(
				array( 'id' => 10, 'parent_id' => 0, 'data' => array( 'status' => 'draft' ) ),
				array( 'id' => 55, 'parent_id' => 10, 'data' => array( 'regular_price' => '9.99' ) ),
			),
			$result['items']
		);
	}

	/**
	 * @dataProvider invalid_payloads
	 */
	public function test_normalize_rejects_bad_payloads( $raw, string $expected_code ): void {
		$result = Products_Bulk_Controller::normalize_items( $raw );
		$this->assertSame( $expected_code, $result['error']['code'] ?? null );
	}

	public function invalid_payloads(): array {
		$too_many = array_fill( 0, Products_Bulk_Controller::MAX_ITEMS + 1, array( 'id' => 1, 'data' => array( 'a' => 1 ) ) );
		return array(
			'not an array'      => array( 'nope', 'empty_items' ),
			'empty'             => array( array(), 'empty_items' ),
			'too many'          => array( $too_many, 'too_many_items' ),
			'item not object'   => array( array( 5 ), 'invalid_item' ),
			'missing id'        => array( array( array( 'data' => array( 'a' => 1 ) ) ), 'invalid_item_id' ),
			'zero id'           => array( array( array( 'id' => 0, 'data' => array( 'a' => 1 ) ) ), 'invalid_item_id' ),
			'bad parent'        => array( array( array( 'id' => 1, 'parent_id' => 'x', 'data' => array( 'a' => 1 ) ) ), 'invalid_parent_id' ),
			'zero parent'       => array( array( array( 'id' => 1, 'parent_id' => 0, 'data' => array( 'a' => 1 ) ) ), 'invalid_parent_id' ),
			'empty data'        => array( array( array( 'id' => 1, 'data' => array() ) ), 'empty_item_data' ),
			'missing data'      => array( array( array( 'id' => 1 ) ), 'empty_item_data' ),
			'reserved id'       => array( array( array( 'id' => 1, 'data' => array( 'id' => 2 ) ) ), 'reserved_data_key' ),
			'reserved product'  => array( array( array( 'id' => 1, 'data' => array( 'product_id' => 2 ) ) ), 'reserved_data_key' ),
			'duplicate id'      => array(
				array(
					array( 'id' => 1, 'data' => array( 'a' => 1 ) ),
					array( 'id' => 1, 'data' => array( 'b' => 2 ) ),
				),
				'duplicate_item',
			),
		);
	}

	public function test_normalize_allows_same_id_under_different_parents(): void {
		// A variation id and a product id can never collide in WP, but the
		// dedupe key is (parent, id) so the guard never over-rejects.
		$result = Products_Bulk_Controller::normalize_items(
			array(
				array( 'id' => 7, 'data' => array( 'a' => 1 ) ),
				array( 'id' => 7, 'parent_id' => 3, 'data' => array( 'a' => 1 ) ),
			)
		);
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertCount( 2, $result['items'] );
	}

	// ── effective_budget ────────────────────────────────────────────────

	public function test_budget_is_clamped_to_min_and_max(): void {
		$this->assertSame( Products_Bulk_Controller::MIN_BUDGET_MS, Products_Bulk_Controller::effective_budget( 10, 0 ) );
		$this->assertSame( Products_Bulk_Controller::MAX_BUDGET_MS, Products_Bulk_Controller::effective_budget( 999999, 0 ) );
		$this->assertSame( 20000, Products_Bulk_Controller::effective_budget( 20000, 0 ) );
	}

	public function test_budget_respects_php_execution_limit(): void {
		// 30s limit → 24s ceiling, minus 2s already spent on bootstrap.
		$this->assertSame( 22000, Products_Bulk_Controller::effective_budget( 60000, 30, 2000 ) );
		// A generous limit does not cap a modest request.
		$this->assertSame( 20000, Products_Bulk_Controller::effective_budget( 20000, 300, 500 ) );
		// A nearly-exhausted limit never drops below the floor.
		$this->assertSame( Products_Bulk_Controller::MIN_BUDGET_MS, Products_Bulk_Controller::effective_budget( 20000, 5, 4500 ) );
	}

	// ── process ─────────────────────────────────────────────────────────

	private function items( int $count ): array {
		$items = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = array( 'id' => $i, 'parent_id' => 0, 'data' => array( 'status' => 'draft' ) );
		}
		return $items;
	}

	public function test_process_applies_every_item_inside_budget(): void {
		$controller = new Products_Bulk_Controller();
		$applied    = array();
		$result     = $controller->process(
			$this->items( 3 ),
			5000,
			static function ( array $item ) use ( &$applied ) {
				$applied[] = $item['id'];
				return array( 'ok' => true );
			},
			static function (): float {
				return 0.0;
			}
		);

		$this->assertSame( array( 1, 2, 3 ), $applied );
		$this->assertSame( array(), $result['remaining'] );
		$this->assertSame( 3, $result['processed'] );
		$this->assertSame(
			array(
				array( 'id' => 1, 'ok' => true ),
				array( 'id' => 2, 'ok' => true ),
				array( 'id' => 3, 'ok' => true ),
			),
			$result['done']
		);
		$this->assertSame( 5000, $result['budget_ms'] );
	}

	public function test_process_stops_at_deadline_and_reports_remaining(): void {
		$controller = new Products_Bulk_Controller();
		$clock_ms   = 0.0;
		$applied    = array();
		$result     = $controller->process(
			$this->items( 5 ),
			1000,
			static function ( array $item ) use ( &$applied, &$clock_ms ) {
				$applied[]  = $item['id'];
				$clock_ms  += 600; // each item costs 600ms
				return array( 'ok' => true );
			},
			static function () use ( &$clock_ms ): float {
				return $clock_ms;
			}
		);

		// t=0 item1 (→600), t=600 item2 (→1200), t=1200 ≥ deadline → 3,4,5 remain.
		$this->assertSame( array( 1, 2 ), $applied );
		$this->assertSame( array( 3, 4, 5 ), $result['remaining'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 1200, $result['elapsed_ms'] );
	}

	public function test_process_always_attempts_the_first_item(): void {
		$controller = new Products_Bulk_Controller();
		$clock_ms   = 0.0;
		$applied    = array();
		$result     = $controller->process(
			$this->items( 2 ),
			1000,
			static function ( array $item ) use ( &$applied, &$clock_ms ) {
				$applied[] = $item['id'];
				$clock_ms += 5000; // one slow item blows the whole budget
				return array( 'ok' => true );
			},
			static function () use ( &$clock_ms ): float {
				return $clock_ms;
			}
		);

		$this->assertSame( array( 1 ), $applied );
		$this->assertSame( array( 2 ), $result['remaining'] );
	}

	public function test_process_records_item_failures_without_stopping(): void {
		$controller = new Products_Bulk_Controller();
		$result     = $controller->process(
			$this->items( 3 ),
			5000,
			static function ( array $item ) {
				if ( 2 === $item['id'] ) {
					return array(
						'ok'      => false,
						'code'    => 'woocommerce_rest_product_invalid_id',
						'message' => 'Invalid ID.',
					);
				}
				return array( 'ok' => true );
			},
			static function (): float {
				return 0.0;
			}
		);

		$this->assertSame(
			array(
				'id'      => 2,
				'ok'      => false,
				'code'    => 'woocommerce_rest_product_invalid_id',
				'message' => 'Invalid ID.',
			),
			$result['done'][1]
		);
		$this->assertTrue( $result['done'][2]['ok'] );
		$this->assertSame( array(), $result['remaining'] );
	}

	public function test_process_turns_exceptions_into_item_failures_and_logs(): void {
		$controller = new Products_Bulk_Controller();
		$result     = $controller->process(
			$this->items( 2 ),
			5000,
			static function ( array $item ) {
				if ( 1 === $item['id'] ) {
					throw new RuntimeException( 'boom' );
				}
				return array( 'ok' => true );
			},
			static function (): float {
				return 0.0;
			}
		);

		$this->assertFalse( $result['done'][0]['ok'] );
		$this->assertSame( 'exception', $result['done'][0]['code'] );
		$this->assertSame( 'boom', $result['done'][0]['message'] );
		$this->assertTrue( $result['done'][1]['ok'] );
		$this->assertCount( 1, StoreDash_Helpers::$logs );
		$this->assertSame( 'error', StoreDash_Helpers::$logs[0]['level'] );
	}
}
