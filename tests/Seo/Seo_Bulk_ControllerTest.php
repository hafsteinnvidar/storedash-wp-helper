<?php
/**
 * Seo_Bulk_Controller: batch limits, per-item validation, partial success.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Controllers\Seo_Bulk_Controller;

require_once __DIR__ . '/seo-stubs.php';

/**
 * Controller with fake post types / terms.
 */
class Seo_Test_Bulk_Controller extends Seo_Bulk_Controller {
	/** @var array<int,string> */
	public $post_types = array(
		10 => 'product',
		11 => 'product_variation',
		12 => 'page',
	);
	/** @var array<string,int[]> */
	public $terms = array( 'product_cat' => array( 5 ) );

	protected function post_type( int $id ): ?string {
		return $this->post_types[ $id ] ?? null;
	}

	protected function term_exists_in( int $id, string $taxonomy ): bool {
		return in_array( $id, $this->terms[ $taxonomy ] ?? array(), true );
	}
}

/**
 * @covers \StoreDash\Seo\Controllers\Seo_Bulk_Controller
 */
class Seo_Bulk_ControllerTest extends TestCase {

	/** @var array */
	private $log = array();

	private function controller(): Seo_Test_Bulk_Controller {
		$this->log = array();
		return new Seo_Test_Bulk_Controller( seo_recording_writer( $this->log ) );
	}

	public function test_batch_limits() {
		$c = $this->controller();
		$this->assertSame( 'storedash_seo_empty_items', $c->bulk_meta( new Seo_Test_Request( array( 'items' => array() ) ) )->get_error_code() );
		$this->assertSame( 'storedash_seo_empty_items', $c->bulk_meta( new Seo_Test_Request( array() ) )->get_error_code() );

		$items = array_fill( 0, Seo_Bulk_Controller::MAX_ITEMS + 1, array( 'object' => 'product', 'id' => 10, 'fields' => array( 'seo_title' => 'x' ) ) );
		$out   = $c->bulk_meta( new Seo_Test_Request( array( 'items' => $items ) ) );
		$this->assertSame( 'storedash_seo_too_many_items', $out->get_error_code() );
		$this->assertSame( array(), $this->log );
	}

	public function test_partial_success() {
		$c   = $this->controller();
		$out = $c->bulk_meta(
			new Seo_Test_Request(
				array(
					'items' => array(
						array( 'object' => 'product', 'id' => 10, 'fields' => array( 'seo_title' => 'P', 'seo_description' => '' ) ),
						array( 'object' => 'term', 'id' => 5, 'taxonomy' => 'product_cat', 'fields' => array( 'robots' => array( 'index' => false ) ) ),
						array( 'object' => 'product', 'id' => 11, 'fields' => array( 'seo_title' => 'V' ) ),
						array( 'object' => 'page', 'id' => 10, 'fields' => array( 'seo_title' => 'X' ) ),
						array( 'object' => 'post', 'id' => 99, 'fields' => array( 'seo_title' => 'X' ) ),
						array( 'object' => 'term', 'id' => 6, 'taxonomy' => 'product_cat', 'fields' => array( 'seo_title' => 'X' ) ),
						array( 'object' => 'term', 'id' => 5, 'taxonomy' => 'nav_menu', 'fields' => array( 'seo_title' => 'X' ) ),
						array( 'object' => 'product', 'id' => 10, 'fields' => array( 'canonical' => 'relative' ) ),
						array( 'object' => 'order', 'id' => 10, 'fields' => array( 'seo_title' => 'X' ) ),
						array( 'object' => 'page', 'id' => 0, 'fields' => array( 'seo_title' => 'X' ) ),
						'garbage',
					),
				)
			)
		);

		$this->assertSame( 2, $out['ok_count'] );
		$this->assertSame( 9, $out['error_count'] );

		$r = $out['results'];
		$this->assertSame(
			array( 'id' => 10, 'object' => 'product', 'ok' => true, 'written' => array( '_storedash_seo_title' ), 'deleted' => array( '_storedash_seo_description' ) ),
			$r[0]
		);
		$this->assertTrue( $r[1]['ok'] );
		$this->assertSame( 'product_cat', $r[1]['taxonomy'] );
		$this->assertSame( array( '_storedash_seo_robots' ), $r[1]['written'] );

		$this->assertSame( array( 422, 'object is a product_variation, not a product' ), array( $r[2]['status'], $r[2]['error'] ) );
		$this->assertSame( 422, $r[3]['status'] );
		$this->assertSame( 404, $r[4]['status'] );
		$this->assertSame( 404, $r[5]['status'] );
		$this->assertSame( array( 422, 'unsupported taxonomy' ), array( $r[6]['status'], $r[6]['error'] ) );
		$this->assertSame( 422, $r[7]['status'] );
		$this->assertStringContainsString( 'canonical', $r[7]['error'] );
		$this->assertSame( 400, $r[8]['status'] );
		$this->assertSame( 400, $r[9]['status'] );
		$this->assertSame( 400, $r[10]['status'] );

		$this->assertSame(
			array(
				array( 'update', 'post', 10, '_storedash_seo_title', 'P' ),
				array( 'delete', 'post', 10, '_storedash_seo_description' ),
				array( 'clean', 'post', 10 ),
				array( 'update', 'term', 5, '_storedash_seo_robots', '{"index":false,"follow":true,"imageindex":true,"archive":true,"snippet":true}' ),
				array( 'clean', 'term', 5 ),
			),
			$this->log
		);
	}

	public function test_id_must_be_a_positive_integer() {
		foreach ( array( 10, '10' ) as $good ) {
			$this->assertSame( 10, Seo_Bulk_Controller::positive_int( $good ), var_export( $good, true ) );
		}
		foreach ( array( '1e3', 1e3, '1.5', 1.5, 0, -3, '0', '-3', ' 7', '7 ', '0x1A', true, null, array( 10 ), '' ) as $bad ) {
			$this->assertSame( 0, Seo_Bulk_Controller::positive_int( $bad ), var_export( $bad, true ) );
		}

		$c   = $this->controller();
		$out = $c->process(
			array(
				array( 'object' => 'product', 'id' => '1e3', 'fields' => array( 'seo_title' => 'X' ) ),
				array( 'object' => 'product', 'id' => 1.5, 'fields' => array( 'seo_title' => 'X' ) ),
				array( 'object' => 'product', 'id' => '10', 'fields' => array( 'seo_title' => 'X' ) ),
			)
		);
		$this->assertSame( array( 400, 'id must be a positive integer' ), array( $out['results'][0]['status'], $out['results'][0]['error'] ) );
		$this->assertSame( 400, $out['results'][1]['status'] );
		$this->assertTrue( $out['results'][2]['ok'] );
		$this->assertSame( 10, $out['results'][2]['id'] );
	}

	public function test_supported_taxonomies() {
		$this->assertTrue( Seo_Bulk_Controller::is_supported_taxonomy( 'product_cat' ) );
		$this->assertTrue( Seo_Bulk_Controller::is_supported_taxonomy( 'pa_color' ) );
		$this->assertTrue( Seo_Bulk_Controller::is_supported_taxonomy( 'pwb-brand' ) );
		$this->assertFalse( Seo_Bulk_Controller::is_supported_taxonomy( 'nav_menu' ) );
		$this->assertFalse( Seo_Bulk_Controller::is_supported_taxonomy( '' ) );
	}
}
