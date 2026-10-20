<?php
/**
 * Robots_Txt: ai_bots blocks, sitemap line, llms.txt format + request match.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Robots_Txt;
use StoreDash\Seo\Settings;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Robots_Txt
 * @covers \StoreDash\Seo\Settings::normalize_ai_bots
 */
class Robots_TxtTest extends TestCase {

	public function test_normalize_ai_bots_keeps_known_slugs_with_booleans_only() {
		$out = Settings::normalize_ai_bots(
			array(
				'gptbot'     => false,
				'claudebot'  => true,
				'applebot'   => 'no',
				'googlebot'  => false,
				'bingbot'    => false,
				'unknownbot' => false,
				'ccbot'      => 0,
			)
		);
		$this->assertSame( array( 'gptbot' => false, 'claudebot' => true ), $out );
		$this->assertSame( $out, Settings::normalize( array( 'ai_bots' => array( 'gptbot' => false, 'claudebot' => true, 'googlebot' => false ) ) )['ai_bots'] );
		$this->assertSame( array_keys( Robots_Txt::ROBOTS_TOKENS ), Settings::AI_BOT_SLUGS, 'every blockable slug has a robots token' );
		$this->assertNotContains( 'googlebot', Settings::AI_BOT_SLUGS );
		$this->assertNotContains( 'bingbot', Settings::AI_BOT_SLUGS );
	}

	public function test_robots_blocks_per_disallowed_bot_with_vendor_tokens() {
		$out = Robots_Txt::robots_blocks(
			array(
				'gptbot'          => false,
				'claudebot'       => true,
				'applebot'        => false,
				'google-extended' => false,
				'perplexitybot'   => false,
			),
			''
		);
		$this->assertSame(
			"\nUser-agent: GPTBot\nDisallow: /\n"
			. "\nUser-agent: PerplexityBot\nDisallow: /\n"
			. "\nUser-agent: Google-Extended\nDisallow: /\n"
			. "\nUser-agent: Applebot-Extended\nDisallow: /\n",
			$out
		);
		$this->assertStringNotContainsString( 'ClaudeBot', $out, 'true = allowed = nothing emitted' );
		$this->assertStringNotContainsString( "User-agent: Applebot\n", $out, 'never block Apple search, only AI training' );
	}

	public function test_robots_blocks_empty_without_blocked_bots_and_sitemap_line_is_conditional() {
		$this->assertSame( '', Robots_Txt::robots_blocks( array(), '' ) );
		$this->assertSame( '', Robots_Txt::robots_blocks( array( 'gptbot' => true ), '' ) );
		$this->assertSame( "\nSitemap: https://shop.test/sitemap.xml\n", Robots_Txt::robots_blocks( array(), 'https://shop.test/sitemap.xml' ) );
		$this->assertSame(
			"\nUser-agent: CCBot\nDisallow: /\n\nSitemap: https://shop.test/sitemap.xml\n",
			Robots_Txt::robots_blocks( array( 'ccbot' => false ), 'https://shop.test/sitemap.xml' )
		);
	}

	public function test_filter_appends_to_wp_output_and_respects_blog_public() {
		$robots = new Robots_Txt(
			Settings::normalize( array( 'ai_bots' => array( 'bytespider' => false ) ) ),
			static function () {
				return 'https://shop.test/sitemap.xml';
			}
		);
		$wp = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$this->assertSame( $wp . "\nUser-agent: Bytespider\nDisallow: /\n\nSitemap: https://shop.test/sitemap.xml\n", $robots->filter_robots_txt( $wp, true ) );
		$this->assertSame( "User-agent: *\nDisallow: /\n", $robots->filter_robots_txt( "User-agent: *\nDisallow: /\n", false ), 'private site: untouched' );
	}

	public function test_b2_sitemap_url_follows_sitemap_generator_active_flag() {
		$this->assertTrue( class_exists( 'StoreDash\Seo\Engine\Sitemap_Generator' ) );
		\StoreDash\Seo\Engine\Sitemap_Generator::reset_active();
		$this->assertSame( '', Robots_Txt::b2_sitemap_url() );
		$robots = new Robots_Txt( Settings::normalize( array( 'ai_bots' => array( 'gptbot' => false ) ) ) );
		$this->assertStringNotContainsString( 'Sitemap:', $robots->filter_robots_txt( '', true ) );

		( new \StoreDash\Seo\Engine\Sitemap_Generator( Settings::normalize( array() ) ) )->register_hooks();
		$this->assertSame( home_url( '/sitemap.xml' ), Robots_Txt::b2_sitemap_url() );
		$this->assertStringContainsString( 'Sitemap: ' . home_url( '/sitemap.xml' ), $robots->filter_robots_txt( '', true ) );
		\StoreDash\Seo\Engine\Sitemap_Generator::reset_active();
	}

	/**
	 * @dataProvider llms_request_cases
	 */
	public function test_is_llms_request( string $uri, string $home_path, bool $expected ) {
		$this->assertSame( $expected, Robots_Txt::is_llms_request( $uri, $home_path ) );
	}

	public function llms_request_cases(): array {
		return array(
			array( '/llms.txt', '', true ),
			array( '/llms.txt', '/', true ),
			array( '/llms.txt?x=1', '', true ),
			array( '/LLMS.TXT', '', true ),
			array( '/shop/llms.txt', '/shop', true ),
			array( '/llms.txt', '/shop', false ),
			array( '/llms.txt.bak', '', false ),
			array( '/product/llms.txt', '', false ),
			array( '/', '', false ),
		);
	}

	public function test_llms_txt_follows_the_spec_shape() {
		$categories = array();
		for ( $i = 1; $i <= 55; $i++ ) {
			$categories[] = array(
				'name'  => 'Cat [' . $i . ']',
				'url'   => 'https://shop.test/product-category/cat-' . $i . '/',
				'count' => $i,
			);
		}
		$categories[] = array( 'name' => '', 'url' => 'https://shop.test/x/' );
		$out          = Robots_Txt::llms_txt(
			array(
				'name'        => 'My  Store',
				'description' => "<b>Best</b> socks\nin town",
				'home_url'    => 'https://shop.test/',
				'categories'  => $categories,
				'catalog_url' => 'https://shop.test/wp-json/storedash/v1/seo/catalog.txt',
			)
		);
		$lines = explode( "\n", $out );
		$this->assertSame( '# My Store', $lines[0] );
		$this->assertSame( '', $lines[1] );
		$this->assertSame( '> Best socks in town', $lines[2] );
		$this->assertStringContainsString( "\n## Categories\n\n- [Cat (1)](https://shop.test/product-category/cat-1/): 1 products\n", $out );
		$this->assertSame( 50, substr_count( $out, "\n- [Cat (" ), 'capped at 50' );
		$this->assertStringEndsWith( "## Catalog\n\n- [Product catalog (plain text, 500 per page, &page=N)](https://shop.test/wp-json/storedash/v1/seo/catalog.txt): one product per line — name | price currency | availability | URL\n", $out );
		$this->assertSame( 1, preg_match( '/^# .+\n\n> .+\n\n/', $out ), 'H1 then blockquote per llmstxt.org' );
	}

	public function test_llms_txt_minimal() {
		$this->assertSame( "# Store\n", Robots_Txt::llms_txt( array() ) );
		$out = Robots_Txt::llms_txt( array( 'name' => 'Shop', 'categories' => array(), 'catalog_url' => '' ) );
		$this->assertSame( "# Shop\n", $out );
		$this->assertStringNotContainsString( '>', $out );
	}

	public function test_llms_txt_policies_section_after_the_catalog() {
		$out = Robots_Txt::llms_txt(
			array(
				'name'        => 'Shop',
				'catalog_url' => 'https://shop.test/wp-json/storedash/v1/seo/catalog.txt?token=abc',
				'policies'    => array(
					array( 'name' => 'Terms and conditions', 'url' => 'https://shop.test/terms/' ),
					array( 'name' => 'Refunds [and] returns', 'url' => 'https://shop.test/returns/' ),
					array( 'name' => '', 'url' => 'https://shop.test/x/' ),
					array( 'name' => 'No URL' ),
				),
			)
		);
		$this->assertStringContainsString( "(https://shop.test/wp-json/storedash/v1/seo/catalog.txt?token=abc)", $out, 'catalog link carries the token' );
		$this->assertStringEndsWith( "\n\n## Policies\n\n- [Terms and conditions](https://shop.test/terms/)\n- [Refunds (and) returns](https://shop.test/returns/)\n", $out );
	}
}
