<?php
/**
 * Redirect_Manager: path normalisation, row validation (incl. loops), match
 * order, protected paths, request handling (301/410/HEAD/POST), hit counter
 * + drain, table replace semantics, cap, auto redirects (slug change, trash
 * → category, untrash removal, chain re-pointing).
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Redirect_Manager;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Redirect_Manager
 */
class Redirect_ManagerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__seo_options']         = array();
		$GLOBALS['__seo_option_autoload'] = array();
		$GLOBALS['__seo_transients']      = array();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_GET[ Redirect_Manager::BYPASS_ARG ] );
	}

	protected function tearDown(): void {
		unset( $_GET[ Redirect_Manager::BYPASS_ARG ] );
		$GLOBALS['__seo_options']         = array();
		$GLOBALS['__seo_option_autoload'] = array();
		$GLOBALS['__seo_transients']      = array();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
	}

	private function manager(): Seo_Test_Redirects {
		return new Seo_Test_Redirects();
	}

	private function seed( Redirect_Manager $m, array $rows, string $hash = 'h1' ): void {
		$this->assertTrue( $m->replace_merchant_rows( $rows, $hash ) );
	}

	private function request( Seo_Test_Redirects $m, string $uri, string $method = 'GET' ): void {
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = $method;
		$m->handle_request();
	}

	/* ---------------- normalisation ---------------- */

	public function test_normalize_path() {
		$cases = array(
			'old-page/'                              => '/old-page',
			'/old-page'                              => '/old-page',
			'/old-page/'                             => '/old-page',
			'/'                                      => '/',
			''                                       => '',
			'   '                                    => '',
			'https://Shop.test/Old/?a=1&b=2#frag'    => '/Old?a=1&b=2',
			'//cdn.example.com/x/'                   => '/x',
			'/a//b/'                                 => '/a/b',
			'//a//b/'                                => '/b',
			'/v%C3%B6rur/'                           => '/vörur',
			'/vörur/'                                => '/vörur',
			'?x=1'                                   => '/?x=1',
			'/a/?'                                   => '/a',
			'https://shop.test'                      => '/',
		);
		foreach ( $cases as $in => $out ) {
			$this->assertSame( $out, Redirect_Manager::normalize_path( (string) $in ), 'normalize ' . var_export( $in, true ) );
		}
	}

	public function test_absolute_target_resolves_relative() {
		$this->assertSame( 'https://shop.test/new/', Redirect_Manager::absolute_target( '/new/', 'https://shop.test' ) );
		$this->assertSame( 'https://shop.test/new', Redirect_Manager::absolute_target( 'new', 'https://shop.test/' ) );
		$this->assertSame( 'https://other.test/x', Redirect_Manager::absolute_target( 'https://other.test/x', 'https://shop.test' ) );
		$this->assertSame( '', Redirect_Manager::absolute_target( '  ', 'https://shop.test' ) );
	}

	/* ---------------- validation ---------------- */

	public function test_validate_rows_accepts_absolute_relative_and_410() {
		$valid = Redirect_Manager::validate_rows(
			array(
				array( 'source_path' => '/old/', 'target_url' => 'https://shop.test/new/', 'status_code' => 301, 'id' => 7 ),
				array( 'source_path' => 'older', 'target_url' => '/newer', 'status_code' => '302' ),
				array( 'source_path' => '/gone', 'target_url' => '', 'status_code' => 410 ),
				array( 'source_path' => '/gone-with-target', 'target_url' => '/ignored', 'status_code' => 410, 'id' => '12' ),
				array( 'source_path' => '/ext', 'target_url' => 'https://other.test/old/', 'status_code' => 308 ),
			),
			'https://shop.test'
		);
		$this->assertSame( array(), $valid['errors'] );
		$this->assertSame( array( '/old', '/older', '/gone', '/gone-with-target', '/ext' ), array_keys( $valid['rows'] ) );
		$this->assertSame( array( 'target_url' => 'https://shop.test/new/', 'status_code' => 301, 'id' => 7 ), $valid['rows']['/old'] );
		$this->assertSame( array( 'target_url' => '/newer', 'status_code' => 302 ), $valid['rows']['/older'] );
		$this->assertSame( array( 'target_url' => '', 'status_code' => 410 ), $valid['rows']['/gone'] );
		$this->assertSame( array( 'target_url' => '', 'status_code' => 410, 'id' => 12 ), $valid['rows']['/gone-with-target'] );
	}

	public function test_validate_rows_lists_every_bad_row_with_index_and_reason() {
		$valid = Redirect_Manager::validate_rows(
			array(
				'not-a-row',
				array( 'target_url' => '/x', 'status_code' => 301 ),
				array( 'source_path' => '/a', 'target_url' => '/x', 'status_code' => 200 ),
				array( 'source_path' => '/b', 'target_url' => 'ftp://x/y', 'status_code' => 301 ),
				array( 'source_path' => '/c', 'target_url' => '//evil.test/', 'status_code' => 301 ),
				array( 'source_path' => '/d', 'target_url' => 'javascript:alert(1)', 'status_code' => 301 ),
				array( 'source_path' => '/e', 'target_url' => '', 'status_code' => 301 ),
				array( 'source_path' => '/f', 'target_url' => '/f/', 'status_code' => 301 ),
				array( 'source_path' => '/g', 'target_url' => 'https://SHOP.test/g', 'status_code' => 302 ),
				array( 'source_path' => '/h', 'target_url' => '/h?x=1', 'status_code' => 301 ),
				array( 'source_path' => '/i', 'target_url' => '/ok', 'status_code' => 301, 'id' => 0 ),
				array( 'source_path' => '/j', 'target_url' => '/ok', 'status_code' => 301 ),
				array( 'source_path' => 'j/', 'target_url' => '/ok2', 'status_code' => 301 ),
				array( 'source_path' => str_repeat( 'a', 2001 ), 'target_url' => '/ok', 'status_code' => 301 ),
				array( 'source_path' => '/k', 'target_url' => 'https://x.test/' . str_repeat( 'a', 2000 ), 'status_code' => 301 ),
				array( 'source_path' => '#only-fragment', 'target_url' => '/ok', 'status_code' => 301 ),
			),
			'https://shop.test'
		);

		$this->assertSame( array( '/j' ), array_keys( $valid['rows'] ) );
		$by_index = array_column( $valid['errors'], 'reason', 'index' );
		$this->assertSame( 'row must be an object', $by_index[0] );
		$this->assertSame( 'source_path is required', $by_index[1] );
		$this->assertStringContainsString( 'status_code must be one of 301, 302, 307, 308, 410', $by_index[2] );
		$this->assertStringContainsString( 'absolute http(s) URL or a site-relative path', $by_index[3] );
		$this->assertStringContainsString( 'absolute http(s) URL or a site-relative path', $by_index[4] );
		$this->assertStringContainsString( 'absolute http(s) URL or a site-relative path', $by_index[5] );
		$this->assertSame( 'target_url is required', $by_index[6] );
		$this->assertStringContainsString( 'loop', $by_index[7] );
		$this->assertStringContainsString( 'loop', $by_index[8] );
		$this->assertStringContainsString( 'loop', $by_index[9] );
		$this->assertSame( 'id must be a positive integer', $by_index[10] );
		$this->assertArrayNotHasKey( 11, $by_index );
		$this->assertSame( 'duplicate source_path /j', $by_index[12] );
		$this->assertStringContainsString( 'source_path must be at most 2000', $by_index[13] );
		$this->assertStringContainsString( 'target_url must be at most 2000', $by_index[14] );
		$this->assertSame( 'source_path is not a path', $by_index[15] );
		$this->assertCount( 15, $valid['errors'] );
	}

	public function test_loop_detection_ignores_other_hosts() {
		$this->assertFalse( Redirect_Manager::is_loop( '/g', 'https://other.test/g', 'https://shop.test', 'shop.test' ) );
		$this->assertTrue( Redirect_Manager::is_loop( '/g', 'https://shop.test/g/', 'https://shop.test', 'shop.test' ) );
		$this->assertTrue( Redirect_Manager::is_loop( '/g', 'g', 'https://shop.test', 'shop.test' ) );
		$this->assertFalse( Redirect_Manager::is_loop( '/g?x=1', '/g', 'https://shop.test', 'shop.test' ), 'query source → bare target is not a loop' );
	}

	/* ---------------- matching ---------------- */

	public function test_match_order_exact_then_query_stripped_with_trailing_slash_normalised() {
		$rows = array(
			'/old?x=1' => array( 'target_url' => '/A', 'status_code' => 301 ),
			'/old'     => array( 'target_url' => '/B', 'status_code' => 301 ),
		);
		$this->assertSame( '/A', Redirect_Manager::match( '/old/?x=1', $rows )['target_url'] );
		$this->assertSame( '/A', Redirect_Manager::match( '/old?x=1', $rows )['target_url'] );
		$this->assertSame( '/B', Redirect_Manager::match( '/old?y=2', $rows )['target_url'] );
		$this->assertSame( '/B', Redirect_Manager::match( '/old/', $rows )['target_url'] );
		$this->assertSame( '/old', Redirect_Manager::match( '/old/', $rows )['source_path'] );
		$this->assertNull( Redirect_Manager::match( '/other', $rows ) );
		$this->assertNull( Redirect_Manager::match( '/old', array() ) );
	}

	public function test_candidates_strip_subdirectory_home_path() {
		$this->assertSame(
			array( '/shop/old?x=1', '/shop/old', '/old?x=1', '/old' ),
			Redirect_Manager::candidates( '/shop/old/?x=1', '/shop/' )
		);
		$this->assertSame( array( '/old' ), Redirect_Manager::candidates( '/old/', '/' ) );
		$this->assertSame( array(), Redirect_Manager::candidates( '', '' ) );
	}

	public function test_is_protected() {
		$wc = array( '/cart', '/checkout', '/my-account' );
		$this->assertTrue( Redirect_Manager::is_protected( '/wp-admin', '', $wc ) );
		$this->assertTrue( Redirect_Manager::is_protected( '/wp-admin/edit.php', '', $wc ) );
		$this->assertTrue( Redirect_Manager::is_protected( '/wp-json/wc/v3/products', '', $wc ) );
		$this->assertTrue( Redirect_Manager::is_protected( '/wp-login.php', '', $wc ) );
		$this->assertTrue( Redirect_Manager::is_protected( '/', 'rest_route=/wc/v3', $wc ) );
		$this->assertTrue( Redirect_Manager::is_protected( '/', 'a=1&rest_route=/x', $wc ) );
		$this->assertTrue( Redirect_Manager::is_protected( '/cart', '', $wc ) );
		$this->assertTrue( Redirect_Manager::is_protected( '/checkout/order-pay/5', '', $wc ) );
		$this->assertTrue( Redirect_Manager::is_protected( '/my-account/orders', '', $wc ) );
		$this->assertFalse( Redirect_Manager::is_protected( '/cartoon', '', $wc ) );
		$this->assertFalse( Redirect_Manager::is_protected( '/wp-adminish', '', $wc ) );
		$this->assertFalse( Redirect_Manager::is_protected( '/product/x', 'x=rest_route', $wc ) );
		$this->assertFalse( Redirect_Manager::is_protected( '/', '', array( '/' ) ), 'a WC page at the root never protects everything' );
	}

	/* ---------------- request handling ---------------- */

	public function test_handle_request_redirects_and_counts() {
		$m = $this->manager();
		$this->seed(
			$m,
			array(
				'/old'  => array( 'target_url' => '/new/', 'status_code' => 301 ),
				'/ext'  => array( 'target_url' => 'https://other.test/x', 'status_code' => 302 ),
				'/gone' => array( 'target_url' => '', 'status_code' => 410 ),
				'/cart' => array( 'target_url' => '/new/', 'status_code' => 301 ),
			)
		);

		$this->request( $m, '/old/?utm=1' );
		$this->request( $m, '/old' );
		$this->request( $m, '/ext', 'HEAD' );
		$this->request( $m, '/gone' );
		$this->request( $m, '/missing' );
		$this->request( $m, '/old', 'POST' );
		$this->request( $m, '/cart' );
		$this->request( $m, '/?rest_route=/old' );

		$this->assertSame(
			array(
				array( 301, 'https://shop.test/new/' ),
				array( 301, 'https://shop.test/new/' ),
				array( 302, 'https://other.test/x' ),
				array( 410, '' ),
			),
			$m->responses
		);
		$drained = Redirect_Manager::drain_hits();
		$this->assertSame( array( '/old' => 2, '/ext' => 1, '/gone' => 1 ), $drained['hits'] );
		$this->assertNotNull( $drained['since'] );
		$this->assertSame( array( 'hits' => array(), 'since' => null ), Redirect_Manager::drain_hits(), 'drained' );
	}

	public function test_handle_request_is_a_noop_without_rows() {
		$m                         = $this->manager();
		$_SERVER['REQUEST_URI']    = '/old';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$m->handle_request();
		$this->assertSame( array(), $m->responses );
		$this->assertFalse( $m->is_active() );
		$this->assertArrayNotHasKey( Redirect_Manager::OPTION, $GLOBALS['__seo_options'], 'table never read/created' );
	}

	public function test_hit_counter_caps_keys() {
		$m = $this->manager();
		for ( $i = 0; $i < Redirect_Manager::MAX_ROWS; $i++ ) {
			$m->count_hit( '/p' . $i );
		}
		$m->count_hit( '/overflow' );
		$m->count_hit( '/p0' );
		$hits = Redirect_Manager::drain_hits()['hits'];
		$this->assertCount( Redirect_Manager::MAX_ROWS, $hits );
		$this->assertArrayNotHasKey( '/overflow', $hits );
		$this->assertSame( 2, $hits['/p0'] );
	}

	/* ---------------- table ---------------- */

	public function test_replace_keeps_auto_rows_unless_overridden_and_stores_without_autoload() {
		$m = $this->manager();
		$m->add_auto_redirect( 'https://shop.test/product/a/', 'https://shop.test/product/b/', 1, 'slug' );
		$m->add_auto_redirect( 'https://shop.test/product/c/', 'https://shop.test/product-category/x/', 2, 'trash' );
		$this->assertSame( '1', $GLOBALS['__seo_options'][ Redirect_Manager::ACTIVE_OPTION ] );
		$this->assertFalse( $GLOBALS['__seo_option_autoload'][ Redirect_Manager::OPTION ] );
		$this->assertTrue( $GLOBALS['__seo_option_autoload'][ Redirect_Manager::ACTIVE_OPTION ] );

		$this->seed(
			$m,
			array(
				'/m'         => array( 'target_url' => '/n', 'status_code' => 301, 'id' => 3 ),
				'/product/c' => array( 'target_url' => '/other', 'status_code' => 302 ),
			),
			'h2'
		);

		$this->assertSame(
			array(
				array( 'source_path' => '/m', 'target_url' => '/n', 'status_code' => 301, 'auto' => false, 'id' => 3 ),
				array( 'source_path' => '/product/c', 'target_url' => '/other', 'status_code' => 302, 'auto' => false ),
				array( 'source_path' => '/product/a', 'target_url' => 'https://shop.test/product/b/', 'status_code' => 301, 'auto' => true ),
			),
			$m->export()
		);
		$this->assertSame( 'h2', $m->hash() );
		$this->assertFalse( $GLOBALS['__seo_option_autoload'][ Redirect_Manager::OPTION ] );

		$this->seed( $m, array(), 'h3' );
		$this->assertCount( 1, $m->export(), 'empty PUT clears merchant rows, auto survives' );
		$this->assertSame( '1', $GLOBALS['__seo_options'][ Redirect_Manager::ACTIVE_OPTION ] );

		// Naming the auto row's source turns it into a merchant row; the next empty PUT clears it.
		$this->seed( $m, array( '/product/a' => array( 'target_url' => '/x', 'status_code' => 301 ) ), 'h4' );
		$this->assertFalse( $m->export()[0]['auto'] );
		$this->seed( $m, array(), 'h5' );
		$this->assertSame( array(), $m->export() );
		$this->assertSame( '0', $GLOBALS['__seo_options'][ Redirect_Manager::ACTIVE_OPTION ] );
	}

	public function test_replace_refuses_when_merge_exceeds_cap() {
		$m = $this->manager();
		$m->add_auto_redirect( 'https://shop.test/product/a/', 'https://shop.test/product/b/', 1, 'slug' );
		$rows = array();
		for ( $i = 0; $i < Redirect_Manager::MAX_ROWS; $i++ ) {
			$rows[ '/r' . $i ] = array( 'target_url' => '/t', 'status_code' => 301 );
		}
		$this->assertFalse( $m->replace_merchant_rows( $rows, 'h' ) );
		$this->assertCount( 1, $m->export(), 'nothing stored' );
		$this->assertNull( $m->hash() );
	}

	/* ---------------- auto redirects ---------------- */

	private function post( string $type, string $name, string $status = 'publish' ): object {
		return (object) array(
			'post_type'   => $type,
			'post_name'   => $name,
			'post_status' => $status,
		);
	}

	public function test_slug_change_adds_auto_row_for_published_posts_only() {
		$m = $this->manager();

		$m->on_post_updated( 1, $this->post( 'product', 'new' ), $this->post( 'product', 'old' ) );
		$m->on_post_updated( 2, $this->post( 'page', 'about-us' ), $this->post( 'page', 'about' ) );
		$m->on_post_updated( 3, $this->post( 'post', 'same' ), $this->post( 'post', 'same' ) );
		$m->on_post_updated( 4, $this->post( 'product', 'live' ), $this->post( 'product', 'draft-name', 'draft' ) );
		$m->on_post_updated( 5, $this->post( 'product', 'restored' ), $this->post( 'product', 'restored__trashed', 'trash' ) );
		$m->on_post_updated( 6, $this->post( 'attachment', 'b' ), $this->post( 'attachment', 'a' ) );
		$m->on_post_updated( 7, $this->post( 'product', 'x' ), $this->post( 'product', '' ) );

		$this->assertSame(
			array(
				'/product/old' => array( 'target_url' => 'https://shop.test/product/new/', 'status_code' => 301, 'auto' => true, 'reason' => 'slug', 'post_id' => 1 ),
				'/page/about'  => array( 'target_url' => 'https://shop.test/page/about-us/', 'status_code' => 301, 'auto' => true, 'reason' => 'slug', 'post_id' => 2 ),
			),
			$m->rows()
		);
	}

	public function test_slug_change_never_overrides_existing_row_and_repoints_chains() {
		$m = $this->manager();
		$this->seed( $m, array( '/product/old' => array( 'target_url' => '/merchant', 'status_code' => 302 ) ) );

		$m->on_post_updated( 1, $this->post( 'product', 'new' ), $this->post( 'product', 'old' ) );
		$this->assertSame( '/merchant', $m->rows()['/product/old']['target_url'], 'merchant row wins' );
		$this->assertFalse( $m->rows()['/product/old']['auto'] ?? false );

		// a → b, then b → c: the a row is re-pointed to c (no chain).
		$m->on_post_updated( 2, $this->post( 'product', 'b' ), $this->post( 'product', 'a' ) );
		$m->on_post_updated( 2, $this->post( 'product', 'c' ), $this->post( 'product', 'b' ) );
		$this->assertSame( 'https://shop.test/product/c/', $m->rows()['/product/a']['target_url'] );
		$this->assertSame( 'https://shop.test/product/c/', $m->rows()['/product/b']['target_url'] );

		// c → a: the auto row at the new live path (a) is removed, b and c point at a.
		$m->on_post_updated( 2, $this->post( 'product', 'a' ), $this->post( 'product', 'c' ) );
		$this->assertArrayNotHasKey( '/product/a', $m->rows() );
		$this->assertSame( 'https://shop.test/product/a/', $m->rows()['/product/b']['target_url'] );
		$this->assertSame( 'https://shop.test/product/a/', $m->rows()['/product/c']['target_url'] );
	}

	public function test_trash_adds_category_redirect_and_untrash_removes_it() {
		$m                   = $this->manager();
		$m->types            = array( 10 => 'product', 11 => 'product', 12 => 'page', 13 => 'product' );
		$m->statuses         = array( 10 => 'publish', 11 => 'draft', 12 => 'publish', 13 => 'publish' );
		$m->permalinks       = array(
			10 => 'https://shop.test/product/gone/',
			11 => 'https://shop.test/product/draft/',
			12 => 'https://shop.test/some-page/',
			13 => 'https://shop.test/product/no-target/',
		);
		$m->trash_targets    = array(
			10 => 'https://shop.test/product-category/shoes/',
			11 => 'https://shop.test/product-category/shoes/',
			12 => 'https://shop.test/',
		);

		$m->on_trash_post( 10, 'publish' );
		$m->on_trash_post( 11 );
		$m->on_trash_post( 12, 'publish' );
		$m->on_trash_post( 13, 'publish' );

		$this->assertSame(
			array(
				'/product/gone' => array( 'target_url' => 'https://shop.test/product-category/shoes/', 'status_code' => 301, 'auto' => true, 'reason' => 'trash', 'post_id' => 10 ),
			),
			$m->rows()
		);

		// Previous status read from the post when WP < 6.3 passes none.
		$m->statuses[11] = 'publish';
		$m->on_trash_post( 11 );
		$this->assertArrayHasKey( '/product/draft', $m->rows() );

		// A slug-change auto row for the same product survives untrash; the trash row goes.
		$m->on_post_updated( 10, $this->post( 'product', 'gone' ), $this->post( 'product', 'older' ) );
		$m->on_untrash_post( 10 );
		$this->assertArrayNotHasKey( '/product/gone', $m->rows() );
		$this->assertArrayHasKey( '/product/older', $m->rows() );
		$this->assertArrayHasKey( '/product/draft', $m->rows() );
	}

	public function test_auto_rows_skip_at_cap_and_trivial_cases() {
		$m    = $this->manager();
		$rows = array();
		for ( $i = 0; $i < Redirect_Manager::MAX_ROWS; $i++ ) {
			$rows[ '/r' . $i ] = array( 'target_url' => '/t', 'status_code' => 301 );
		}
		$this->seed( $m, $rows );
		$this->assertFalse( $m->add_auto_redirect( 'https://shop.test/product/a/', 'https://shop.test/product/b/', 1, 'slug' ) );
		$this->assertCount( Redirect_Manager::MAX_ROWS, $m->rows() );

		$GLOBALS['__seo_options'] = array();
		$m2                       = $this->manager();
		$this->assertFalse( $m2->add_auto_redirect( 'https://shop.test/a/', 'https://shop.test/a', 1, 'slug' ), 'same path' );
		$this->assertFalse( $m2->add_auto_redirect( 'https://shop.test/', 'https://shop.test/x/', 1, 'slug' ), 'root source' );
		$this->assertFalse( $m2->add_auto_redirect( 'https://shop.test/a/', '', 1, 'slug' ), 'empty target' );
		$this->assertSame( array(), $m2->rows() );
		$this->assertFalse( $m2->is_active() );
	}

	/* ---------------- review fixes 2026-10-19 ---------------- */

	public function test_validate_rejects_home_page_and_protected_sources() {
		$rows  = array(
			array( 'source_path' => '/', 'target_url' => '/x', 'status_code' => 301 ),
			array( 'source_path' => 'https://shop.test/', 'target_url' => '/x', 'status_code' => 301 ),
			array( 'source_path' => '/wp-admin/edit.php', 'target_url' => '/x', 'status_code' => 301 ),
			array( 'source_path' => '/wp-json/wc/v3', 'target_url' => '/x', 'status_code' => 301 ),
			array( 'source_path' => '/wp-login.php', 'target_url' => '/x', 'status_code' => 301 ),
			array( 'source_path' => '/index.php?rest_route=/wc/v3', 'target_url' => '/x', 'status_code' => 301 ),
			array( 'source_path' => '/?p=123', 'target_url' => '/product/x/', 'status_code' => 301 ),
			array( 'source_path' => '/wp-adminx', 'target_url' => '/x', 'status_code' => 301 ),
		);
		$valid = Redirect_Manager::validate_rows( $rows, 'https://shop.test' );
		$this->assertSame( array( 0, 1, 2, 3, 4, 5 ), array_column( $valid['errors'], 'index' ) );
		$this->assertSame( 'source_path must not be the home page', $valid['errors'][0]['reason'] );
		$this->assertSame( 'source_path is a protected WordPress path', $valid['errors'][2]['reason'] );
		$this->assertSame( array( '/?p=123', '/wp-adminx' ), array_keys( $valid['rows'] ), 'old ?p= links on the root stay allowed' );
	}

	public function test_validate_remove() {
		$this->assertSame( array( 'paths' => array(), 'errors' => array() ), Redirect_Manager::validate_remove( null ) );
		$ok = Redirect_Manager::validate_remove( array( '/product/a/', 'https://shop.test/product/a', 'product/b' ) );
		$this->assertSame( array( '/product/a', '/product/b' ), $ok['paths'], 'normalised + de-duplicated' );
		$this->assertSame( array(), $ok['errors'] );
		$bad = Redirect_Manager::validate_remove( array( '/ok', 5, '', '   ' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $bad['errors'], 'index' ) );
		$this->assertCount( 1, Redirect_Manager::validate_remove( 'nope' )['errors'] );
		$this->assertCount( 1, Redirect_Manager::validate_remove( array_fill( 0, Redirect_Manager::MAX_ROWS + 1, '/a' ) )['errors'] );
	}

	public function test_replace_with_remove_drops_named_auto_rows_only() {
		$m = $this->manager();
		$m->add_auto_redirect( 'https://shop.test/product/a/', 'https://shop.test/product/b/', 1, 'slug' );
		$m->add_auto_redirect( 'https://shop.test/product/c/', 'https://shop.test/product/d/', 2, 'slug' );
		$this->assertTrue( $m->replace_merchant_rows( array( '/m' => array( 'target_url' => '/x', 'status_code' => 301 ) ), 'h', array( '/product/a', '/unknown' ) ) );
		$this->assertSame( array( '/m', '/product/c' ), array_keys( $m->rows() ) );
	}

	public function test_editor_can_bypass_with_query_arg() {
		$m = $this->manager();
		$this->seed( $m, array( '/old' => array( 'target_url' => '/new/', 'status_code' => 301 ) ) );

		$_GET[ Redirect_Manager::BYPASS_ARG ] = '1';
		$this->request( $m, '/old?' . Redirect_Manager::BYPASS_ARG . '=1' );
		$this->assertCount( 1, $m->responses, 'visitor: the arg does nothing' );

		$m->can_bypass = true;
		$this->request( $m, '/old?' . Redirect_Manager::BYPASS_ARG . '=1' );
		$this->assertCount( 1, $m->responses, 'editor: no redirect' );
		$this->assertSame( array( '/old' => 1 ), Redirect_Manager::drain_hits()['hits'], 'bypass is not a hit' );
	}

	public function test_publishing_a_post_at_an_auto_source_drops_that_auto_row() {
		$m = $this->manager();
		$m->types[10]         = 'product';
		$m->statuses[10]      = 'publish';
		$m->permalinks[10]    = 'https://shop.test/product/shoe/';
		$m->trash_targets[10] = 'https://shop.test/product-category/shoes/';
		$m->on_trash_post( 10, 'publish' );
		$this->seed( $m, array( '/product/merchant' => array( 'target_url' => '/x', 'status_code' => 301 ) ) );
		$this->assertArrayHasKey( '/product/shoe', $m->rows() );

		// A new product (ID 11) is published with the freed slug `shoe`.
		$m->permalinks[11] = 'https://shop.test/product/shoe/';
		$draft             = (object) array( 'post_type' => 'product', 'post_status' => 'draft' );
		$m->on_post_saved( 11, $draft );
		$this->assertArrayHasKey( '/product/shoe', $m->rows(), 'drafts do not own a public URL' );

		$m->on_post_saved( 11, (object) array( 'post_type' => 'product', 'post_status' => 'publish' ) );
		$this->assertArrayNotHasKey( '/product/shoe', $m->rows(), 'the live product is no longer hijacked' );

		$m->permalinks[12] = 'https://shop.test/product/merchant/';
		$m->on_post_saved( 12, (object) array( 'post_type' => 'product', 'post_status' => 'publish' ) );
		$this->assertArrayHasKey( '/product/merchant', $m->rows(), 'merchant rows are Storedash-owned' );

		$m->on_post_saved( 13, (object) array( 'post_type' => 'attachment', 'post_status' => 'publish' ) );
		$this->assertCount( 1, $m->rows() );
	}
}
