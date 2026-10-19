<?php
/**
 * Seo_Redirects_Controller: PUT validation + replace semantics + hash, GET
 * shape, hits drain.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Controllers\Seo_Redirects_Controller;
use StoreDash\Seo\Engine\Redirect_Manager;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Controllers\Seo_Redirects_Controller
 */
class Seo_Redirects_ControllerTest extends TestCase {

	/** @var Seo_Test_Redirects */
	private $manager;

	protected function setUp(): void {
		$GLOBALS['__seo_options']         = array();
		$GLOBALS['__seo_option_autoload'] = array();
		$GLOBALS['__seo_transients']      = array();
		$this->manager                    = new Seo_Test_Redirects();
	}

	protected function tearDown(): void {
		$GLOBALS['__seo_options']         = array();
		$GLOBALS['__seo_option_autoload'] = array();
		$GLOBALS['__seo_transients']      = array();
	}

	private function controller(): Seo_Redirects_Controller {
		return new Seo_Redirects_Controller( $this->manager );
	}

	private function put( array $body ) {
		return $this->controller()->put_redirects( new Seo_Test_Request( $body ) );
	}

	private function rows(): array {
		return array(
			array( 'source_path' => '/old/', 'target_url' => 'https://shop.test/new/', 'status_code' => 301, 'id' => 1 ),
			array( 'source_path' => 'gone', 'target_url' => '', 'status_code' => 410 ),
			array( 'source_path' => '/rel', 'target_url' => '/target', 'status_code' => 302 ),
		);
	}

	public function test_put_stores_normalised_rows_and_returns_get_shape() {
		$out = $this->put( array( 'redirects' => $this->rows(), 'hash' => 'abc' ) );

		$this->assertSame(
			array(
				'redirects' => array(
					array( 'source_path' => '/old', 'target_url' => 'https://shop.test/new/', 'status_code' => 301, 'auto' => false, 'id' => 1 ),
					array( 'source_path' => '/gone', 'target_url' => '', 'status_code' => 410, 'auto' => false ),
					array( 'source_path' => '/rel', 'target_url' => '/target', 'status_code' => 302, 'auto' => false ),
				),
				'count'     => 3,
				'hash'      => 'abc',
			),
			$out
		);
		$this->assertSame( 'abc', $GLOBALS['__seo_options'][ Redirect_Manager::HASH_OPTION ] );
		$this->assertSame( '1', $GLOBALS['__seo_options'][ Redirect_Manager::ACTIVE_OPTION ] );
		$this->assertFalse( $GLOBALS['__seo_option_autoload'][ Redirect_Manager::OPTION ] );

		$this->assertSame( $out, $this->controller()->get_redirects( new Seo_Test_Request( array() ) ) );
	}

	public function test_get_when_empty() {
		$this->assertSame(
			array( 'redirects' => array(), 'count' => 0, 'hash' => null ),
			$this->controller()->get_redirects( new Seo_Test_Request( array() ) )
		);
	}

	public function test_put_rejects_missing_hash_or_bad_redirects() {
		$e = $this->put( array( 'redirects' => $this->rows() ) );
		$this->assertInstanceOf( WP_Error::class, $e );
		$this->assertSame( 'storedash_seo_invalid_hash', $e->get_error_code() );

		$e = $this->put( array( 'redirects' => $this->rows(), 'hash' => '   ' ) );
		$this->assertSame( 'storedash_seo_invalid_hash', $e->get_error_code() );

		$e = $this->put( array( 'redirects' => 'nope', 'hash' => 'h' ) );
		$this->assertSame( 'storedash_seo_invalid_redirects', $e->get_error_code() );

		$e = $this->put( array( 'hash' => 'h' ) );
		$this->assertSame( 'storedash_seo_invalid_redirects', $e->get_error_code() );

		$this->assertArrayNotHasKey( Redirect_Manager::OPTION, $GLOBALS['__seo_options'] );
	}

	public function test_put_above_cap_is_400() {
		$rows = array();
		for ( $i = 0; $i <= Redirect_Manager::MAX_ROWS; $i++ ) {
			$rows[] = array( 'source_path' => '/r' . $i, 'target_url' => '/t', 'status_code' => 301 );
		}
		$e = $this->put( array( 'redirects' => $rows, 'hash' => 'h' ) );
		$this->assertInstanceOf( WP_Error::class, $e );
		$this->assertSame( 'storedash_seo_redirects_cap', $e->get_error_code() );
		$this->assertSame( 400, $e->get_error_data()['status'] );
		$this->assertArrayNotHasKey( Redirect_Manager::OPTION, $GLOBALS['__seo_options'] );
	}

	public function test_put_with_invalid_rows_lists_them_and_stores_nothing() {
		$this->put( array( 'redirects' => $this->rows(), 'hash' => 'first' ) );

		$e = $this->put(
			array(
				'redirects' => array(
					array( 'source_path' => '/a', 'target_url' => '/b', 'status_code' => 301 ),
					array( 'source_path' => '/loop', 'target_url' => '/loop/', 'status_code' => 301 ),
					array( 'source_path' => '', 'target_url' => '/b', 'status_code' => 301 ),
					array( 'source_path' => '/a/', 'target_url' => '/c', 'status_code' => 307 ),
				),
				'hash'      => 'second',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $e );
		$this->assertSame( 'storedash_seo_invalid_redirects', $e->get_error_code() );
		$data = $e->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( array( 1, 2, 3 ), array_column( $data['errors'], 'index' ) );
		$this->assertStringContainsString( 'loop', $data['errors'][0]['reason'] );
		$this->assertSame( 'source_path is required', $data['errors'][1]['reason'] );
		$this->assertSame( 'duplicate source_path /a', $data['errors'][2]['reason'] );

		$get = $this->controller()->get_redirects( new Seo_Test_Request( array() ) );
		$this->assertSame( 3, $get['count'], 'previous table intact' );
		$this->assertSame( 'first', $get['hash'] );
	}

	public function test_put_replaces_merchant_rows_but_auto_rows_survive_unless_named() {
		$this->manager->add_auto_redirect( 'https://shop.test/product/a/', 'https://shop.test/product/b/', 1, 'slug' );
		$this->manager->add_auto_redirect( 'https://shop.test/product/c/', 'https://shop.test/product-category/x/', 2, 'trash' );
		$this->put( array( 'redirects' => $this->rows(), 'hash' => 'h1' ) );

		$out = $this->put(
			array(
				'redirects' => array(
					array( 'source_path' => '/product/c', 'target_url' => '/mine', 'status_code' => 301, 'id' => 9 ),
				),
				'hash'      => 'h2',
			)
		);

		$this->assertSame( 2, $out['count'] );
		$this->assertSame(
			array(
				array( 'source_path' => '/product/c', 'target_url' => '/mine', 'status_code' => 301, 'auto' => false, 'id' => 9 ),
				array( 'source_path' => '/product/a', 'target_url' => 'https://shop.test/product/b/', 'status_code' => 301, 'auto' => true ),
			),
			$out['redirects']
		);
		$this->assertSame( 'h2', $out['hash'] );

		$out = $this->put( array( 'redirects' => array(), 'hash' => 'h3' ) );
		$this->assertSame( 1, $out['count'] );
		$this->assertTrue( $out['redirects'][0]['auto'] );
		$this->assertSame( '1', $GLOBALS['__seo_options'][ Redirect_Manager::ACTIVE_OPTION ] );
	}

	public function test_put_merge_above_cap_is_400_and_stores_nothing() {
		$this->manager->add_auto_redirect( 'https://shop.test/product/a/', 'https://shop.test/product/b/', 1, 'slug' );
		$rows = array();
		for ( $i = 0; $i < Redirect_Manager::MAX_ROWS; $i++ ) {
			$rows[] = array( 'source_path' => '/r' . $i, 'target_url' => '/t', 'status_code' => 301 );
		}
		$e = $this->put( array( 'redirects' => $rows, 'hash' => 'h' ) );
		$this->assertInstanceOf( WP_Error::class, $e );
		$this->assertSame( 'storedash_seo_redirects_cap', $e->get_error_code() );
		$this->assertSame( 1, $this->controller()->get_redirects( new Seo_Test_Request( array() ) )['count'] );
	}

	public function test_hits_returns_object_and_clears() {
		$empty = $this->controller()->get_hits( new Seo_Test_Request( array() ) );
		$this->assertInstanceOf( stdClass::class, $empty['hits'] );
		$this->assertNull( $empty['since'] );
		$this->assertSame( '{"hits":{},"since":null}', json_encode( $empty ) );

		$this->manager->count_hit( '/old' );
		$this->manager->count_hit( '/old' );
		$this->manager->count_hit( '/gone' );

		$out = $this->controller()->get_hits( new Seo_Test_Request( array() ) );
		$this->assertSame( array( '/old' => 2, '/gone' => 1 ), $out['hits'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $out['since'] );

		$again = $this->controller()->get_hits( new Seo_Test_Request( array() ) );
		$this->assertInstanceOf( stdClass::class, $again['hits'] );
	}
}
