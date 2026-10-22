<?php
/**
 * Store API `extensions.storedash_swatches` product data.
 *
 * @package StoreDash\Tests\Products\Swatches
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Products\Swatches\Store_API_Extension;

require_once __DIR__ . '/swatch-stubs.php';

/**
 * Minimal WC_Product_Attribute double.
 */
class Swatch_Test_Attribute {
	/** @var string */
	private $name;
	/** @var array */
	private $options;
	/** @var bool */
	private $taxonomy;

	public function __construct( string $name, array $options, bool $taxonomy = true ) {
		$this->name     = $name;
		$this->options  = $options;
		$this->taxonomy = $taxonomy;
	}
	public function get_name() {
		return $this->name;
	}
	public function get_options() {
		return $this->options;
	}
	public function is_taxonomy() {
		return $this->taxonomy;
	}
}

/**
 * Minimal product double.
 */
class Swatch_Test_Product {
	/** @var array */
	private $attributes;

	public function __construct( array $attributes ) {
		$this->attributes = $attributes;
	}
	public function get_attributes() {
		return $this->attributes;
	}
}

/**
 * @covers \StoreDash\Products\Swatches\Store_API_Extension
 */
class Store_API_ExtensionTest extends TestCase {

	/** @var array Captured terms-loader calls. */
	private $term_loads = array();

	/** @var int Types-loader calls. */
	private $type_loads = 0;

	/** @var array taxonomy => stored attribute_type. */
	private $types = array();

	protected function setUp(): void {
		Swatch_Test_Fixtures::reset();
		$this->term_loads = array();
		$this->type_loads = 0;
		$this->types      = array(
			'pa_color' => 'color',
			'pa_size'  => 'select',
			'pa_style' => 'label',
		);

		$GLOBALS['__swatch_attachments'][55] = 'https://shop.example/wp-content/uploads/stripe.png';
	}

	private function extension( bool $emran, bool $cartflows ): Store_API_Extension {
		$slugs = array(
			10 => 'red',
			11 => 'blue',
			12 => 'green',
			13 => '42',
			20 => 'small',
			30 => 'classic',
		);

		return new Store_API_Extension(
			Swatch_Test_Fixtures::resolver( $emran, $cartflows ),
			function () {
				++$this->type_loads;
				return $this->types;
			},
			function ( string $taxonomy, array $ids ) use ( $slugs ) {
				$this->term_loads[] = array( $taxonomy, $ids );
				$terms              = array();
				foreach ( $ids as $id ) {
					if ( isset( $slugs[ $id ] ) ) {
						$terms[] = (object) array(
							'term_id' => $id,
							'slug'    => $slugs[ $id ],
						);
					}
				}
				return $terms;
			}
		);
	}

	private static function product(): Swatch_Test_Product {
		return new Swatch_Test_Product(
			array(
				'pa_color'  => new Swatch_Test_Attribute( 'pa_color', array( 10, 11, 12, 13 ) ),
				'pa_size'   => new Swatch_Test_Attribute( 'pa_size', array( 20 ) ),
				'pa_style'  => new Swatch_Test_Attribute( 'pa_style', array( 30 ) ),
				'engraving' => new Swatch_Test_Attribute( 'engraving', array( 'Yes', 'No' ), false ),
			)
		);
	}

	public function test_empty_array_without_provider(): void {
		$this->assertSame( array(), $this->extension( false, false )->data_callback( self::product() ) );
		$this->assertSame( array(), $this->term_loads );
	}

	public function test_empty_for_non_product(): void {
		$this->assertSame( array(), $this->extension( true, false )->data_callback( null ) );
	}

	public function test_emran_shape(): void {
		$GLOBALS['__swatch_meta'][10] = array( 'product_attribute_color' => '#ff0000' );
		$GLOBALS['__swatch_meta'][11] = array(
			'product_attribute_color' => '#0000ff',
			'secondary_color'         => '#ffffff',
			'is_dual_color'           => 'yes',
		);
		$GLOBALS['__swatch_meta'][13] = array( 'product_attribute_image' => 55 );
		// 12 has nothing → omitted.

		$data = $this->extension( true, false )->data_callback( self::product() );

		// pa_size is select → skipped; pa_style is "label" which Emran doesn't know → select → skipped.
		$this->assertSame( array( 'pa_color' ), array_keys( $data ) );
		$this->assertSame( 'color', $data['pa_color']['type'] );
		$this->assertSame(
			'{"red":{"color":"#ff0000"},"blue":{"color":"#0000ff","color2":"#ffffff"},"42":{"image_url":"https:\/\/shop.example\/wp-content\/uploads\/stripe-150x150.png"}}',
			json_encode( $data['pa_color']['terms'] )
		);
	}

	public function test_cartflows_maps_label_to_button_and_includes_empty_terms_as_object(): void {
		$GLOBALS['__swatch_meta'][10] = array( 'cfvsw_color' => '#ff0000' );

		$data = $this->extension( false, true )->data_callback( self::product() );

		$this->assertSame( array( 'pa_color', 'pa_style' ), array_keys( $data ) );
		$this->assertSame( 'button', $data['pa_style']['type'] );
		$this->assertSame( '{}', json_encode( $data['pa_style']['terms'] ) );
		$this->assertSame( '{"red":{"color":"#ff0000"}}', json_encode( $data['pa_color']['terms'] ) );
	}

	public function test_cartflows_image_url_is_stored_url(): void {
		$GLOBALS['__swatch_meta'][11] = array( 'cfvsw_image' => 'https://shop.example/wp-content/uploads/stripe.png' );

		$data = $this->extension( false, true )->data_callback( self::product() );
		$this->assertSame(
			array( 'image_url' => 'https://shop.example/wp-content/uploads/stripe.png' ),
			( (array) $data['pa_color']['terms'] )['blue']
		);
	}

	public function test_caches_types_and_terms_across_products(): void {
		$GLOBALS['__swatch_meta'][10] = array( 'product_attribute_color' => '#ff0000' );
		$extension                    = $this->extension( true, false );

		$extension->data_callback( self::product() );
		$extension->data_callback( self::product() );
		$extension->data_callback(
			new Swatch_Test_Product(
				array( 'pa_color' => new Swatch_Test_Attribute( 'pa_color', array( 10, 14 ) ) )
			)
		);
		// 14 does not exist: remembered as missing, not re-queried.
		$extension->data_callback(
			new Swatch_Test_Product(
				array( 'pa_color' => new Swatch_Test_Attribute( 'pa_color', array( 14 ) ) )
			)
		);

		$this->assertSame( 1, $this->type_loads );
		$this->assertSame(
			array(
				array( 'pa_color', array( 10, 11, 12, 13 ) ),
				array( 'pa_color', array( 14 ) ),
			),
			$this->term_loads
		);
	}

	public function test_variation_style_string_attributes_are_ignored(): void {
		$variation = new Swatch_Test_Product( array( 'pa_color' => 'red' ) );
		$this->assertSame( array(), $this->extension( true, false )->data_callback( $variation ) );
	}

	public function test_schema_is_empty(): void {
		$this->assertSame( array(), $this->extension( true, false )->schema_callback() );
	}

	// ---- objectify_empty (empty extension must encode as {}) ----------------

	private static function store_request( string $route ): object {
		return new class( $route ) {
			/** @var string */
			private $route;
			public function __construct( string $route ) {
				$this->route = $route;
			}
			public function get_route() {
				return $this->route;
			}
		};
	}

	private static function product_payload( $swatches ): array {
		return array(
			'id'         => 1,
			'extensions' => (object) array(
				'storedash_swatches'  => $swatches,
				'storedash_discounts' => array(),
			),
		);
	}

	public function test_objectify_single_product_empty_becomes_object(): void {
		$response = new WP_REST_Response( self::product_payload( array() ) );
		$this->extension( false, false )->objectify_empty( $response, array(), self::store_request( '/wc/store/v1/products/1' ) );

		$this->assertSame(
			'{"id":1,"extensions":{"storedash_swatches":{},"storedash_discounts":[]}}',
			json_encode( $response->get_data() )
		);
	}

	public function test_objectify_collection_and_keeps_non_empty(): void {
		$full     = array(
			'pa_color' => array(
				'type'  => 'color',
				'terms' => (object) array(),
			),
		);
		$response = new WP_REST_Response( array( self::product_payload( array() ), self::product_payload( $full ) ) );
		$this->extension( true, false )->objectify_empty( $response, array(), self::store_request( '/wc/store/v1/products' ) );

		$this->assertSame(
			'[{"id":1,"extensions":{"storedash_swatches":{},"storedash_discounts":[]}},{"id":1,"extensions":{"storedash_swatches":{"pa_color":{"type":"color","terms":{}}},"storedash_discounts":[]}}]',
			json_encode( $response->get_data() )
		);
	}

	public function test_objectify_ignores_non_store_routes_and_errors(): void {
		$response = new WP_REST_Response( self::product_payload( array() ) );
		$this->extension( true, false )->objectify_empty( $response, array(), self::store_request( '/wc/v3/products/1' ) );
		$this->assertSame( array(), $response->get_data()['extensions']->storedash_swatches );

		$error = new WP_Error( 'x', 'y' );
		$this->assertSame( $error, $this->extension( true, false )->objectify_empty( $error, array(), self::store_request( '/wc/store/v1/products' ) ) );
	}
}
