<?php
/**
 * Unit tests for StoreDash_Image_Thumbnails: the field is strictly additive,
 * and only appears when WordPress reports a real, smaller, generated file.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

require_once __DIR__ . '/../../inc/api/image-thumbnails.php';

/**
 * @covers StoreDash_Image_Thumbnails
 */
class Image_ThumbnailsTest extends TestCase {

	const FULL  = 'https://shop.test/wp-content/uploads/a.jpg';
	const SMALL = 'https://shop.test/wp-content/uploads/a-324x324.jpg';

	protected function setUp(): void {
		StoreDash_Image_Thumbnails::reset_memo();
	}

	private function resolver( $result ): callable {
		return function () use ( $result ) {
			return $result;
		};
	}

	public function test_adds_thumbnail_and_keeps_every_other_field(): void {
		$image = array( 'id' => 7, 'src' => self::FULL, 'alt' => 'A', 'name' => 'a.jpg' );
		$out   = StoreDash_Image_Thumbnails::with_thumbnail( $image, $this->resolver( array( self::SMALL, 324, 324, true ) ) );
		$this->assertSame( $image + array( 'thumbnail_src' => self::SMALL ), $out );
	}

	public function test_object_images_are_supported(): void {
		$image = (object) array( 'id' => 7, 'src' => self::FULL );
		$out   = StoreDash_Image_Thumbnails::with_thumbnail( $image, $this->resolver( array( self::SMALL, 324, 324, true ) ) );
		$this->assertSame( self::SMALL, $out->thumbnail_src );
		$this->assertSame( self::FULL, $out->src );
	}

	public function test_unchanged_when_wp_falls_back_to_full_size(): void {
		$image = array( 'id' => 7, 'src' => self::FULL );
		// is_intermediate = false → the "thumbnail" IS the original file.
		$out = StoreDash_Image_Thumbnails::with_thumbnail( $image, $this->resolver( array( self::FULL, 2000, 2000, false ) ) );
		$this->assertSame( $image, $out );
	}

	public function test_unchanged_when_resolver_finds_nothing(): void {
		$image = array( 'id' => 7, 'src' => self::FULL );
		$this->assertSame( $image, StoreDash_Image_Thumbnails::with_thumbnail( $image, $this->resolver( false ) ) );
	}

	public function test_unchanged_for_placeholder_or_external_images(): void {
		$never = function () {
			throw new RuntimeException( 'resolver must not be called' );
		};
		$placeholder = array( 'id' => 0, 'src' => self::FULL );
		$no_src      = array( 'id' => 7 );
		$this->assertSame( $placeholder, StoreDash_Image_Thumbnails::with_thumbnail( $placeholder, $never ) );
		$this->assertSame( $no_src, StoreDash_Image_Thumbnails::with_thumbnail( $no_src, $never ) );
	}

	public function test_non_image_values_pass_through(): void {
		$r = $this->resolver( array( self::SMALL, 324, 324, true ) );
		$this->assertNull( StoreDash_Image_Thumbnails::with_thumbnail( null, $r ) );
		$this->assertSame( '', StoreDash_Image_Thumbnails::with_thumbnail( '', $r ) );
		$this->assertSame( array(), StoreDash_Image_Thumbnails::with_thumbnail( array(), $r ) );
	}

	public function test_same_attachment_is_resolved_once(): void {
		$calls    = 0;
		$resolver = function () use ( &$calls ) {
			++$calls;
			return array( self::SMALL, 324, 324, true );
		};
		$image = array( 'id' => 7, 'src' => self::FULL );
		StoreDash_Image_Thumbnails::with_thumbnail( $image, $resolver );
		StoreDash_Image_Thumbnails::with_thumbnail( $image, $resolver );
		$this->assertSame( 1, $calls );
	}
}
