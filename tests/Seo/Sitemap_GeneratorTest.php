<?php
/**
 * Sitemap_Generator: types, index, page building + exclusions, image
 * entries, XML validity (xmllint when available), transient cache + bust,
 * query budget, request routing, rewrite flush transition.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Sitemap_Generator;
use StoreDash\Seo\Settings;

require_once __DIR__ . '/seo-stubs.php';

/**
 * Generator over in-memory fixtures. Every overridden data method counts as
 * one "query"; `post_records()` is the one priming pass.
 */
class Seo_Test_Sitemap extends Sitemap_Generator {
	/** @var array<string,int[]> post type => IDs (published, ID order) */
	public $posts = array();
	/** @var array<int,array> ID => record */
	public $records = array();
	/** @var array<string,array[]> taxonomy => records */
	public $terms = array();
	/** @var string[] */
	public $taxonomies = array( 'product_cat', 'product_tag', 'product_brand' );
	/** @var int */
	public $queries = 0;
	/** @var string[] */
	public $log = array();
	/** @var string|null */
	public $sent = null;

	protected function home( string $path = '' ): string {
		return 'https://shop.test' . $path;
	}

	protected function taxonomy_exists( string $taxonomy ): bool {
		return in_array( $taxonomy, $this->taxonomies, true );
	}

	protected function count_posts( string $post_type ): int {
		++$this->queries;
		$this->log[] = 'count:' . $post_type;
		return count( $this->posts[ $post_type ] ?? array() );
	}

	protected function fetch_post_ids( string $post_type, int $offset, int $limit ): array {
		++$this->queries;
		$this->log[] = 'fetch:' . $post_type . ':' . $offset . ':' . $limit;
		return array_slice( $this->posts[ $post_type ] ?? array(), $offset, $limit );
	}

	protected function post_records( array $ids ): array {
		++$this->queries;
		$this->log[] = 'prime:' . count( $ids );
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->records[ $id ] ) ) {
				$out[] = $this->records[ $id ];
			}
		}
		return $out;
	}

	protected function count_terms( string $taxonomy ): int {
		++$this->queries;
		$this->log[] = 'count_terms:' . $taxonomy;
		return count( $this->terms[ $taxonomy ] ?? array() );
	}

	protected function term_records( string $taxonomy, int $offset, int $limit ): array {
		++$this->queries;
		$this->log[] = 'terms:' . $taxonomy . ':' . $offset . ':' . $limit;
		return array_slice( $this->terms[ $taxonomy ] ?? array(), $offset, $limit );
	}

	protected function output( string $xml ): void {
		$this->sent = $xml;
	}
}

/**
 * @covers \StoreDash\Seo\Engine\Sitemap_Generator
 */
class Sitemap_GeneratorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__seo_options']    = array();
		$GLOBALS['__seo_transients'] = array();
		$GLOBALS['__seo_query_vars'] = array();
		$GLOBALS['__seo_status']     = array();
		Settings::reset_cache();
		Sitemap_Generator::reset_active();
	}

	protected function tearDown(): void {
		$GLOBALS['__seo_options']    = array();
		$GLOBALS['__seo_transients'] = array();
		$GLOBALS['__seo_query_vars'] = array();
		$GLOBALS['__seo_status']     = array();
		Settings::reset_cache();
		Sitemap_Generator::reset_active();
	}

	private function generator( array $settings = array() ): Seo_Test_Sitemap {
		return new Seo_Test_Sitemap( Settings::normalize( $settings ) );
	}

	private function product( int $id, array $extra = array() ): array {
		return array_merge(
			array(
				'loc'       => 'https://shop.test/product/item-' . $id . '/',
				'lastmod'   => '2026-10-19 12:00:00',
				'password'  => false,
				'hidden'    => false,
				'index'     => true,
				'canonical' => '',
				'images'    => array( 'https://shop.test/wp-content/uploads/' . $id . '.jpg' ),
			),
			$extra
		);
	}

	/** Small catalog: 3 products, 2 categories, 1 tag, 1 page, 1 post. */
	private function small(): Seo_Test_Sitemap {
		$g        = $this->generator();
		$g->posts = array(
			'product' => array( 1, 2, 3 ),
			'page'    => array( 10 ),
			'post'    => array( 20 ),
		);
		foreach ( array( 1, 2, 3 ) as $id ) {
			$g->records[ $id ] = $this->product( $id );
		}
		$g->records[10] = array(
			'loc'     => 'https://shop.test/about/',
			'lastmod' => '2026-01-02 03:04:05',
			'images'  => array( 'https://shop.test/x.jpg' ),
		);
		$g->records[20] = array(
			'loc'     => 'https://shop.test/news/hello/',
			'lastmod' => '2026-02-03 04:05:06',
		);
		$g->terms   = array(
			'product_cat' => array(
				array( 'loc' => 'https://shop.test/product-category/shoes/' ),
				array( 'loc' => 'https://shop.test/product-category/hats/' ),
			),
			'product_tag' => array(
				array( 'loc' => 'https://shop.test/product-tag/sale/' ),
			),
		);
		return $g;
	}

	/* ---------------- types + index ---------------- */

	public function test_types_default_and_gated() {
		$this->assertSame(
			array( 'products', 'product_cat', 'product_tag', 'product_brand', 'pages', 'posts' ),
			$this->generator()->types()
		);

		$this->assertNotContains( 'product_tag', $this->generator( array( 'noindex_tags' => true ) )->types() );

		$g             = $this->generator();
		$g->taxonomies = array( 'product_cat', 'product_tag' );
		$this->assertNotContains( 'product_brand', $g->types() );
	}

	public function test_index_lists_one_entry_per_page_and_skips_empty_types() {
		$g                   = $this->small();
		$g->posts['product'] = range( 1, 2500 );
		$entries             = $g->index_entries();
		$locs                = array_column( $entries, 'loc' );

		$this->assertSame(
			array(
				'https://shop.test/sitemap-products-1.xml',
				'https://shop.test/sitemap-products-2.xml',
				'https://shop.test/sitemap-products-3.xml',
				'https://shop.test/sitemap-product_cat-1.xml',
				'https://shop.test/sitemap-product_tag-1.xml',
				'https://shop.test/sitemap-pages-1.xml',
				'https://shop.test/sitemap-posts-1.xml',
			),
			$locs
		);
		// product_brand exists but has no terms → not listed.
		$this->assertNotContains( 'https://shop.test/sitemap-product_brand-1.xml', $locs );
		$this->assertSame( 6, $g->queries, 'one count query per served type' );
		$this->assertSame( 'https://shop.test/sitemap.xml', $g->index_url() );
	}

	public function test_render_index_is_valid_xml() {
		$g   = $this->small();
		$xml = Sitemap_Generator::render_index( $g->index_entries() );
		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc );
		$this->assertSame( array( '' => Sitemap_Generator::NS_SITEMAP ), $doc->getNamespaces() );
		$this->assertCount( 5, $doc->sitemap );
		$this->assertStringNotContainsString( 'lastmod', $xml );
		$this->xmllint( $xml, 'siteindex' );
	}

	/* ---------------- page entries + exclusions ---------------- */

	public function test_page_entries_apply_exclusions() {
		$g              = $this->small();
		$g->posts['product'] = range( 1, 8 );
		$g->records[4]  = $this->product( 4, array( 'password' => true ) );
		$g->records[5]  = $this->product( 5, array( 'hidden' => true ) );
		$g->records[6]  = $this->product( 6, array( 'index' => false ) );
		$g->records[7]  = $this->product( 7, array( 'canonical' => 'https://shop.test/product/item-1/' ) );
		$g->records[8]  = $this->product( 8, array( 'canonical' => 'https://shop.test/product/item-8' ) ); // self, no slash

		$entries = $g->page_entries( 'products', 1 );
		$this->assertSame(
			array(
				'https://shop.test/product/item-1/',
				'https://shop.test/product/item-2/',
				'https://shop.test/product/item-3/',
				'https://shop.test/product/item-8/',
			),
			array_column( $entries, 'loc' )
		);
		$this->assertSame( '2026-10-19T12:00:00Z', $entries[0]['lastmod'] );
		$this->assertSame( array( 'https://shop.test/wp-content/uploads/1.jpg' ), $entries[0]['images'] );
	}

	public function test_images_capped_at_ten_deduped_and_only_for_products() {
		$images = array();
		for ( $i = 1; $i <= 14; $i++ ) {
			$images[] = 'https://shop.test/img-' . $i . '.jpg';
		}
		$images[] = 'https://shop.test/img-1.jpg';
		$records  = array( $this->product( 1, array( 'images' => array_merge( array( 'https://shop.test/img-1.jpg', ' ' ), $images ) ) ) );

		$out = Sitemap_Generator::filter_records( $records, true );
		$this->assertCount( 10, $out[0]['images'] );
		$this->assertSame( 'https://shop.test/img-1.jpg', $out[0]['images'][0] );
		$this->assertSame( 'https://shop.test/img-10.jpg', $out[0]['images'][9] );

		$pages = Sitemap_Generator::filter_records( $records, false );
		$this->assertArrayNotHasKey( 'images', $pages[0] );
	}

	public function test_page_entries_out_of_range_or_unknown_type_is_null() {
		$g = $this->small();
		$this->assertNull( $g->page_entries( 'products', 0 ) );
		$this->assertNull( $g->page_entries( 'products', 2 ) );
		$this->assertNull( $g->page_entries( 'users', 1 ) );
		$this->assertNull( $g->page_entries( 'product_brand', 1 ), 'brand taxonomy exists but has no terms' );
		$this->assertNull( $this->generator( array( 'noindex_tags' => true ) )->page_entries( 'product_tag', 1 ) );
	}

	public function test_term_page_has_no_lastmod_or_images_and_respects_meta() {
		$g                      = $this->small();
		$g->terms['product_cat'][] = array(
			'loc'   => 'https://shop.test/product-category/secret/',
			'index' => false,
		);
		$g->terms['product_cat'][] = array(
			'loc'       => 'https://shop.test/product-category/dupe/',
			'canonical' => 'https://shop.test/product-category/shoes/',
		);
		$entries                = $g->page_entries( 'product_cat', 1 );
		$this->assertSame(
			array( 'https://shop.test/product-category/shoes/', 'https://shop.test/product-category/hats/' ),
			array_column( $entries, 'loc' )
		);
		$this->assertSame( array( 'loc' ), array_keys( $entries[0] ) );
		$this->assertSame( array( 'count_terms:product_cat', 'terms:product_cat:0:1000' ), $g->log );
	}

	public function test_w3c_datetime() {
		$this->assertSame( '2026-10-19T12:34:56Z', Sitemap_Generator::w3c_datetime( '2026-10-19 12:34:56' ) );
		$this->assertSame( '', Sitemap_Generator::w3c_datetime( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', Sitemap_Generator::w3c_datetime( '' ) );
		$this->assertSame( '', Sitemap_Generator::w3c_datetime( 'not a date' ) );
	}

	/* ---------------- XML ---------------- */

	public function test_render_urlset_declares_image_namespace_only_when_used_and_escapes() {
		$with = Sitemap_Generator::render_urlset(
			array(
				array(
					'loc'     => 'https://shop.test/product/a?x=1&y=2',
					'lastmod' => '2026-10-19T12:00:00Z',
					'images'  => array( 'https://shop.test/i.jpg?a=1&b=<2>' ),
				),
			)
		);
		$this->assertStringContainsString( 'xmlns:image="' . Sitemap_Generator::NS_IMAGE . '"', $with );
		$this->assertStringContainsString( '<loc>https://shop.test/product/a?x=1&amp;y=2</loc>', $with );
		$this->assertStringContainsString( '<image:loc>https://shop.test/i.jpg?a=1&amp;b=&lt;2&gt;</image:loc>', $with );
		$this->assertStringNotContainsString( 'priority', $with );
		$this->assertStringNotContainsString( 'changefreq', $with );

		$without = Sitemap_Generator::render_urlset( array( array( 'loc' => 'https://shop.test/about/' ) ) );
		$this->assertStringNotContainsString( 'xmlns:image', $without );

		$doc = simplexml_load_string( $with );
		$this->assertNotFalse( $doc );
		$this->assertSame(
			array(
				''      => Sitemap_Generator::NS_SITEMAP,
				'image' => Sitemap_Generator::NS_IMAGE,
			),
			$doc->getNamespaces( true )
		);
	}

	public function test_xml_strips_control_chars() {
		$this->assertSame( 'a&amp;b', Sitemap_Generator::xml( "a\x01&\x1Fb" ) );
	}

	public function test_real_products_page_validates_against_schemas() {
		$g       = $this->small();
		$xml     = $g->page_xml( 'products', 1 );
		$doc     = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc );
		$this->assertCount( 3, $doc->url );
		$this->xmllint( $xml, 'sitemap-with-images' );
	}

	/* ---------------- performance budget ---------------- */

	public function test_thousand_url_page_uses_three_queries() {
		$g                   = $this->generator();
		$g->posts['product'] = range( 1, 1000 );
		for ( $i = 1; $i <= 1000; $i++ ) {
			$g->records[ $i ] = $this->product( $i );
		}

		$xml = $g->page_xml( 'products', 1 );

		$this->assertSame( 3, $g->queries, 'count + id fetch + one priming pass, nothing per item' );
		$this->assertSame( array( 'count:product', 'fetch:product:0:1000', 'prime:1000' ), $g->log );
		$this->assertSame( 1000, substr_count( $xml, '<url>' ) );
		$this->assertSame( 1000, substr_count( $xml, '<image:image>' ) );
	}

	public function test_second_page_offsets_by_per_page() {
		$g                   = $this->generator();
		$g->posts['product'] = range( 1, 1001 );
		$g->records[1001]    = $this->product( 1001 );
		$entries             = $g->page_entries( 'products', 2 );
		$this->assertCount( 1, $entries );
		$this->assertContains( 'fetch:product:1000:1000', $g->log );
	}

	/* ---------------- cache ---------------- */

	public function test_page_xml_is_cached_tracked_and_busted() {
		$g   = $this->small();
		$xml = $g->page_xml( 'products', 1 );
		$this->assertSame( 3, $g->queries );
		$this->assertSame( $xml, $GLOBALS['__seo_transients']['storedash_sitemap_products_1'] );
		$this->assertSame( array( 'storedash_sitemap_products_1' ), $GLOBALS['__seo_options'][ Sitemap_Generator::KEYS_OPTION ] );
		$this->assertFalse( $GLOBALS['__seo_option_autoload'][ Sitemap_Generator::KEYS_OPTION ], 'key list is not autoloaded' );

		$this->assertSame( $xml, $g->page_xml( 'products', 1 ) );
		$this->assertSame( 3, $g->queries, 'cache hit: no new queries, no range check' );

		$g->index_xml();
		$this->assertSame(
			array( 'storedash_sitemap_products_1', 'storedash_sitemap_index' ),
			$GLOBALS['__seo_options'][ Sitemap_Generator::KEYS_OPTION ]
		);

		$g->bust_cache();
		$this->assertSame( array(), $GLOBALS['__seo_transients'] );
		$this->assertArrayNotHasKey( Sitemap_Generator::KEYS_OPTION, $GLOBALS['__seo_options'] );

		$g->page_xml( 'products', 1 );
		$this->assertSame( 12, $g->queries, 'rebuilt after bust (3) on top of the index build (6)' );
	}

	public function test_bust_hooks_filter_by_type_and_taxonomy() {
		$g = $this->small();
		$g->page_xml( 'products', 1 );

		$g->on_save_post( 99, (object) array( 'post_type' => 'revision' ) );
		$this->assertArrayHasKey( 'storedash_sitemap_products_1', $GLOBALS['__seo_transients'] );
		$g->on_term_change( 5, 5, 'category' );
		$this->assertArrayHasKey( 'storedash_sitemap_products_1', $GLOBALS['__seo_transients'] );

		$g->on_save_post( 1, (object) array( 'post_type' => 'product' ) );
		$this->assertArrayNotHasKey( 'storedash_sitemap_products_1', $GLOBALS['__seo_transients'] );

		$g->page_xml( 'products', 1 );
		$g->on_term_change( 5, 5, 'product_cat' );
		$this->assertArrayNotHasKey( 'storedash_sitemap_products_1', $GLOBALS['__seo_transients'] );
	}

	public function test_settings_reset_fires_bust_action() {
		$GLOBALS['__seo_actions'] = array();
		Settings::reset_cache();
		$this->assertContains( Settings::RESET_ACTION, $GLOBALS['__seo_actions'] );
	}

	/* ---------------- request routing ---------------- */

	public function test_maybe_render_serves_index_and_pages_and_404s() {
		$g = $this->small();

		$g->maybe_render();
		$this->assertNull( $g->sent, 'no query var → not ours' );

		$GLOBALS['__seo_query_vars'] = array( Sitemap_Generator::QUERY_VAR => 'index' );
		$g->maybe_render();
		$this->assertStringContainsString( '<sitemapindex', $g->sent );

		$g->sent                     = null;
		$GLOBALS['__seo_query_vars'] = array(
			Sitemap_Generator::QUERY_VAR => 'product_cat',
			Sitemap_Generator::PAGE_VAR  => '1',
		);
		$g->maybe_render();
		$this->assertStringContainsString( 'product-category/shoes', $g->sent );

		$g->sent                     = null;
		$GLOBALS['__seo_query_vars'] = array(
			Sitemap_Generator::QUERY_VAR => 'products',
			Sitemap_Generator::PAGE_VAR  => '7',
		);
		$g->maybe_render();
		$this->assertNull( $g->sent );
		$this->assertSame( array( 404 ), $GLOBALS['__seo_status'] );

		$GLOBALS['__seo_status']     = array();
		$GLOBALS['__seo_query_vars'] = array( Sitemap_Generator::QUERY_VAR => '../etc' );
		$g->maybe_render();
		$this->assertNull( $g->sent );
		$this->assertSame( array( 404 ), $GLOBALS['__seo_status'] );
	}

	public function test_query_vars_added() {
		$vars = $this->generator()->add_query_vars( array( 'p' ) );
		$this->assertSame( array( 'p', Sitemap_Generator::QUERY_VAR, Sitemap_Generator::PAGE_VAR ), $vars );
	}

	/* ---------------- rewrite flush transition ---------------- */

	public function test_sync_rewrite_rules_only_on_transition() {
		// Plain permalinks: nothing.
		$this->assertFalse( Sitemap_Generator::sync_rewrite_rules( true ) );

		$GLOBALS['__seo_options']['permalink_structure'] = '/%postname%/';
		$GLOBALS['__seo_options']['rewrite_rules']       = array( '^shop/?$' => 'index.php?post_type=product' );
		$this->assertTrue( Sitemap_Generator::sync_rewrite_rules( true ), 'on, rule missing → flush' );
		$this->assertFalse( Sitemap_Generator::sync_rewrite_rules( false ), 'off, rule missing → nothing' );

		$GLOBALS['__seo_options']['rewrite_rules'][ Sitemap_Generator::INDEX_RULE ] = 'index.php?storedash_sitemap=index';
		$this->assertFalse( Sitemap_Generator::sync_rewrite_rules( true ), 'on, rule present → nothing' );
		$this->assertTrue( Sitemap_Generator::sync_rewrite_rules( false ), 'off, rule present → flush' );

		$GLOBALS['__seo_options']['rewrite_rules'] = '';
		$this->assertFalse( Sitemap_Generator::sync_rewrite_rules( true ), 'not generated yet → nothing' );
	}

	public function test_register_hooks_marks_active() {
		$this->assertFalse( Sitemap_Generator::is_active() );
		$this->generator()->register_hooks();
		$this->assertTrue( Sitemap_Generator::is_active() );
	}

	/* ---------------- helpers ---------------- */

	/**
	 * Validate with xmllint against the sitemaps.org / Google image XSDs when
	 * the binary and the schema files are available; otherwise only
	 * well-formedness (asserted by the caller) is checked.
	 */
	private function xmllint( string $xml, string $kind ): void {
		$xmllint = trim( (string) shell_exec( 'which xmllint 2>/dev/null' ) );
		$xsd_dir = __DIR__ . '/fixtures/xsd';
		if ( '' === $xmllint || ! is_dir( $xsd_dir ) ) {
			$this->addToAssertionCount( 1 );
			return;
		}
		$schema = $xsd_dir . '/' . ( 'siteindex' === $kind ? 'siteindex.xsd' : 'sitemap-with-images.xsd' );
		$this->assertFileExists( $schema );

		$tmp = tempnam( sys_get_temp_dir(), 'sdmap' );
		file_put_contents( $tmp, $xml );
		$out  = array();
		$code = 1;
		exec( escapeshellarg( $xmllint ) . ' --noout --schema ' . escapeshellarg( $schema ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1', $out, $code );
		unlink( $tmp );
		$this->assertSame( 0, $code, 'xmllint: ' . implode( "\n", $out ) );
	}
}
