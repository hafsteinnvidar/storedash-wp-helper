<?php
/**
 * Head_Renderer: tag output per context, robots merge, escaping, pagination.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Head_Renderer;
use StoreDash\Seo\Engine\Meta_Reader;
use StoreDash\Seo\Settings;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Head_Renderer
 * @covers \StoreDash\Seo\Settings::normalize
 */
class Head_RendererTest extends TestCase {

	private function renderer( array $settings = array() ): Head_Renderer {
		return new Head_Renderer( new Meta_Reader( static function () {
			return array();
		} ), Settings::normalize( $settings ) );
	}

	private function ctx( array $overrides = array(), array $meta = array() ): array {
		return array_merge(
			array(
				'type'              => 'product',
				'template'          => 'product',
				'id'                => 10,
				'name'              => 'Blue Shirt',
				'url'               => 'https://shop.test/product/blue-shirt/',
				'paged'             => 1,
				'pretty_permalinks' => true,
				'site_name'         => 'My Store',
				'locale'            => 'en_US',
				'meta'              => Meta_Reader::normalize( $meta ),
				'vars'              => array(
					'product' => 'Blue Shirt',
					'store'   => 'My Store',
				),
				'description'       => '<p>A soft <strong>cotton</strong> shirt.</p>',
				'image'             => 'https://shop.test/img/shirt.jpg',
				'product'           => array(
					'price'        => '19.90',
					'currency'     => 'USD',
					'stock_status' => 'instock',
				),
			),
			$overrides
		);
	}

	private function html( array $ctx, array $settings = array() ): string {
		$r = $this->renderer( $settings );
		return Head_Renderer::render_tags( $r->build_tags( $ctx ) );
	}

	public function test_product_with_every_meta_set() {
		$meta = array(
			'_storedash_seo_title'               => array( 'Best Blue Shirt' ),
			'_storedash_seo_description'         => array( 'Meta "desc" & more' ),
			'_storedash_seo_canonical'           => array( 'https://shop.test/canon/' ),
			'_storedash_seo_og_title'            => array( 'OG T' ),
			'_storedash_seo_og_description'      => array( 'OG D' ),
			'_storedash_seo_twitter_title'       => array( 'TW T' ),
			'_storedash_seo_twitter_description' => array( 'TW D' ),
		);
		$ctx  = $this->ctx( array( 'image' => 'https://shop.test/og.jpg' ), $meta );
		$r    = $this->renderer();

		$this->assertSame( 'Best Blue Shirt', $r->document_title( $ctx ) );

		$expected = "<!-- Storedash SEO -->\n"
			. "<meta name=\"description\" content=\"Meta &quot;desc&quot; &amp; more\" />\n"
			. "<link rel=\"canonical\" href=\"https://shop.test/canon/\" />\n"
			. "<meta property=\"og:type\" content=\"product\" />\n"
			. "<meta property=\"og:title\" content=\"OG T\" />\n"
			. "<meta property=\"og:description\" content=\"OG D\" />\n"
			. "<meta property=\"og:url\" content=\"https://shop.test/canon/\" />\n"
			. "<meta property=\"og:image\" content=\"https://shop.test/og.jpg\" />\n"
			. "<meta property=\"og:site_name\" content=\"My Store\" />\n"
			. "<meta property=\"og:locale\" content=\"en_US\" />\n"
			. "<meta property=\"product:price:amount\" content=\"19.90\" />\n"
			. "<meta property=\"product:price:currency\" content=\"USD\" />\n"
			. "<meta property=\"product:availability\" content=\"instock\" />\n"
			. "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n"
			. "<meta name=\"twitter:title\" content=\"TW T\" />\n"
			. "<meta name=\"twitter:description\" content=\"TW D\" />\n"
			. "<meta name=\"twitter:image\" content=\"https://shop.test/og.jpg\" />\n"
			. "<!-- /Storedash SEO -->\n";
		$this->assertSame( $expected, Head_Renderer::render_tags( $r->build_tags( $ctx ) ) );
	}

	public function test_product_without_meta_uses_template_and_fallbacks() {
		$ctx  = $this->ctx(
			array(
				'image'   => '',
				'product' => array(
					'price'        => '5',
					'currency'     => 'ISK',
					'stock_status' => 'outofstock',
				),
			)
		);
		$r    = $this->renderer( array( 'default_og_image_url' => 'https://shop.test/default.png' ) );
		$html = Head_Renderer::render_tags( $r->build_tags( $ctx ) );

		$this->assertSame( 'Blue Shirt | My Store', $r->document_title( $ctx ) );
		$this->assertStringContainsString( '<meta name="description" content="A soft cotton shirt." />', $html );
		$this->assertStringContainsString( '<link rel="canonical" href="https://shop.test/product/blue-shirt/" />', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Blue Shirt | My Store" />', $html );
		$this->assertStringContainsString( '<meta property="og:image" content="https://shop.test/default.png" />', $html );
		$this->assertStringContainsString( '<meta property="product:availability" content="oos" />', $html );
		$this->assertStringContainsString( '<meta name="twitter:title" content="Blue Shirt | My Store" />', $html );
	}

	public function test_meta_title_with_variables_renders_like_a_template() {
		$r   = $this->renderer( array( 'title_separator' => '–' ) );
		$ctx = $this->ctx( array(), array( '_storedash_seo_title' => array( 'Buy {product} {sep} {store}' ) ) );
		$this->assertSame( 'Buy Blue Shirt – My Store', $r->document_title( $ctx ) );

		$html = Head_Renderer::render_tags( $r->build_tags( $ctx ) );
		$this->assertStringContainsString( '<meta property="og:title" content="Buy Blue Shirt – My Store" />', $html );

		// No supported variable: verbatim, braces kept.
		$ctx = $this->ctx( array(), array( '_storedash_seo_title' => array( 'Shirt {limited}' ) ) );
		$this->assertSame( 'Shirt {limited}', $r->document_title( $ctx ) );
	}

	public function test_custom_template_and_backorder_availability() {
		$ctx = $this->ctx(
			array(
				'vars'    => array(
					'product' => 'Shirt',
					'store'   => 'Shop',
					'brand'   => '',
					'price'   => '$5.00',
				),
				'product' => array(
					'price'        => '5',
					'currency'     => 'USD',
					'stock_status' => 'onbackorder',
				),
			)
		);
		$r   = $this->renderer(
			array(
				'title_template_product' => '{product} {sep} {brand} {sep} {price} {sep} {store}',
				'title_separator'        => '–',
			)
		);
		$this->assertSame( 'Shirt – $5.00 – Shop', $r->document_title( $ctx ) );
		$this->assertStringContainsString( 'content="pending"', Head_Renderer::render_tags( $r->build_tags( $ctx ) ) );
	}

	public function test_category_with_term_meta_and_pagination() {
		$ctx  = $this->ctx(
			array(
				'type'        => 'term',
				'template'    => 'category',
				'name'        => 'Shirts',
				'url'         => 'https://shop.test/product-category/shirts/',
				'paged'       => 3,
				'vars'        => array(
					'category' => 'Shirts',
					'store'    => 'My Store',
				),
				'description' => '',
				'image'       => '',
				'product'     => null,
			),
			array(
				'_storedash_seo_description' => array( 'All our shirts' ),
				'_storedash_seo_og_image'    => array( 'https://shop.test/cat.jpg' ),
			)
		);
		$ctx['image'] = $ctx['meta']['og_image'];
		$r            = $this->renderer();
		$html         = Head_Renderer::render_tags( $r->build_tags( $ctx ) );

		$this->assertSame( 'Shirts | My Store', $r->document_title( $ctx ) );
		$this->assertStringContainsString( '<meta name="description" content="All our shirts" />', $html );
		$this->assertStringContainsString( '<link rel="canonical" href="https://shop.test/product-category/shirts/page/3/" />', $html );
		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $html );
		$this->assertStringContainsString( '<meta property="og:image" content="https://shop.test/cat.jpg" />', $html );
		$this->assertStringNotContainsString( 'product:', $html );
	}

	public function test_page_uses_page_template_and_skips_empty_tags() {
		$ctx  = $this->ctx(
			array(
				'type'        => 'page',
				'template'    => 'page',
				'name'        => 'About us',
				'url'         => 'https://shop.test/about/',
				'vars'        => array(
					'page'  => 'About us',
					'store' => 'My Store',
				),
				'description' => '',
				'image'       => '',
				'product'     => null,
				'locale'      => '',
			)
		);
		$r    = $this->renderer();
		$html = Head_Renderer::render_tags( $r->build_tags( $ctx ) );

		$this->assertSame( 'About us | My Store', $r->document_title( $ctx ) );
		$this->assertStringNotContainsString( 'name="description"', $html );
		$this->assertStringNotContainsString( 'og:image', $html );
		$this->assertStringNotContainsString( 'twitter:image', $html );
		$this->assertStringNotContainsString( 'og:locale', $html );
		$this->assertStringNotContainsString( 'og:description', $html );
		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $html );
	}

	public function test_long_description_is_cut_at_a_word() {
		$text = str_repeat( 'word ', 60 );
		$out  = Head_Renderer::excerpt( $text, 160 );
		$this->assertLessThanOrEqual( 161, mb_strlen( $out ) );
		$this->assertStringEndsWith( 'word…', $out );
		$this->assertSame( 'Hi there', Head_Renderer::excerpt( '[gallery ids="1"] <p>Hi&nbsp;there</p>', 160 ) );
	}

	public function test_render_escapes_and_drops_unsafe_urls() {
		$tags = array(
			array(
				'tag'      => 'meta',
				'attrs'    => array(
					'property' => 'og:title',
					'content'  => '"><script>x</script>',
				),
				'url_attr' => null,
			),
			array(
				'tag'      => 'link',
				'attrs'    => array(
					'rel'  => 'canonical',
					'href' => 'javascript:alert(1)',
				),
				'url_attr' => 'href',
			),
		);
		$html = Head_Renderer::render_tags( $tags );
		$this->assertStringContainsString( 'content="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $html );
		$this->assertStringNotContainsString( 'canonical', $html );
		$this->assertSame( '', Head_Renderer::render_tags( array() ) );
	}

	public function test_robots_meta_noindex_only() {
		$r     = $this->renderer();
		$robot = Meta_Reader::robots( '{"index":false,"follow":false,"imageindex":false,"archive":false,"snippet":false}' );
		$this->assertSame(
			array( 'noindex' => true, 'nofollow' => true, 'noimageindex' => true, 'noarchive' => true, 'nosnippet' => true ),
			$r->robots_directives( $robot, array() )
		);
		$this->assertSame( array(), $r->robots_directives( Meta_Reader::robots( null ), array( 'paged' => 2 ) ), 'paginated not noindexed by default' );
	}

	public function test_robots_settings_rules() {
		$r = $this->renderer( array( 'noindex_paginated' => true, 'noindex_tags' => true, 'noindex_attributes' => true ) );
		$this->assertSame( array( 'noindex' => true, 'follow' => true ), $r->robots_directives( array(), array( 'paged' => 2 ) ) );
		$this->assertSame( array(), $r->robots_directives( array(), array( 'paged' => 1 ) ) );
		$this->assertSame( array( 'noindex' => true, 'follow' => true ), $r->robots_directives( array(), array( 'tag' => true ) ) );
		$this->assertSame( array( 'noindex' => true, 'follow' => true ), $r->robots_directives( array(), array( 'attribute' => true ) ) );
		$this->assertSame( array( 'noindex' => true, 'follow' => true ), $r->robots_directives( array(), array( 'search' => true ) ) );
		$this->assertSame( array( 'noindex' => true, 'follow' => true ), $r->robots_directives( array(), array( 'filtered' => true ) ) );

		$off = $this->renderer( array( 'noindex_search' => false, 'noindex_filtered' => false ) );
		$this->assertSame( array(), $off->robots_directives( array(), array( 'search' => true, 'filtered' => true, 'tag' => true ) ) );
	}

	public function test_robots_meta_and_settings_both() {
		$r     = $this->renderer( array( 'noindex_paginated' => true ) );
		$robot = Meta_Reader::robots( '{"index":false,"follow":false}' );
		$this->assertSame( array( 'noindex' => true, 'nofollow' => true ), $r->robots_directives( $robot, array( 'paged' => 4 ) ) );

		$robot = Meta_Reader::robots( '{"archive":false}' );
		$this->assertSame( array( 'noarchive' => true, 'noindex' => true, 'follow' => true ), $r->robots_directives( $robot, array( 'paged' => 4 ) ) );
	}

	public function test_merge_robots_only_adds_restrictions() {
		$wp = array( 'max-image-preview' => 'large' );
		$this->assertSame( $wp, Head_Renderer::merge_robots( $wp, array() ) );

		$merged = Head_Renderer::merge_robots(
			array(
				'index'             => true,
				'follow'            => true,
				'max-image-preview' => 'large',
			),
			array(
				'noindex'      => true,
				'nofollow'     => true,
				'noimageindex' => true,
			)
		);
		$this->assertSame( array( 'noindex' => true, 'nofollow' => true, 'noimageindex' => true ), $merged );

		// WP already noindexed (blog_public = 0 / WC cart): stays noindexed.
		$this->assertSame( array( 'noindex' => true, 'follow' => true ), Head_Renderer::merge_robots( array( 'noindex' => true, 'follow' => true ), array() ) );
	}

	public function test_paginate_url() {
		$this->assertSame( 'https://s.test/shop/', Head_Renderer::paginate_url( 'https://s.test/shop/', 1, true ) );
		$this->assertSame( 'https://s.test/shop/page/2/', Head_Renderer::paginate_url( 'https://s.test/shop/', 2, true ) );
		$this->assertSame( 'https://s.test/shop/page/2', Head_Renderer::paginate_url( 'https://s.test/shop', 2, true ) );
		$this->assertSame( 'https://s.test/?post_type=product&paged=3', Head_Renderer::paginate_url( 'https://s.test/?post_type=product', 3, false ) );
		$this->assertSame( 'https://s.test/x?paged=3', Head_Renderer::paginate_url( 'https://s.test/x', 3, false ) );
	}

	public function test_document_title_hook_escapes_html() {
		$r   = $this->renderer();
		$ctx = $this->ctx( array(), array( '_storedash_seo_title' => array( 'Tom & Jerry <b>x</b>' ) ) );

		// Inject the memoized context (resolve_context() needs the WP main query).
		$inject = static function ( $renderer, $value ) {
			( function ( $value ) {
				$this->context = $value;
			} )->call( $renderer, $value );
		};
		$inject( $r, $ctx );

		// Pure builder stays raw …
		$this->assertSame( 'Tom & Jerry <b>x</b>', $r->document_title( $ctx ) );
		// … the pre_get_document_title callback escapes (core's esc_html is skipped on short-circuit).
		$out = $r->filter_document_title( '' );
		$this->assertSame( 'Tom &amp; Jerry &lt;b&gt;x&lt;/b&gt;', $out );
		$this->assertStringNotContainsString( '<', $out );
		$this->assertStringNotContainsString( '& ', $out );

		// Unsupported page: WP's title passes through untouched.
		$inject( $r, null );
		$this->assertSame( 'WP <title>', $r->filter_document_title( 'WP <title>' ) );
	}

	public function test_absolute_url_accepts_non_ascii_paths() {
		$iri = 'https://verslun.is/vörur/þráðlaus-ðæ/';
		$this->assertTrue( Settings::is_absolute_url( $iri ) );
		$this->assertSame( $iri, Settings::http_url( $iri ), 'original string kept' );
		$this->assertTrue( Settings::is_absolute_url( 'https://shop.test/a?q=ö#ð' ) );
		$this->assertTrue( Settings::is_absolute_url( 'http://shop.test' ) );

		foreach ( array( 'javascript:alert(1)', '/relative', 'ftp://x', 'https://', 'https:///path', '', 'shop.test/x', 'https://shop.test/a b' ) as $bad ) {
			$this->assertFalse( Settings::is_absolute_url( $bad ), $bad );
			$this->assertSame( '', Settings::http_url( $bad ), $bad );
		}

		// Shared by the settings, the reader and the writer.
		$this->assertSame( $iri, Settings::normalize( array( 'default_og_image_url' => $iri ) )['default_og_image_url'] );
		$this->assertSame( $iri, Meta_Reader::normalize( array( '_storedash_seo_canonical' => array( $iri ) ) )['canonical'] );
		$this->assertSame( array( '_storedash_seo_canonical' => $iri ), \StoreDash\Seo\Engine\Meta_Writer::prepare( array( 'canonical' => $iri ) )['set'] );
	}

	public function test_settings_normalize_defaults_and_types() {
		$s = Settings::normalize(
			array(
				'title_separator'      => '<b>-</b>',
				'default_og_image_url' => 'ftp://nope',
				'noindex_tags'         => 'true',
				'schema_org'           => 'bad',
				'unknown'              => 1,
			)
		);
		$this->assertSame( '-', $s['title_separator'] );
		$this->assertSame( '', $s['default_og_image_url'] );
		$this->assertTrue( $s['noindex_tags'] );
		$this->assertSame( array(), $s['schema_org'] );
		$this->assertArrayNotHasKey( 'unknown', $s );
		$this->assertSame( '{product} {sep} {store}', $s['title_template_product'] );
		$this->assertTrue( $s['noindex_search'] );
	}
}
