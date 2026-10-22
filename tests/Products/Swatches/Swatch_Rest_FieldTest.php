<?php
/**
 * `storedash_swatch` term field: GET shape and PUT payload → meta writes.
 *
 * @package StoreDash\Tests\Products\Swatches
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Products\Swatches\Swatch_Rest_Field;

require_once __DIR__ . '/swatch-stubs.php';

/**
 * Minimal WP_REST_Request double for guard_request().
 */
class Swatch_Test_Request {
	/** @var string */
	private $method;
	/** @var string */
	private $route;
	/** @var array */
	public $params;

	public function __construct( string $method, string $route, array $params ) {
		$this->method = $method;
		$this->route  = $route;
		$this->params = $params;
	}
	public function get_method() {
		return $this->method;
	}
	public function get_route() {
		return $this->route;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
	public function set_param( $key, $value ) {
		$this->params[ $key ] = $value;
	}
}

/**
 * Field with a controllable capability check.
 */
class Swatch_Test_Guarded_Field extends Swatch_Rest_Field {
	/** @var bool */
	public $can = true;

	protected function can_edit_terms(): bool {
		return $this->can;
	}
}

/**
 * @covers \StoreDash\Products\Swatches\Swatch_Rest_Field
 */
class Swatch_Rest_FieldTest extends TestCase {

	/** @var string[] URLs passed to the image resolver. */
	private $resolved = array();

	protected function setUp(): void {
		Swatch_Test_Fixtures::reset();
		$this->resolved                      = array();
		$GLOBALS['__swatch_attachments'][55] = 'https://shop.example/wp-content/uploads/red.png';
	}

	private function field( bool $emran, bool $cartflows ): Swatch_Test_Guarded_Field {
		return new Swatch_Test_Guarded_Field(
			Swatch_Test_Fixtures::resolver( $emran, $cartflows ),
			function ( string $url ) {
				$this->resolved[] = $url;
				return 'https://bad.example/fail.png' === $url
					? new WP_Error( 'storedash_swatch_image_sideload_failed', 'nope', array( 'status' => 400 ) )
					: 77;
			},
			static function ( int $id ) {
				return in_array( $id, array( 55, 77 ), true );
			}
		);
	}

	private static function term( int $id ): object {
		return (object) array( 'term_id' => $id );
	}

	// ---- GET ----------------------------------------------------------------

	public function test_get_returns_null_without_provider(): void {
		$this->assertNull( $this->field( false, false )->get_value( array( 'id' => 7 ) ) );
	}

	public function test_get_shape_emran(): void {
		$GLOBALS['__swatch_meta'][7] = array(
			'product_attribute_color' => '#ff0000',
			'product_attribute_image' => 55,
		);

		$this->assertSame(
			array(
				'provider'  => 'emran',
				'color'     => '#ff0000',
				'color2'    => null,
				'image_id'  => 55,
				'image_url' => 'https://shop.example/wp-content/uploads/red-150x150.png',
				'tooltip'   => null,
			),
			$this->field( true, false )->get_value( array( 'id' => 7 ) )
		);
	}

	public function test_get_shape_cartflows(): void {
		$GLOBALS['__swatch_meta'][7] = array( 'cfvsw_color' => '#00ff00' );

		$this->assertSame(
			array(
				'provider'  => 'cartflows',
				'color'     => '#00ff00',
				'color2'    => null,
				'image_id'  => null,
				'image_url' => null,
				'tooltip'   => null,
			),
			$this->field( false, true )->get_value( array( 'id' => 7 ) )
		);
	}

	public function test_schema_is_nullable_object_with_readonly_outputs(): void {
		$schema = Swatch_Rest_Field::schema();
		$this->assertSame( array( 'object', 'null' ), $schema['type'] );
		$this->assertTrue( $schema['properties']['provider']['readonly'] );
		$this->assertTrue( $schema['properties']['image_url']['readonly'] );
		$this->assertContains( 'null', $schema['properties']['color']['type'] );
	}

	// ---- PUT ----------------------------------------------------------------

	public function test_put_without_provider_is_409(): void {
		$result = $this->field( false, false )->update_value( array( 'color' => '#ffffff' ), self::term( 7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_no_swatch_provider', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $GLOBALS['__swatch_meta_log'] );
	}

	public function test_put_emran_writes_meta(): void {
		$result = $this->field( true, false )->update_value(
			array(
				'color'    => ' #AbCdEf ',
				'color2'   => '#000',
				'image_id' => '55',
			),
			self::term( 7 )
		);

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				array( 'update', 7, 'product_attribute_color', '#AbCdEf' ),
				array( 'update', 7, 'secondary_color', '#000' ),
				array( 'update', 7, 'is_dual_color', 'yes' ),
				array( 'update', 7, 'product_attribute_image', 55 ),
			),
			$GLOBALS['__swatch_meta_log']
		);
	}

	public function test_put_null_and_empty_string_delete(): void {
		$this->field( true, false )->update_value(
			array(
				'color'    => '',
				'color2'   => null,
				'image_id' => null,
			),
			self::term( 7 )
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

	public function test_put_absent_keys_untouched(): void {
		$this->field( true, false )->update_value( array( 'color' => '#111111' ), self::term( 7 ) );
		$this->assertSame( array( array( 'update', 7, 'product_attribute_color', '#111111' ) ), $GLOBALS['__swatch_meta_log'] );
	}

	public function test_put_cartflows_ignores_color2_and_stores_image_url(): void {
		$result = $this->field( false, true )->update_value(
			array(
				'color'    => '#123456',
				'color2'   => '#654321',
				'image_id' => 55,
			),
			self::term( 7 )
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

	public function test_put_image_src_resolves_to_attachment_id(): void {
		$this->field( true, false )->update_value( array( 'image_src' => 'https://cdn.example/blue.jpg' ), self::term( 7 ) );

		$this->assertSame( array( 'https://cdn.example/blue.jpg' ), $this->resolved );
		$this->assertSame( array( array( 'update', 7, 'product_attribute_image', 77 ) ), $GLOBALS['__swatch_meta_log'] );
	}

	public function test_put_image_id_wins_over_image_src(): void {
		$this->field( true, false )->update_value(
			array(
				'image_id'  => 55,
				'image_src' => 'https://cdn.example/blue.jpg',
			),
			self::term( 7 )
		);

		$this->assertSame( array(), $this->resolved );
		$this->assertSame( array( array( 'update', 7, 'product_attribute_image', 55 ) ), $GLOBALS['__swatch_meta_log'] );
	}

	public function test_put_empty_image_src_deletes_image(): void {
		$this->field( true, false )->update_value( array( 'image_src' => '' ), self::term( 7 ) );
		$this->assertSame( array( array( 'delete', 7, 'product_attribute_image' ) ), $GLOBALS['__swatch_meta_log'] );
	}

	public function test_put_sideload_failure_returns_error_and_writes_nothing(): void {
		$result = $this->field( true, false )->update_value(
			array(
				'color'     => '#111111',
				'image_src' => 'https://bad.example/fail.png',
			),
			self::term( 7 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_swatch_image_sideload_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['__swatch_meta_log'] );
	}

	/**
	 * @dataProvider invalid_payloads
	 */
	public function test_put_rejects_invalid_payloads( $payload, string $code ): void {
		$result = $this->field( true, false )->update_value( $payload, self::term( 7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $GLOBALS['__swatch_meta_log'] );
		$this->assertSame( array(), $this->resolved );
	}

	public function invalid_payloads(): array {
		return array(
			'not an object'          => array( '#ffffff', 'storedash_invalid_swatch' ),
			'named color'            => array( array( 'color' => 'red' ), 'storedash_invalid_swatch_color' ),
			'rgb color'              => array( array( 'color2' => 'rgb(0,0,0)' ), 'storedash_invalid_swatch_color' ),
			'numeric color'          => array( array( 'color' => 123 ), 'storedash_invalid_swatch_color' ),
			'negative image id'      => array( array( 'image_id' => -5 ), 'storedash_invalid_swatch_image' ),
			'text image id'          => array( array( 'image_id' => 'abc' ), 'storedash_invalid_swatch_image' ),
			'not an attachment'      => array( array( 'image_id' => 999 ), 'storedash_invalid_swatch_image' ),
			'non-http image_src'     => array( array( 'image_src' => 'file:///etc/passwd' ), 'storedash_invalid_swatch_image' ),
			'bad color before image' => array(
				array(
					'color'     => 'nope',
					'image_src' => 'https://cdn.example/blue.jpg',
				),
				'storedash_invalid_swatch_color',
			),
		);
	}

	public function test_put_accepts_object_payload(): void {
		$result = $this->field( true, false )->update_value( (object) array( 'color' => '#222222' ), self::term( 7 ) );
		$this->assertTrue( $result );
	}

	public function test_put_zero_image_id_deletes(): void {
		$this->field( true, false )->update_value( array( 'image_id' => 0 ), self::term( 7 ) );
		$this->assertSame( array( array( 'delete', 7, 'product_attribute_image' ) ), $GLOBALS['__swatch_meta_log'] );
	}

	// ---- guard_request (pre-validation, because WC swallows field errors) ----

	public function test_guard_ignores_other_routes_reads_and_decided_responses(): void {
		$field = $this->field( false, false );
		$body  = array( Swatch_Rest_Field::FIELD => array( 'color' => 'red' ) );

		$this->assertNull( $field->guard_request( null, array(), new Swatch_Test_Request( 'PUT', '/wc/v3/products/7', $body ) ) );
		$this->assertNull( $field->guard_request( null, array(), new Swatch_Test_Request( 'GET', '/wc/v3/products/attributes/2/terms/7', $body ) ) );
		$this->assertNull( $field->guard_request( null, array(), new Swatch_Test_Request( 'PUT', '/wc/v3/products/attributes/2/terms/7', array( 'name' => 'Red' ) ) ) );

		$earlier = new WP_Error( 'rest_invalid_param', 'x', array( 'status' => 400 ) );
		$this->assertSame( $earlier, $field->guard_request( $earlier, array(), new Swatch_Test_Request( 'PUT', '/wc/v3/products/attributes/2/terms/7', $body ) ) );
	}

	public function test_guard_leaves_unauthorized_requests_to_woocommerce(): void {
		$field      = $this->field( false, false );
		$field->can = false;
		$request    = new Swatch_Test_Request( 'PUT', '/wc/v3/products/attributes/2/terms/7', array( Swatch_Rest_Field::FIELD => array( 'image_src' => 'https://cdn.example/blue.jpg' ) ) );

		$this->assertNull( $field->guard_request( null, array(), $request ) );
		$this->assertSame( array(), $this->resolved );
	}

	public function test_guard_single_put_without_provider_is_409(): void {
		$request = new Swatch_Test_Request( 'PUT', '/wc/v3/products/attributes/2/terms/7', array( Swatch_Rest_Field::FIELD => array( 'color' => '#ffffff' ) ) );
		$result  = $this->field( false, false )->guard_request( null, array(), $request );

		$this->assertSame( 'storedash_no_swatch_provider', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_guard_single_put_invalid_is_400(): void {
		$request = new Swatch_Test_Request( 'PUT', '/wc/v3/products/attributes/2/terms/7', array( Swatch_Rest_Field::FIELD => array( 'color' => 'red' ) ) );
		$result  = $this->field( true, false )->guard_request( null, array(), $request );

		$this->assertSame( 'storedash_invalid_swatch_color', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_guard_single_put_rewrites_value_with_resolved_image(): void {
		$request = new Swatch_Test_Request(
			'PUT',
			'/wc/v3/products/attributes/2/terms/7',
			array(
				Swatch_Rest_Field::FIELD => array(
					'color'     => ' #ABC ',
					'image_src' => 'https://cdn.example/blue.jpg',
					'provider'  => 'ignored',
				),
			)
		);

		$this->assertNull( $this->field( true, false )->guard_request( null, array(), $request ) );
		$this->assertSame(
			array(
				'color'    => '#ABC',
				'image_id' => 77,
			),
			$request->params[ Swatch_Rest_Field::FIELD ]
		);
		$this->assertSame( array(), $GLOBALS['__swatch_meta_log'] );
	}

	public function test_guard_batch_sanitizes_each_item_and_keeps_others(): void {
		$request = new Swatch_Test_Request(
			'POST',
			'/wc/v3/products/attributes/2/terms/batch',
			array(
				'create' => array( array( 'name' => 'Teal' ) ),
				'update' => array(
					array(
						'id'                     => 7,
						Swatch_Rest_Field::FIELD => array( 'image_src' => 'https://cdn.example/blue.jpg' ),
					),
					array(
						'id'   => 8,
						'name' => 'Renamed',
					),
					array(
						'id'                     => 9,
						Swatch_Rest_Field::FIELD => array( 'color' => '' ),
					),
				),
			)
		);

		$this->assertNull( $this->field( true, false )->guard_request( null, array(), $request ) );
		$this->assertSame( array( 'image_id' => 77 ), $request->params['update'][0][ Swatch_Rest_Field::FIELD ] );
		$this->assertSame( array( 'id' => 8, 'name' => 'Renamed' ), $request->params['update'][1] );
		$this->assertSame( array( 'color' => null ), $request->params['update'][2][ Swatch_Rest_Field::FIELD ] );
		$this->assertSame( array( array( 'name' => 'Teal' ) ), $request->params['create'] );
	}

	public function test_guard_batch_rejects_whole_batch_on_one_bad_item(): void {
		$request = new Swatch_Test_Request(
			'POST',
			'/wc/v3/products/attributes/2/terms/batch',
			array(
				'update' => array(
					array(
						'id'                     => 7,
						Swatch_Rest_Field::FIELD => array( 'color' => '#111111' ),
					),
					array(
						'id'                     => 8,
						Swatch_Rest_Field::FIELD => array( 'image_id' => 999 ),
					),
				),
			)
		);

		$result = $this->field( true, false )->guard_request( null, array(), $request );

		$this->assertSame( 'storedash_invalid_swatch_image', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame(
			array(
				'action' => 'update',
				'index'  => 1,
				'id'     => 8,
			),
			$result->get_error_data()['item']
		);
	}

	public function test_guard_batch_without_provider_is_409(): void {
		$request = new Swatch_Test_Request(
			'POST',
			'/wc/v3/products/attributes/2/terms/batch',
			array( 'update' => array( array( 'id' => 7, Swatch_Rest_Field::FIELD => array( 'color' => '#111111' ) ) ) )
		);

		$this->assertSame( 409, $this->field( false, false )->guard_request( null, array(), $request )->get_error_data()['status'] );
	}

	public function test_guard_covers_term_create(): void {
		$request = new Swatch_Test_Request( 'POST', '/wc/v3/products/attributes/2/terms', array( 'name' => 'Teal', Swatch_Rest_Field::FIELD => array( 'color' => 'teal' ) ) );
		$this->assertSame( 'storedash_invalid_swatch_color', $this->field( true, false )->guard_request( null, array(), $request )->get_error_code() );
	}
}
