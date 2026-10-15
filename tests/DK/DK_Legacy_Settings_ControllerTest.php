<?php
/**
 * Unit tests for StoreDash\Services\DK\DK_Legacy_Settings_Controller.
 *
 * Route registration, the wp_options prefix query and get_plugins() are
 * WordPress glue verified on a live store; this test pins the pure logic that
 * matters for safety: the DK API key (and any other secret-looking option)
 * never leaves the store, lock rows are dropped, and DKSync is found by its
 * main file name whatever folder it was installed into.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Services\DK\DK_Legacy_Settings_Controller;

// The controller self-instantiates at file scope and hooks rest_api_init.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

require_once __DIR__ . '/../../inc/services/dk/class-dk-legacy-settings-controller.php';

/**
 * @covers \StoreDash\Services\DK\DK_Legacy_Settings_Controller
 */
class DK_Legacy_Settings_ControllerTest extends TestCase {

	// ── filter_options ──────────────────────────────────────────────────

	public function test_filter_strips_api_key_and_keeps_mappings(): void {
		$result = DK_Legacy_Settings_Controller::filter_options(
			array(
				'dksync_api_key'                  => 'secret-dk-token',
				'dksync_api_payment_id_bacs'      => '3',
				'dksync_shipping_flat_rate'       => 'SEND1',
				'dksync_api_salesperson'          => '12',
				'dksync_price_field'              => 'UnitPrice1WithTax',
				'dksync_warehouse_field'          => 'bg1',
				'dksync_order_ssn'                => '',
				'dksync_tasks_run_task'           => 'none',
			)
		);

		$this->assertSame( array( 'dksync_api_key' ), $result['redacted'] );
		$this->assertArrayNotHasKey( 'dksync_api_key', $result['options'] );
		$this->assertNotContains( 'secret-dk-token', $result['options'] );
		$this->assertSame(
			array(
				'dksync_api_payment_id_bacs' => '3',
				'dksync_api_salesperson'     => '12',
				'dksync_order_ssn'           => '',
				'dksync_price_field'         => 'UnitPrice1WithTax',
				'dksync_shipping_flat_rate'  => 'SEND1',
				'dksync_tasks_run_task'      => 'none',
				'dksync_warehouse_field'     => 'bg1',
			),
			$result['options']
		);
	}

	public function test_filter_withholds_any_secret_looking_name(): void {
		$result = DK_Legacy_Settings_Controller::filter_options(
			array(
				'dksync_webhook_secret' => 'a',
				'dksync_API_TOKEN'      => 'b',
				'dksync_password'       => 'c',
				'dksync_license_key'    => 'd',
				'dksync_refunds'        => 'all',
			)
		);

		$this->assertSame( array( 'dksync_refunds' => 'all' ), $result['options'] );
		$this->assertCount( 4, $result['redacted'] );
	}

	public function test_filter_drops_lock_rows_and_foreign_options(): void {
		$result = DK_Legacy_Settings_Controller::filter_options(
			array(
				'dksync_lock_1234'          => 1700000000,
				'_transient_dksync_options' => array(),
				'woocommerce_currency'      => 'ISK',
				'dksync_shipping'           => 'yes',
			)
		);

		$this->assertSame( array( 'dksync_shipping' => 'yes' ), $result['options'] );
		$this->assertSame( array(), $result['redacted'] );
	}

	public function test_filter_keeps_non_string_values(): void {
		$result = DK_Legacy_Settings_Controller::filter_options(
			array(
				'dksync_webhook_created' => true,
				'dksync_future_map'      => array( 'a' => 1 ),
			)
		);

		$this->assertSame(
			array(
				'dksync_future_map'      => array( 'a' => 1 ),
				'dksync_webhook_created' => true,
			),
			$result['options']
		);
	}

	public function test_filter_empty_input(): void {
		$this->assertSame(
			array(
				'options'  => array(),
				'redacted' => array(),
			),
			DK_Legacy_Settings_Controller::filter_options( array() )
		);
	}

	// ── pick_plugin ─────────────────────────────────────────────────────

	public function test_pick_plugin_finds_dksync_in_any_folder(): void {
		$found = DK_Legacy_Settings_Controller::pick_plugin(
			array(
				'woocommerce/woocommerce.php' => array( 'Version' => '9.0.0' ),
				'DKSync-main/dksync.php'      => array( 'Version' => '1.2.0' ),
			),
			static function ( string $file ): bool {
				return 'DKSync-main/dksync.php' === $file;
			}
		);

		$this->assertSame(
			array(
				'file'    => 'DKSync-main/dksync.php',
				'active'  => true,
				'version' => '1.2.0',
			),
			$found
		);
	}

	public function test_pick_plugin_prefers_active_copy(): void {
		$found = DK_Legacy_Settings_Controller::pick_plugin(
			array(
				'dksync-old/dksync.php' => array( 'Version' => '1.0.0' ),
				'dksync/dksync.php'     => array( 'Version' => '1.2.0' ),
			),
			static function ( string $file ): bool {
				return 'dksync/dksync.php' === $file;
			}
		);

		$this->assertSame( 'dksync/dksync.php', $found['file'] );
		$this->assertTrue( $found['active'] );
	}

	public function test_pick_plugin_inactive_and_missing(): void {
		$inactive = DK_Legacy_Settings_Controller::pick_plugin(
			array( 'dksync/dksync.php' => array( 'Version' => '' ) ),
			static function (): bool {
				return false;
			}
		);
		$this->assertSame(
			array(
				'file'    => 'dksync/dksync.php',
				'active'  => false,
				'version' => null,
			),
			$inactive
		);

		$missing = DK_Legacy_Settings_Controller::pick_plugin(
			array( 'hello.php' => array( 'Version' => '1.7' ) ),
			static function (): bool {
				return true;
			}
		);
		$this->assertSame(
			array(
				'file'    => null,
				'active'  => false,
				'version' => null,
			),
			$missing
		);
	}
}
