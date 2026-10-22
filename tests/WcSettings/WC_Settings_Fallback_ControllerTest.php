<?php
/**
 * Unit tests for StoreDash\Services\WC_Settings\WC_Settings_Fallback_Controller.
 *
 * The REST glue (route registration, WooCommerce's controller) is verified on a
 * live store. This pins the one decision that keeps healthy stores untouched:
 * the core page is registered ONLY when WooCommerce has no settings for the
 * group, at most once per request, and only for the four mirrored groups.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Services\WC_Settings\WC_Settings_Fallback_Controller;

// The controller self-instantiates at file scope and hooks rest_api_init.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

// Records every registrar WooCommerce would have been handed.
if ( ! class_exists( 'WC_Register_WP_Admin_Settings' ) ) {
	class WC_Register_WP_Admin_Settings {
		/** @var array<int, array{0: object, 1: string}> */
		public static $created = array();

		public function __construct( $object, $type ) {
			self::$created[] = array( $object, $type );
		}
	}
}
if ( ! class_exists( 'WC_Settings_General', false ) ) {
	class WC_Settings_General {}
}
if ( ! class_exists( 'WC_Settings_Products', false ) ) {
	class WC_Settings_Products {}
}

require_once __DIR__ . '/../../inc/services/wc-settings/class-wc-settings-fallback-controller.php';

/**
 * Controller with WooCommerce's group lookup replaced by a fixed answer.
 */
class Testable_WC_Settings_Fallback_Controller extends WC_Settings_Fallback_Controller {
	/** @var array<string, bool> */
	public $registered = array();

	/** @var string[] */
	public $lookups = array();

	protected function group_has_settings( string $group_id ): bool {
		$this->lookups[] = $group_id;
		return ! empty( $this->registered[ $group_id ] );
	}
}

/**
 * @covers \StoreDash\Services\WC_Settings\WC_Settings_Fallback_Controller
 */
class WC_Settings_Fallback_ControllerTest extends TestCase {

	protected function setUp(): void {
		WC_Register_WP_Admin_Settings::$created = array();
	}

	public function test_healthy_group_is_left_alone(): void {
		$controller             = new Testable_WC_Settings_Fallback_Controller();
		$controller->registered = array( 'general' => true );

		$controller->ensure_group_registered( 'general' );

		$this->assertSame( array(), WC_Register_WP_Admin_Settings::$created );
	}

	public function test_missing_group_registers_the_core_page(): void {
		$controller = new Testable_WC_Settings_Fallback_Controller();

		$controller->ensure_group_registered( 'general' );

		$this->assertCount( 1, WC_Register_WP_Admin_Settings::$created );
		list( $page, $type ) = WC_Register_WP_Admin_Settings::$created[0];
		$this->assertInstanceOf( WC_Settings_General::class, $page );
		$this->assertSame( 'page', $type );
	}

	public function test_each_group_gets_its_own_page(): void {
		$controller = new Testable_WC_Settings_Fallback_Controller();

		$controller->ensure_group_registered( 'products' );

		$this->assertInstanceOf( WC_Settings_Products::class, WC_Register_WP_Admin_Settings::$created[0][0] );
	}

	public function test_repeat_calls_register_once(): void {
		$controller = new Testable_WC_Settings_Fallback_Controller();

		$controller->ensure_group_registered( 'general' );
		$controller->ensure_group_registered( 'general' );

		$this->assertCount( 1, WC_Register_WP_Admin_Settings::$created );
		$this->assertSame( array( 'general' ), $controller->lookups );
	}

	public function test_unknown_group_is_ignored(): void {
		$controller = new Testable_WC_Settings_Fallback_Controller();

		$controller->ensure_group_registered( 'advanced' );
		$controller->ensure_group_registered( 'email_new_order' );

		$this->assertSame( array(), WC_Register_WP_Admin_Settings::$created );
		$this->assertSame( array(), $controller->lookups );
	}

	public function test_only_the_four_mirrored_groups_are_served(): void {
		$this->assertSame(
			array( 'general', 'products', 'tax', 'shipping' ),
			array_keys( WC_Settings_Fallback_Controller::GROUPS )
		);
	}
}
