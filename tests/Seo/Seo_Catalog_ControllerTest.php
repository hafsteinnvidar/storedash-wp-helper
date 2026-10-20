<?php
/**
 * Seo_Catalog_Controller: line format, pagination headers, gate, cache.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Controllers\Seo_Catalog_Controller;
use StoreDash\Seo\Settings;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Controllers\Seo_Catalog_Controller
 */
class Seo_Catalog_ControllerTest extends TestCase {

	const TOKEN = '0123456789abcdef0123456789abcdef';

	/** @var array */
	private $fetches = array();
	/** @var array */
	private $cache = array();

	protected function setUp(): void {
		$this->fetches             = array();
		$this->cache               = array();
		$GLOBALS['__seo_options'] = array(
			Settings::ENABLED_OPTION                   => '1',
			Settings::OPTION                           => array( 'llms_txt_enabled' => true ),
			Seo_Catalog_Controller::TOKEN_OPTION       => self::TOKEN,
		);
		Settings::reset_cache();
	}

	protected function tearDown(): void {
		Settings::reset_cache();
	}

	private function controller( int $total = 1200 ): Seo_Catalog_Controller {
		$self = $this;
		return new Seo_Catalog_Controller(
			function ( $page, $per_page ) use ( $self, $total ) {
				$self->fetches[] = $page;
				$rows            = array();
				$start           = ( $page - 1 ) * $per_page;
				for ( $i = $start; $i < min( $total, $start + $per_page ); $i++ ) {
					$rows[] = array(
						'name'         => 'Product ' . $i,
						'price'        => '10.00',
						'currency'     => 'ISK',
						'stock_status' => 'instock',
						'url'          => 'https://shop.test/product/p' . $i . '/',
					);
				}
				return array( 'rows' => $rows, 'total' => $total );
			},
			function ( $op, $key, $value = null ) use ( $self ) {
				if ( 'get' === $op ) {
					return isset( $self->cache[ $key ] ) ? $self->cache[ $key ] : false;
				}
				$self->cache[ $key ] = $value;
				return true;
			}
		);
	}

	private function request( $page, $token = self::TOKEN ) {
		return new class( $page, $token ) {
			/** @var mixed */
			private $page;
			/** @var mixed */
			private $token;
			public function __construct( $page, $token ) {
				$this->page  = $page;
				$this->token = $token;
			}
			public function get_param( $name ) {
				if ( 'token' === $name ) {
					return $this->token;
				}
				return 'page' === $name ? $this->page : null;
			}
		};
	}

	public function test_line_format() {
		$this->assertSame(
			'Blue Shirt / XL | 19.90 USD | in_stock | https://shop.test/product/blue-shirt/',
			Seo_Catalog_Controller::line( array( 'name' => " Blue  Shirt | XL\n", 'price' => '19.90', 'currency' => 'USD', 'stock_status' => 'instock', 'url' => 'https://shop.test/product/blue-shirt/' ) )
		);
		$this->assertSame( 'X | n/a | unknown | https://shop.test/x/', Seo_Catalog_Controller::line( array( 'name' => 'X', 'url' => 'https://shop.test/x/' ) ) );
		$this->assertSame( 'X | 5 EUR | backorder | https://shop.test/x/', Seo_Catalog_Controller::line( array( 'name' => 'X', 'price' => '5', 'currency' => 'EUR', 'stock_status' => 'onbackorder', 'url' => 'https://shop.test/x/' ) ) );
		$this->assertSame( 'X | 5 EUR | out_of_stock | https://shop.test/x/', Seo_Catalog_Controller::line( array( 'name' => 'X', 'price' => '5', 'currency' => 'EUR', 'stock_status' => 'outofstock', 'url' => 'https://shop.test/x/' ) ) );
		$this->assertSame( '', Seo_Catalog_Controller::line( array( 'name' => 'No URL' ) ) );
		$this->assertSame( '', Seo_Catalog_Controller::line( array( 'url' => 'https://shop.test/x/' ) ) );
	}

	public function test_build_headers_and_body() {
		$rows = array(
			array( 'name' => 'A', 'price' => '1.00', 'currency' => 'ISK', 'stock_status' => 'instock', 'url' => 'https://shop.test/a/' ),
			array( 'name' => 'B', 'price' => '2.00', 'currency' => 'ISK', 'stock_status' => 'instock', 'url' => 'https://shop.test/b/' ),
			'junk',
		);
		$out  = Seo_Catalog_Controller::build( $rows, 2, 2, 5, 'https://shop.test/wp-json/storedash/v1/seo/catalog.txt' );
		$this->assertSame( "A | 1.00 ISK | in_stock | https://shop.test/a/\nB | 2.00 ISK | in_stock | https://shop.test/b/\n", $out['body'] );
		$this->assertSame( 'text/plain; charset=UTF-8', $out['headers']['Content-Type'] );
		$this->assertSame( '5', $out['headers']['X-Total-Count'] );
		$this->assertSame( '<https://shop.test/wp-json/storedash/v1/seo/catalog.txt?page=3>; rel="next"', $out['headers']['Link'] );

		$last = Seo_Catalog_Controller::build( $rows, 3, 2, 5, 'https://shop.test/wp-json/storedash/v1/seo/catalog.txt' );
		$this->assertArrayNotHasKey( 'Link', $last['headers'], 'no next page' );
		$this->assertSame( '', Seo_Catalog_Controller::build( array(), 9, 2, 5, 'x' )['body'] );
		$this->assertSame( 'https://shop.test/?rest_route=/storedash/v1/seo/catalog.txt&page=2', Seo_Catalog_Controller::page_url( 'https://shop.test/?rest_route=/storedash/v1/seo/catalog.txt', 2 ), 'plain permalinks keep the query' );
	}

	/**
	 * @dataProvider page_cases
	 */
	public function test_page_number_validation( $raw, $expected ) {
		$this->assertSame( $expected, Seo_Catalog_Controller::page_number( $raw ) );
	}

	public function page_cases(): array {
		return array(
			array( null, 1 ),
			array( '', 1 ),
			array( 3, 3 ),
			array( '7', 7 ),
			array( 10000, 10000 ),
			array( 10001, null ),
			array( 0, null ),
			array( '-1', null ),
			array( '1e3', null ),
			array( 'abc', null ),
		);
	}

	public function test_404_when_llms_txt_disabled_or_module_off() {
		$GLOBALS['__seo_options'][ Settings::OPTION ] = array( 'llms_txt_enabled' => false );
		Settings::reset_cache();
		$out = $this->controller()->get_catalog( $this->request( null ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'storedash_seo_catalog_disabled', $out->get_error_code() );
		$this->assertSame( 404, $out->get_error_data()['status'] );
		$this->assertSame( array(), $this->fetches, 'nothing fetched' );

		$GLOBALS['__seo_options'][ Settings::OPTION ]         = array( 'llms_txt_enabled' => true );
		$GLOBALS['__seo_options'][ Settings::ENABLED_OPTION ] = '0';
		Settings::reset_cache();
		$this->assertSame( 404, $this->controller()->get_catalog( $this->request( null ) )->get_error_data()['status'] );
	}

	public function test_400_on_bad_page() {
		$out = $this->controller()->get_catalog( $this->request( '99999999' ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 400, $out->get_error_data()['status'] );
	}

	public function test_success_response_is_plain_text_with_pagination_headers() {
		$out = $this->controller( 1200 )->get_catalog( $this->request( '2' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $out );
		$this->assertSame( 200, $out->get_status() );
		$headers = $out->get_headers();
		$this->assertSame( 'text/plain; charset=UTF-8', $headers['Content-Type'] );
		$this->assertSame( '1200', $headers['X-Total-Count'] );
		$this->assertSame( '<https://shop.test/wp-json/storedash/v1/seo/catalog.txt?token=' . self::TOKEN . '&page=3>; rel="next"', $headers['Link'] );
		$lines = explode( "\n", rtrim( $out->get_data(), "\n" ) );
		$this->assertCount( 500, $lines );
		$this->assertSame( 'Product 500 | 10.00 ISK | in_stock | https://shop.test/product/p500/', $lines[0] );
		$this->assertSame( array( 2 ), $this->fetches );

		$page3 = $this->controller( 1200 )->get_catalog( $this->request( 3 ) );
		$this->assertArrayNotHasKey( 'Link', $page3->get_headers() );
		$this->assertCount( 200, explode( "\n", rtrim( $page3->get_data(), "\n" ) ) );
	}

	public function test_page_cache_hit_skips_the_fetcher_and_version_bump_invalidates() {
		$controller = $this->controller( 10 );
		$controller->page( 1, 'https://shop.test/wp-json/storedash/v1/seo/catalog.txt' );
		$controller->page( 1, 'https://shop.test/wp-json/storedash/v1/seo/catalog.txt' );
		$this->assertSame( array( 1 ), $this->fetches, 'second call served from cache' );
		$this->assertSame( array( Seo_Catalog_Controller::TRANSIENT_PREFIX . '0_total', Seo_Catalog_Controller::TRANSIENT_PREFIX . '0_p1' ), array_keys( $this->cache ) );

		Seo_Catalog_Controller::bump_version();
		$version = Seo_Catalog_Controller::version();
		$this->assertNotSame( '0', $version );
		$controller->page( 1, 'https://shop.test/wp-json/storedash/v1/seo/catalog.txt' );
		$this->assertSame( array( 1, 1 ), $this->fetches, 'new version → refetch' );
		$this->assertArrayHasKey( Seo_Catalog_Controller::TRANSIENT_PREFIX . $version . '_p1', $this->cache );
	}

	public function test_serve_plain_text_only_for_text_responses() {
		$json = new WP_REST_Response( array( 'a' => 1 ), 200 );
		$this->assertFalse( Seo_Catalog_Controller::serve_plain_text( false, $json ) );
		$this->assertTrue( Seo_Catalog_Controller::serve_plain_text( true, $json ), 'already served stays served' );

		$text = new WP_REST_Response( "A | 1 ISK | in_stock | https://shop.test/a/\n", 200 );
		$text->header( 'Content-Type', 'text/plain; charset=UTF-8' );
		ob_start();
		$served = Seo_Catalog_Controller::serve_plain_text( false, $text );
		$echoed = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( "A | 1 ISK | in_stock | https://shop.test/a/\n", $echoed );
	}

	/* ---------------- review fixes 2026-10-19 ---------------- */

	public function test_404_without_the_right_token() {
		foreach ( array( null, '', 'wrong', strtoupper( self::TOKEN ), array( self::TOKEN ) ) as $token ) {
			$out = $this->controller()->get_catalog( $this->request( 1, $token ) );
			$this->assertInstanceOf( WP_Error::class, $out );
			$this->assertSame( 404, $out->get_error_data()['status'] );
		}
		$this->assertSame( array(), $this->fetches, 'nothing fetched without the token' );
	}

	public function test_page_past_the_end_is_404_and_never_cached() {
		$controller = $this->controller( 1200 ); // 3 pages.
		$out        = $controller->get_catalog( $this->request( 9000 ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 404, $out->get_error_data()['status'] );
		$this->assertArrayNotHasKey( Seo_Catalog_Controller::TRANSIENT_PREFIX . '0_p9000', $this->cache );
		$this->assertSame( 1200, $this->cache[ Seo_Catalog_Controller::TRANSIENT_PREFIX . '0_total' ] );

		$this->fetches = array();
		$this->assertSame( 404, $controller->get_catalog( $this->request( 4 ) )->get_error_data()['status'] );
		$this->assertSame( array(), $this->fetches, 'known total → no query for out-of-range pages' );
		$this->assertInstanceOf( WP_REST_Response::class, $controller->get_catalog( $this->request( 3 ) ) );

		$empty = $this->controller( 0 )->page( 1, 'x' );
		$this->assertSame( '', $empty['body'], 'page 1 always exists' );
	}

	public function test_token_is_generated_once_and_rotation_busts_pages() {
		unset( $GLOBALS['__seo_options'][ Seo_Catalog_Controller::TOKEN_OPTION ] );
		$token = Seo_Catalog_Controller::token();
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{32}$/', $token ) );
		$this->assertSame( $token, Seo_Catalog_Controller::token(), 'stored, not regenerated' );
		$this->assertSame( 'https://shop.test/wp-json/storedash/v1/seo/catalog.txt?token=' . $token, Seo_Catalog_Controller::base_url() );

		$version = Seo_Catalog_Controller::version();
		$rotated = Seo_Catalog_Controller::rotate_token();
		$this->assertNotSame( $token, $rotated );
		$this->assertSame( $rotated, Seo_Catalog_Controller::token() );
		$this->assertNotSame( $version, Seo_Catalog_Controller::version(), 'cached pages carry the old token in Link → new version' );
		$this->assertFalse( Seo_Catalog_Controller::token_matches( $token, $rotated ) );
		$this->assertTrue( Seo_Catalog_Controller::token_matches( $rotated, $rotated ) );
		$this->assertFalse( Seo_Catalog_Controller::token_matches( '', '' ), 'empty stored token never matches' );
	}

	public function test_last_page() {
		$this->assertSame( 1, Seo_Catalog_Controller::last_page( 0 ) );
		$this->assertSame( 1, Seo_Catalog_Controller::last_page( 500 ) );
		$this->assertSame( 2, Seo_Catalog_Controller::last_page( 501 ) );
		$this->assertSame( Seo_Catalog_Controller::MAX_PAGE, Seo_Catalog_Controller::last_page( PHP_INT_MAX ) );
	}
}
