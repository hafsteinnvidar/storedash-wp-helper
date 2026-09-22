<?php
/**
 * Unit tests for StoreDash\Products\Video_Embeds.
 *
 * The WooCommerce hooks and wp_kses() pass are WordPress glue verified on a
 * live store; this test pins the pure logic: which iframe srcs are trusted and
 * that untrusted iframes are removed.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Products\Video_Embeds;

require_once __DIR__ . '/../../inc/Products/Video_Embeds.php';

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url ) {
		return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

/**
 * @covers \StoreDash\Products\Video_Embeds
 */
class Video_EmbedsTest extends TestCase {

	public function test_allows_youtube_and_vimeo_embed_urls(): void {
		$this->assertTrue( Video_Embeds::is_allowed_video_src( 'https://www.youtube-nocookie.com/embed/xJpJ7nLGKk0?rel=1' ) );
		$this->assertTrue( Video_Embeds::is_allowed_video_src( 'https://www.youtube.com/embed/xJpJ7nLGKk0' ) );
		$this->assertTrue( Video_Embeds::is_allowed_video_src( 'https://youtube.com/embed/xJpJ7nLGKk0' ) );
		$this->assertTrue( Video_Embeds::is_allowed_video_src( 'https://player.vimeo.com/video/76979871' ) );
	}

	public function test_rejects_other_hosts_schemes_and_paths(): void {
		$this->assertFalse( Video_Embeds::is_allowed_video_src( 'http://www.youtube.com/embed/xJpJ7nLGKk0' ) );
		$this->assertFalse( Video_Embeds::is_allowed_video_src( '//www.youtube.com/embed/xJpJ7nLGKk0' ) );
		$this->assertFalse( Video_Embeds::is_allowed_video_src( 'https://evil.com/embed/x' ) );
		$this->assertFalse( Video_Embeds::is_allowed_video_src( 'https://www.youtube.com.evil.com/embed/x' ) );
		$this->assertFalse( Video_Embeds::is_allowed_video_src( 'https://www.youtube.com/watch?v=xJpJ7nLGKk0' ) );
		$this->assertFalse( Video_Embeds::is_allowed_video_src( 'javascript:alert(1)' ) );
		$this->assertFalse( Video_Embeds::is_allowed_video_src( '' ) );
	}

	public function test_keeps_trusted_iframe_untouched(): void {
		$html = '<p>d</p><div data-youtube-video=""><iframe width="640" height="360" allowfullscreen="true" src="https://www.youtube-nocookie.com/embed/xJpJ7nLGKk0?rel=1&amp;start=5"></iframe></div>';

		$this->assertSame( $html, Video_Embeds::keep_allowed_iframes( $html ) );
	}

	public function test_removes_untrusted_iframes_and_keeps_the_rest(): void {
		$html = '<p>a</p><iframe src="https://evil.com/embed/x"></iframe><p>b</p><iframe width="1"></iframe><iframe src="https://evil.com/x">';

		$this->assertSame( '<p>a</p><p>b</p>', Video_Embeds::keep_allowed_iframes( $html ) );
	}

	public function test_mixed_iframes_only_drop_the_untrusted_one(): void {
		$good = '<iframe src="https://player.vimeo.com/video/1"></iframe>';
		$html = $good . '<IFRAME SRC=\'https://evil.com/embed/x\'></IFRAME>';

		$this->assertSame( $good, Video_Embeds::keep_allowed_iframes( $html ) );
	}
}
