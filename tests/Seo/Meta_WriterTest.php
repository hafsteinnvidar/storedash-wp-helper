<?php
/**
 * Meta_Writer: §4.9 field validation, delete-on-empty, robots JSON.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Meta_Reader;
use StoreDash\Seo\Engine\Meta_Writer;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Meta_Writer
 */
class Meta_WriterTest extends TestCase {

	public function test_field_map_matches_reader_keys() {
		$this->assertEqualsCanonicalizing( array_values( Meta_Reader::KEYS ), array_values( Meta_Writer::FIELDS ) );
	}

	public function test_prepare_sets_and_deletes() {
		$p = Meta_Writer::prepare(
			array(
				'seo_title'       => '  New <b>title</b> ',
				'seo_description' => '',
				'focus_keyword'   => null,
				'canonical'       => 'https://shop.test/p/',
				'og_image'        => 55,
			)
		);
		$this->assertNull( $p['error'] );
		$this->assertSame(
			array(
				'_storedash_seo_title'     => 'New title',
				'_storedash_seo_canonical' => 'https://shop.test/p/',
				'_storedash_seo_og_image'  => '55',
			),
			$p['set']
		);
		$this->assertSame( array( '_storedash_seo_description', '_storedash_seo_focus_keyword' ), $p['delete'] );
	}

	public function test_robots_json_and_all_true_deletes() {
		$p = Meta_Writer::prepare( array( 'robots' => array( 'index' => false ) ) );
		$this->assertSame( '{"index":false,"follow":true,"imageindex":true,"archive":true,"snippet":true}', $p['set']['_storedash_seo_robots'] );
		$this->assertSame(
			array( 'index' => false, 'follow' => true, 'imageindex' => true, 'archive' => true, 'snippet' => true ),
			Meta_Reader::robots( $p['set']['_storedash_seo_robots'] )
		);

		$p = Meta_Writer::prepare( array( 'robots' => array_fill_keys( Meta_Reader::ROBOTS, true ) ) );
		$this->assertSame( array(), $p['set'] );
		$this->assertSame( array( '_storedash_seo_robots' ), $p['delete'] );
	}

	/**
	 * @dataProvider invalid_fields
	 */
	public function test_invalid_payloads_reject_whole_item( $fields, string $needle ) {
		$p = Meta_Writer::prepare( $fields );
		$this->assertNotNull( $p['error'] );
		$this->assertStringContainsString( $needle, $p['error'] );
	}

	public function invalid_fields(): array {
		return array(
			'not object'        => array( 'x', 'non-empty object' ),
			'empty'             => array( array(), 'non-empty object' ),
			'unknown field'     => array( array( 'seo_title' => 'a', 'title' => 'b' ), 'unknown fields: title' ),
			'relative canonical' => array( array( 'canonical' => '/p/' ), 'canonical' ),
			'js og image'       => array( array( 'og_image' => 'javascript:alert(1)' ), 'og_image' ),
			'zero attachment'   => array( array( 'og_image' => 0 ), 'og_image' ),
			'non-string title'  => array( array( 'seo_title' => 12 ), 'seo_title' ),
			'robots not object' => array( array( 'robots' => 'noindex' ), 'robots' ),
			'robots non-bool'   => array( array( 'robots' => array( 'index' => 'no' ) ), 'index must be a boolean' ),
			'robots unknown'    => array( array( 'robots' => array( 'noodp' => true ) ), 'unknown directives' ),
			'too long'          => array( array( 'seo_title' => str_repeat( 'a', Meta_Writer::MAX_LENGTH + 1 ) ), 'longer than' ),
		);
	}

	public function test_og_image_numeric_string_is_attachment_id() {
		$p = Meta_Writer::prepare( array( 'og_image' => '123' ) );
		$this->assertSame( '123', $p['set']['_storedash_seo_og_image'] );
	}

	public function test_apply_writes_deletes_and_cleans_once() {
		$log    = array();
		$writer = seo_recording_writer( $log );
		$result = $writer->apply(
			'term',
			9,
			array(
				'set'    => array( '_storedash_seo_title' => 'T' ),
				'delete' => array( '_storedash_seo_description' ),
			)
		);
		$this->assertSame( array( '_storedash_seo_title' ), $result['written'] );
		$this->assertSame( array( '_storedash_seo_description' ), $result['deleted'] );
		$this->assertSame(
			array(
				array( 'update', 'term', 9, '_storedash_seo_title', 'T' ),
				array( 'delete', 'term', 9, '_storedash_seo_description' ),
				array( 'clean', 'term', 9 ),
			),
			$log
		);
	}

	public function test_apply_nothing_does_not_clean() {
		$log = array();
		seo_recording_writer( $log )->apply( 'post', 1, array( 'set' => array(), 'delete' => array() ) );
		$this->assertSame( array(), $log );
	}
}
