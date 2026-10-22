<?php
/**
 * storedash/v1/swatches routes: provider save + attribute type writes.
 *
 * @package StoreDash\Tests\Products\Swatches
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Products\Swatches\Provider_Resolver;
use StoreDash\Products\Swatches\Swatches_Controller;

require_once __DIR__ . '/swatch-stubs.php';

/**
 * @covers \StoreDash\Products\Swatches\Swatches_Controller
 */
class Swatches_ControllerTest extends TestCase {

	/** @var array Captured attribute writes. */
	private $writes = array();

	protected function setUp(): void {
		Swatch_Test_Fixtures::reset();
		$this->writes = array();
	}

	private function controller( bool $emran, bool $cartflows ): Swatches_Controller {
		return new Swatches_Controller(
			Swatch_Test_Fixtures::resolver( $emran, $cartflows ),
			static function ( int $id ) {
				if ( 3 !== $id ) {
					return null;
				}
				return (object) array(
					'id'           => 3,
					'name'         => 'Color',
					'slug'         => 'pa_color',
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				);
			},
			function ( int $id, array $args, $provider ) {
				$this->writes[] = array( $id, $args, $provider->slug() );
				return $id;
			}
		);
	}

	public function test_type_write_emran_keeps_existing_fields(): void {
		$result = $this->controller( true, false )->apply_attribute_type( 3, 'radio' );

		$this->assertSame(
			array(
				'id'   => 3,
				'type' => 'radio',
			),
			$result
		);
		$this->assertSame(
			array(
				array(
					3,
					array(
						'name'         => 'Color',
						'slug'         => 'pa_color',
						'type'         => 'radio',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					),
					'emran',
				),
			),
			$this->writes
		);
	}

	public function test_type_write_cartflows_maps_button_to_label(): void {
		$result = $this->controller( false, true )->apply_attribute_type( 3, 'button' );

		$this->assertSame( 'button', $result['type'] );
		$this->assertSame( 'label', $this->writes[0][1]['type'] );
		$this->assertSame( 'cartflows', $this->writes[0][2] );
	}

	public function test_type_write_cartflows_rejects_radio_with_422(): void {
		$result = $this->controller( false, true )->apply_attribute_type( 3, 'radio' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_swatch_type_unsupported', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_type_write_unknown_type_is_422(): void {
		$result = $this->controller( true, false )->apply_attribute_type( 3, 'mixed' );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_type_write_without_provider_is_409(): void {
		$result = $this->controller( false, false )->apply_attribute_type( 3, 'color' );

		$this->assertSame( 'storedash_no_swatch_provider', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_type_write_unknown_attribute_is_404(): void {
		$result = $this->controller( true, false )->apply_attribute_type( 99, 'color' );

		$this->assertSame( 'storedash_attribute_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_type_write_error_without_status_becomes_400(): void {
		$controller = new Swatches_Controller(
			Swatch_Test_Fixtures::resolver( true, false ),
			static function () {
				return (object) array(
					'name'         => 'Color',
					'slug'         => 'pa_color',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				);
			},
			static function () {
				return new WP_Error( 'invalid_product_attribute_slug_too_long', 'Slug too long' );
			}
		);

		$result = $controller->apply_attribute_type( 3, 'color' );
		$this->assertSame( 'invalid_product_attribute_slug_too_long', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_save_provider_sets_and_clears_option(): void {
		$controller = $this->controller( true, true );

		$this->assertTrue( $controller->save_provider( 'cartflows' ) );
		$this->assertSame( 'cartflows', $GLOBALS['__seo_options'][ Provider_Resolver::OPTION ] );

		$this->assertTrue( $controller->save_provider( null ) );
		$this->assertArrayNotHasKey( Provider_Resolver::OPTION, $GLOBALS['__seo_options'] );
	}

	public function test_save_provider_rejects_unknown(): void {
		$result = $this->controller( true, true )->save_provider( 'other' );

		$this->assertSame( 'storedash_invalid_swatch_provider', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_status_response(): void {
		$GLOBALS['__seo_options'][ Provider_Resolver::OPTION ] = 'cartflows';
		$response = $this->controller( true, true )->get_status();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cartflows', $response->get_data()['provider'] );
		$this->assertSame( array( 'version' => '2.6.0' ), $response->get_data()['active']->emran );
	}
}
