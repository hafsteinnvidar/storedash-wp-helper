<?php
/**
 * Meta_Reader: §4.9 keys, robots parsing, one fetch per object.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Meta_Reader;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Meta_Reader
 */
class Meta_ReaderTest extends TestCase {

	/**
	 * Raw get_post_meta( $id ) shape: key => list of raw strings.
	 */
	private function full_raw(): array {
		return array(
			'_storedash_seo_title'               => array( ' Custom Title ' ),
			'_storedash_seo_description'         => array( 'Custom description' ),
			'_storedash_seo_focus_keyword'       => array( 'shirt' ),
			'_storedash_seo_canonical'           => array( 'https://shop.test/canonical/' ),
			'_storedash_seo_robots'              => array( '{"index":false,"follow":true,"imageindex":true,"archive":false,"snippet":true}' ),
			'_storedash_seo_breadcrumb_title'    => array( 'Crumb' ),
			'_storedash_seo_og_title'            => array( 'OG Title' ),
			'_storedash_seo_og_description'      => array( 'OG Desc' ),
			'_storedash_seo_og_image'            => array( 'https://shop.test/og.jpg' ),
			'_storedash_seo_twitter_title'       => array( 'TW Title' ),
			'_storedash_seo_twitter_description' => array( 'TW Desc' ),
			'_thumbnail_id'                      => array( '42' ),
			'_price'                             => array( '10' ),
		);
	}

	public function test_normalize_reads_every_key() {
		$meta = Meta_Reader::normalize( $this->full_raw() );
		$this->assertSame( 'Custom Title', $meta['title'] );
		$this->assertSame( 'Custom description', $meta['description'] );
		$this->assertSame( 'shirt', $meta['focus_keyword'] );
		$this->assertSame( 'https://shop.test/canonical/', $meta['canonical'] );
		$this->assertSame( 'Crumb', $meta['breadcrumb_title'] );
		$this->assertSame( 'OG Title', $meta['og_title'] );
		$this->assertSame( 'OG Desc', $meta['og_description'] );
		$this->assertSame( 'https://shop.test/og.jpg', $meta['og_image'] );
		$this->assertSame( 'TW Title', $meta['twitter_title'] );
		$this->assertSame( 'TW Desc', $meta['twitter_description'] );
		$this->assertSame( 42, $meta['thumbnail_id'] );
		$this->assertSame(
			array( 'index' => false, 'follow' => true, 'imageindex' => true, 'archive' => false, 'snippet' => true ),
			$meta['robots']
		);
	}

	public function test_empty_meta_defaults() {
		$meta = Meta_Reader::normalize( array() );
		$this->assertSame( '', $meta['title'] );
		$this->assertSame( '', $meta['canonical'] );
		$this->assertSame( 0, $meta['thumbnail_id'] );
		$this->assertSame( array_fill_keys( Meta_Reader::ROBOTS, true ), $meta['robots'] );
	}

	public function test_invalid_urls_are_dropped_and_attachment_ids_kept() {
		$meta = Meta_Reader::normalize(
			array(
				'_storedash_seo_canonical' => array( 'javascript:alert(1)' ),
				'_storedash_seo_og_image'  => array( '123' ),
			)
		);
		$this->assertSame( '', $meta['canonical'] );
		$this->assertSame( '123', $meta['og_image'] );

		$meta = Meta_Reader::normalize( array( '_storedash_seo_og_image' => array( 'not a url' ) ) );
		$this->assertSame( '', $meta['og_image'] );
	}

	public function test_robots_accepts_serialized_array_and_partial_values() {
		$serialized = serialize( array( 'index' => false, 'follow' => '0' ) ); // phpcs:ignore
		$this->assertSame(
			array( 'index' => false, 'follow' => false, 'imageindex' => true, 'archive' => true, 'snippet' => true ),
			Meta_Reader::robots( $serialized )
		);
		$this->assertSame(
			array( 'index' => true, 'follow' => true, 'imageindex' => false, 'archive' => true, 'snippet' => true ),
			Meta_Reader::robots( array( 'imageindex' => 'false' ) )
		);
		$this->assertSame( array_fill_keys( Meta_Reader::ROBOTS, true ), Meta_Reader::robots( 'garbage' ) );
	}

	public function test_term_thumbnail_id_is_read() {
		$this->assertSame( 7, Meta_Reader::normalize( array( 'thumbnail_id' => array( '7' ) ) )['thumbnail_id'] );
	}

	public function test_one_fetch_per_object_per_request() {
		$post_calls = 0;
		$term_calls = 0;
		$raw        = $this->full_raw();
		$reader     = new Meta_Reader(
			static function ( $id ) use ( $raw, &$post_calls ) {
				++$post_calls;
				return 10 === $id ? $raw : array();
			},
			static function ( $id ) use ( &$term_calls ) {
				++$term_calls;
				return array( '_storedash_seo_title' => array( 'Term ' . $id ) );
			}
		);

		$this->assertSame( 'Custom Title', $reader->for_post( 10 )['title'] );
		$reader->for_post( 10 );
		$reader->for_post( 10 );
		$this->assertSame( 1, $post_calls );

		$this->assertSame( 'Term 10', $reader->for_term( 10 )['title'] );
		$reader->for_term( 10 );
		$this->assertSame( 1, $term_calls );

		$this->assertSame( '', $reader->for_post( 0 )['title'] );
		$this->assertSame( 1, $post_calls, 'id 0 never queries' );
	}
}
