<?php
/**
 * Unit tests for StoreDash\Products\Controllers\Products_Menu_Order_Controller.
 *
 * Route registration, permissions and the SQL itself are WordPress glue
 * verified on a live store; this test pins the pure logic: payload
 * normalization and the per-item decision (missing, unchanged, written,
 * write failed).
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Products\Controllers\Products_Menu_Order_Controller;

require_once __DIR__ . '/../../inc/Products/Controllers/Products_Menu_Order_Controller.php';

/**
 * @covers \StoreDash\Products\Controllers\Products_Menu_Order_Controller
 */
class Products_Menu_Order_ControllerTest extends TestCase {

	public function test_normalize_accepts_numeric_strings_zero_and_negatives(): void {
		$result = Products_Menu_Order_Controller::normalize_items(
			array(
				array( 'id' => '10', 'menu_order' => '3' ),
				array( 'id' => 11, 'menu_order' => 0 ),
				array( 'id' => 12, 'menu_order' => -2 ),
			)
		);

		$this->assertSame(
			array(
				array( 'id' => 10, 'menu_order' => 3 ),
				array( 'id' => 11, 'menu_order' => 0 ),
				array( 'id' => 12, 'menu_order' => -2 ),
			),
			$result['items']
		);
	}

	/**
	 * @dataProvider invalid_payloads
	 */
	public function test_normalize_rejects_bad_payloads( $raw, string $expected_code ): void {
		$result = Products_Menu_Order_Controller::normalize_items( $raw );
		$this->assertSame( $expected_code, $result['error']['code'] ?? null );
	}

	public function invalid_payloads(): array {
		$too_many = array();
		for ( $i = 1; $i <= Products_Menu_Order_Controller::MAX_ITEMS + 1; $i++ ) {
			$too_many[] = array( 'id' => $i, 'menu_order' => $i );
		}
		return array(
			'not an array'       => array( 'nope', 'empty_items' ),
			'empty'              => array( array(), 'empty_items' ),
			'too many'           => array( $too_many, 'too_many_items' ),
			'item not an object' => array( array( 5 ), 'invalid_item' ),
			'missing id'         => array( array( array( 'menu_order' => 1 ) ), 'invalid_item_id' ),
			'zero id'            => array( array( array( 'id' => 0, 'menu_order' => 1 ) ), 'invalid_item_id' ),
			'missing menu_order' => array( array( array( 'id' => 1 ) ), 'invalid_menu_order' ),
			'text menu_order'    => array( array( array( 'id' => 1, 'menu_order' => 'top' ) ), 'invalid_menu_order' ),
			'float menu_order'   => array( array( array( 'id' => 1, 'menu_order' => 1.5 ) ), 'invalid_menu_order' ),
			'duplicate id'       => array(
				array(
					array( 'id' => 1, 'menu_order' => 1 ),
					array( 'id' => 1, 'menu_order' => 2 ),
				),
				'duplicate_item',
			),
		);
	}

	public function test_process_writes_only_changed_rows_and_reports_each_item(): void {
		$controller = new Products_Menu_Order_Controller();
		$written    = array();

		$result = $controller->process(
			array(
				array( 'id' => 1, 'menu_order' => 1 ), // unchanged.
				array( 'id' => 2, 'menu_order' => 5 ), // changed.
				array( 'id' => 3, 'menu_order' => 2 ), // not a product on the store.
				array( 'id' => 4, 'menu_order' => 9 ), // write fails.
			),
			array(
				1 => 1,
				2 => 0,
				4 => 3,
			),
			static function ( int $id, int $menu_order ) use ( &$written ): bool {
				$written[] = array( $id, $menu_order );
				return 4 !== $id;
			}
		);

		$this->assertSame( array( array( 2, 5 ), array( 4, 9 ) ), $written );
		$this->assertSame( 1, $result['changed'] );
		$this->assertSame(
			array(
				array( 'id' => 1, 'ok' => true ),
				array( 'id' => 2, 'ok' => true ),
				array(
					'id'      => 3,
					'ok'      => false,
					'code'    => 'product_not_found',
					'message' => 'No product with this ID exists on the store.',
				),
				array(
					'id'      => 4,
					'ok'      => false,
					'code'    => 'db_update_failed',
					'message' => 'The position could not be saved.',
				),
			),
			$result['done']
		);
	}
}
