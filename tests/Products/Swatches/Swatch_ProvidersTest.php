<?php
/**
 * Provider type mapping + term meta round trips + resolution order.
 *
 * @package StoreDash\Tests\Products\Swatches
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Products\Swatches\Cartflows_Provider;
use StoreDash\Products\Swatches\Emran_Provider;
use StoreDash\Products\Swatches\Provider_Resolver;

require_once __DIR__ . '/swatch-stubs.php';

/**
 * @covers \StoreDash\Products\Swatches\Emran_Provider
 * @covers \StoreDash\Products\Swatches\Cartflows_Provider
 * @covers \StoreDash\Products\Swatches\Abstract_Provider
 * @covers \StoreDash\Products\Swatches\Provider_Resolver
 */
class Swatch_ProvidersTest extends TestCase {

	protected function setUp(): void {
		Swatch_Test_Fixtures::reset();
	}

	// ---- Type mapping -------------------------------------------------------

	public function test_emran_types_are_identity_and_include_radio(): void {
		$emran = Swatch_Test_Fixtures::emran();

		$this->assertSame( array( 'select', 'color', 'image', 'button', 'radio' ), $emran->attribute_types() );
		foreach ( array( 'select', 'color', 'image', 'button', 'radio' ) as $type ) {
			$this->assertSame( $type, $emran->to_native_type( $type ) );
			$this->assertSame( $type, $emran->to_normalized_type( $type ) );
		}
	}

	public function test_cartflows_maps_button_to_label_and_back(): void {
		$cf = Swatch_Test_Fixtures::cartflows();

		$this->assertSame( 'label', $cf->to_native_type( 'button' ) );
		$this->assertSame( 'button', $cf->to_normalized_type( 'label' ) );
		$this->assertSame( 'color', $cf->to_native_type( 'color' ) );
		$this->assertSame( 'image', $cf->to_normalized_type( 'image' ) );
	}

	public function test_cartflows_has_no_radio(): void {
		$cf = Swatch_Test_Fixtures::cartflows();

		$this->assertNull( $cf->to_native_type( 'radio' ) );
		$this->assertNotContains( 'radio', $cf->attribute_types() );
	}

	public function test_unknown_native_type_normalizes_to_select(): void {
		$this->assertSame( 'select', Swatch_Test_Fixtures::emran()->to_normalized_type( 'wc-visual' ) );
		$this->assertSame( 'select', Swatch_Test_Fixtures::cartflows()->to_normalized_type( 'button' ) );
		$this->assertSame( 'select', Swatch_Test_Fixtures::emran()->to_normalized_type( '' ) );
	}

	public function test_native_type_labels_cover_every_native_type(): void {
		foreach ( array( Swatch_Test_Fixtures::emran(), Swatch_Test_Fixtures::cartflows() ) as $provider ) {
			foreach ( $provider->attribute_types() as $type ) {
				$this->assertArrayHasKey( $provider->to_native_type( $type ), $provider->native_type_labels() );
			}
		}
	}

	// ---- Emran meta ---------------------------------------------------------

	public function test_emran_read_full_shape(): void {
		$GLOBALS['__swatch_attachments'][55] = 'https://shop.example/wp-content/uploads/red.png';
		$GLOBALS['__swatch_meta'][7]         = array(
			Emran_Provider::META_COLOR   => '#ff0000',
			Emran_Provider::META_COLOR2  => '#00ff00',
			Emran_Provider::META_DUAL    => 'yes',
			Emran_Provider::META_IMAGE   => '55',
			Emran_Provider::META_TOOLTIP => ' Bright red ',
		);

		$this->assertSame(
			array(
				'color'     => '#ff0000',
				'color2'    => '#00ff00',
				'image_id'  => 55,
				'image_url' => 'https://shop.example/wp-content/uploads/red-150x150.png',
				'tooltip'   => 'Bright red',
			),
			Swatch_Test_Fixtures::emran()->read( 7 )
		);
	}

	public function test_emran_color2_hidden_unless_dual_color_on(): void {
		$GLOBALS['__swatch_meta'][7] = array(
			Emran_Provider::META_COLOR  => '#ff0000',
			Emran_Provider::META_COLOR2 => '#00ff00',
		);
		$this->assertNull( Swatch_Test_Fixtures::emran()->read( 7 )['color2'] );

		// Its own REST stores the flag as "1".
		$GLOBALS['__swatch_meta'][7][ Emran_Provider::META_DUAL ] = '1';
		$this->assertSame( '#00ff00', Swatch_Test_Fixtures::emran()->read( 7 )['color2'] );
	}

	public function test_emran_read_empty_term_is_all_null(): void {
		$this->assertSame(
			array(
				'color'     => null,
				'color2'    => null,
				'image_id'  => null,
				'image_url' => null,
				'tooltip'   => null,
			),
			Swatch_Test_Fixtures::emran()->read( 99 )
		);
	}

	public function test_emran_invalid_stored_color_reads_null(): void {
		$GLOBALS['__swatch_meta'][7] = array( Emran_Provider::META_COLOR => 'red' );
		$this->assertNull( Swatch_Test_Fixtures::emran()->read( 7 )['color'] );
	}

	public function test_emran_write_sets_and_deletes_meta(): void {
		$emran = Swatch_Test_Fixtures::emran();

		$emran->write(
			7,
			array(
				'color'    => '#123456',
				'color2'   => '#abcdef',
				'image_id' => 55,
			)
		);
		$this->assertSame(
			array(
				array( 'update', 7, 'product_attribute_color', '#123456' ),
				array( 'update', 7, 'secondary_color', '#abcdef' ),
				array( 'update', 7, 'is_dual_color', 'yes' ),
				array( 'update', 7, 'product_attribute_image', 55 ),
			),
			$GLOBALS['__swatch_meta_log']
		);

		$GLOBALS['__swatch_meta_log'] = array();
		$emran->write(
			7,
			array(
				'color'    => null,
				'color2'   => null,
				'image_id' => null,
			)
		);
		$this->assertSame(
			array(
				array( 'delete', 7, 'product_attribute_color' ),
				array( 'delete', 7, 'secondary_color' ),
				array( 'update', 7, 'is_dual_color', 'no' ),
				array( 'delete', 7, 'product_attribute_image' ),
			),
			$GLOBALS['__swatch_meta_log']
		);
	}

	public function test_emran_write_touches_only_present_keys(): void {
		Swatch_Test_Fixtures::emran()->write( 7, array( 'image_id' => 9 ) );
		$this->assertSame( array( array( 'update', 7, 'product_attribute_image', 9 ) ), $GLOBALS['__swatch_meta_log'] );
	}

	// ---- CartFlows meta -----------------------------------------------------

	public function test_cartflows_read_resolves_url_meta_to_attachment(): void {
		$GLOBALS['__swatch_attachments'][55] = 'https://shop.example/wp-content/uploads/red.png';
		$GLOBALS['__swatch_meta'][7]         = array(
			Cartflows_Provider::META_COLOR => '#ff0000',
			Cartflows_Provider::META_IMAGE => 'https://shop.example/wp-content/uploads/red.png',
		);

		$this->assertSame(
			array(
				'color'     => '#ff0000',
				'color2'    => null,
				'image_id'  => 55,
				'image_url' => 'https://shop.example/wp-content/uploads/red-150x150.png',
				'tooltip'   => null,
			),
			Swatch_Test_Fixtures::cartflows()->read( 7 )
		);
	}

	public function test_cartflows_read_keeps_foreign_url_without_attachment(): void {
		$GLOBALS['__swatch_meta'][7] = array( Cartflows_Provider::META_IMAGE => 'https://cdn.example/x.png' );

		$read = Swatch_Test_Fixtures::cartflows()->read( 7 );
		$this->assertNull( $read['image_id'] );
		$this->assertSame( 'https://cdn.example/x.png', $read['image_url'] );
	}

	public function test_cartflows_write_stores_attachment_url_and_ignores_color2(): void {
		$GLOBALS['__swatch_attachments'][55] = 'https://shop.example/wp-content/uploads/red.png';

		$result = Swatch_Test_Fixtures::cartflows()->write(
			7,
			array(
				'color'    => '#123456',
				'color2'   => '#abcdef',
				'image_id' => 55,
			)
		);

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				array( 'update', 7, 'cfvsw_color', '#123456' ),
				array( 'update', 7, 'cfvsw_image', 'https://shop.example/wp-content/uploads/red.png' ),
			),
			$GLOBALS['__swatch_meta_log']
		);
	}

	public function test_cartflows_write_null_deletes(): void {
		Swatch_Test_Fixtures::cartflows()->write(
			7,
			array(
				'color'    => null,
				'image_id' => null,
			)
		);
		$this->assertSame(
			array(
				array( 'delete', 7, 'cfvsw_color' ),
				array( 'delete', 7, 'cfvsw_image' ),
			),
			$GLOBALS['__swatch_meta_log']
		);
	}

	public function test_cartflows_write_unknown_attachment_errors_without_writing(): void {
		$result = Swatch_Test_Fixtures::cartflows()->write(
			7,
			array(
				'color'    => '#123456',
				'image_id' => 404,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $GLOBALS['__swatch_meta_log'] );
	}

	// ---- Resolution ---------------------------------------------------------

	public function test_resolver_none_active(): void {
		$this->assertNull( Swatch_Test_Fixtures::resolver( false, false )->active() );
	}

	public function test_resolver_prefers_emran_when_both_active_and_no_option(): void {
		$this->assertSame( 'emran', Swatch_Test_Fixtures::resolver( true, true )->active()->slug() );
	}

	public function test_resolver_falls_back_to_cartflows(): void {
		$this->assertSame( 'cartflows', Swatch_Test_Fixtures::resolver( false, true )->active()->slug() );
	}

	public function test_resolver_option_wins_when_that_plugin_is_active(): void {
		$GLOBALS['__seo_options'][ Provider_Resolver::OPTION ] = 'cartflows';
		$this->assertSame( 'cartflows', Swatch_Test_Fixtures::resolver( true, true )->active()->slug() );
	}

	public function test_resolver_ignores_option_for_inactive_plugin(): void {
		$GLOBALS['__seo_options'][ Provider_Resolver::OPTION ] = 'cartflows';
		$this->assertSame( 'emran', Swatch_Test_Fixtures::resolver( true, false )->active()->slug() );
	}

	public function test_resolver_ignores_unknown_option_value(): void {
		$GLOBALS['__seo_options'][ Provider_Resolver::OPTION ] = 'something-else';
		$this->assertNull( Swatch_Test_Fixtures::resolver( true, true )->preferred() );
		$this->assertSame( 'emran', Swatch_Test_Fixtures::resolver( true, true )->active()->slug() );
	}

	public function test_status_shape(): void {
		$this->assertSame(
			'{"provider":"cartflows","active":{"emran":null,"cartflows":{"version":"1.0.14"}},"types":["select","color","image","button"]}',
			json_encode( Swatch_Test_Fixtures::resolver( false, true )->status() )
		);

		$this->assertSame(
			'{"provider":null,"active":{"emran":null,"cartflows":null},"types":["select"]}',
			json_encode( Swatch_Test_Fixtures::resolver( false, false )->status() )
		);

		$this->assertSame( '{"provider":null,"active":{},"types":["select"]}', json_encode( ( new Provider_Resolver( array() ) )->status() ) );
	}
}
