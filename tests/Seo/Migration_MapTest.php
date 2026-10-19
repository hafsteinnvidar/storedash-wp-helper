<?php
/**
 * Migration_Map: cross-language fixture, robots, templates, redirects.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Meta_Writer;
use StoreDash\Seo\Engine\Migration_Map;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Migration_Map
 */
class Migration_MapTest extends TestCase {

	private function fixture(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/../../inc/Seo/fixtures/migration-map.json' ), true );
	}

	public function test_maps_equal_the_fixture() {
		$f = $this->fixture();

		// shared (9 fields woo-dash seo-meta.ts reads) + extra (3 read-only) = our maps.
		$this->assertCount( 9, $f['shared']['fields'] );
		$this->assertCount( 3, $f['extra']['fields'] );
		foreach ( array( 'yoast', 'rankmath', 'storedash' ) as $side ) {
			$this->assertSame( $f['shared']['fields'], array_keys( $f['shared'][ $side ] ), $side );
		}
		foreach ( array( 'yoast', 'rankmath' ) as $side ) {
			$this->assertSame( $f['extra']['fields'], array_keys( $f['extra'][ $side ] ), $side );
		}
		$this->assertEquals( $f['shared']['yoast'] + $f['extra']['yoast'], Migration_Map::YOAST );
		$this->assertEquals( $f['shared']['rankmath'] + $f['extra']['rankmath'], Migration_Map::RANKMATH );
		$this->assertSame( $f['shared']['storedash'], array_intersect_key( Migration_Map::STOREDASH, $f['shared']['storedash'] ) );
		foreach ( $f['extra']['fields'] as $field ) {
			$this->assertArrayNotHasKey( $field, Migration_Map::STOREDASH, $field . ' is read-only' );
		}

		$this->assertSame( $f['yoast_robots'], Migration_Map::YOAST_ROBOTS_KEYS );
		$this->assertSame( $f['rankmath_robots'], Migration_Map::RANKMATH_ROBOTS_KEY );
		$this->assertSame( $f['yoast_extra'], Migration_Map::YOAST_EXTRA );
		$this->assertSame( $f['rankmath_extra'], Migration_Map::RANKMATH_EXTRA );
		$this->assertSame( $f['storedash'], Migration_Map::STOREDASH );
	}

	public function test_yoast_separator_lt_gt_replaced_with_guillemets() {
		$this->assertSame( '‹', Migration_Map::separator( 'yoast', 'sc-gt' ) );
		$this->assertSame( '›', Migration_Map::separator( 'yoast', 'sc-lt' ) );
		$this->assertSame( '‹', Migration_Map::separator( 'rankmath', '&lt;' ) );
		$this->assertSame( '|', Migration_Map::separator( 'yoast', 'sc-pipe' ) );

		$out = Migration_Map::site_templates( 'yoast', array( 'separator' => 'sc-gt', 'title-product' => '%%title%%' ) );
		$this->assertSame( '‹', $out['templates']['title_separator'] );
		$this->assertContains( "separator '<' replaced with '‹'", $out['warnings'] );
		// The replacement survives Settings::normalize() (the raw `<` would not).
		$this->assertSame( '‹', StoreDash\Seo\Settings::normalize( array( 'title_separator' => '‹' ) )['title_separator'] );
		$this->assertSame( '', StoreDash\Seo\Settings::normalize( array( 'title_separator' => '<' ) )['title_separator'] );

		$out = Migration_Map::site_templates( 'yoast', array( 'separator' => 'sc-pipe' ) );
		$this->assertSame( array(), $out['warnings'] );
	}

	public function test_yoast_redirects_keyed_by_origin() {
		$keyed = array(
			'old-a'                   => array( 'url' => '/new-a', 'type' => 301, 'format' => 'plain' ),
			'https://shop.test/old-b' => array( 'url' => 'https://shop.test/new-b', 'type' => 302, 'format' => 'plain' ),
			'^re'                     => array( 'url' => '/x', 'type' => 301, 'format' => 'regex' ),
			0                         => array( 'url' => '/no-origin', 'type' => 301, 'format' => 'plain' ),
		);
		$out   = Migration_Map::yoast_redirects( $keyed, 100 );
		$this->assertSame(
			array(
				array( 'source_path' => '/old-a', 'target_url' => '/new-a', 'status_code' => 301 ),
				array( 'source_path' => '/old-b', 'target_url' => 'https://shop.test/new-b', 'status_code' => 302 ),
			),
			$out['redirects']
		);
		$this->assertStringContainsString( 'regex', $out['warnings'][0] );

		// Explicit origin wins over the key.
		$mixed = array( 'ignored-key' => array( 'origin' => 'from-origin', 'url' => '/t', 'type' => 301, 'format' => 'plain' ) );
		$this->assertSame( '/from-origin', Migration_Map::yoast_redirects( $mixed, 100 )['redirects'][0]['source_path'] );
	}

	public function test_storedash_map_agrees_with_meta_writer() {
		foreach ( Migration_Map::STOREDASH as $field => $key ) {
			$this->assertSame( $key, Meta_Writer::FIELDS[ Migration_Map::API_FIELDS[ $field ] ], $field );
		}
	}

	public function test_robots_cases_from_fixture() {
		foreach ( $this->fixture()['robots_cases'] as $i => $case ) {
			$meta = array();
			foreach ( $case['meta'] as $key => $value ) {
				// get_post_meta( $id ) shape: list of raw (serialized) strings.
				$meta[ $key ] = array( is_array( $value ) ? serialize( $value ) : $value );
			}
			$actual = 'yoast' === $case['source']
				? Migration_Map::yoast_robots( $meta )
				: Migration_Map::rankmath_robots( StoreDash\Seo\Engine\Meta_Reader::first( $meta, Migration_Map::RANKMATH_ROBOTS_KEY ) );
			$this->assertSame( $case['expected'], $actual, 'case ' . $i );
		}
	}

	public function test_extract_yoast_post() {
		$out = Migration_Map::extract(
			'yoast',
			array(
				'_yoast_wpseo_title'                 => array( 'Yoast Title' ),
				'_yoast_wpseo_metadesc'              => array( 'Desc' ),
				'_yoast_wpseo_focuskw'               => array( '' ),
				'_yoast_wpseo_opengraph-image'       => array( 'https://shop.test/og.jpg' ),
				'_yoast_wpseo_meta-robots-noindex'   => array( '1' ),
				'_yoast_wpseo_primary_product_cat'   => array( '15' ),
				'_yoast_wpseo_content_score'         => array( '90' ),
			)
		);
		$this->assertSame( 'Yoast Title', $out['fields']['seo_title'] );
		$this->assertSame( 'Desc', $out['fields']['seo_description'] );
		$this->assertSame( 'https://shop.test/og.jpg', $out['fields']['og_image'] );
		$this->assertArrayNotHasKey( 'focus_keyword', $out['fields'] );
		$this->assertFalse( $out['fields']['robots']['index'] );
		$this->assertSame( 15, $out['primary_category_id'] );
	}

	public function test_extract_rankmath_post() {
		$out = Migration_Map::extract(
			'rankmath',
			array(
				'rank_math_title'               => array( 'RM Title' ),
				'rank_math_focus_keyword'       => array( 'shirt,blue shirt' ),
				'rank_math_facebook_title'      => array( 'FB' ),
				'rank_math_robots'              => array( serialize( array( 'index' ) ) ),
				'rank_math_primary_product_cat' => array( 'x' ),
			)
		);
		$this->assertSame( 'RM Title', $out['fields']['seo_title'] );
		$this->assertSame( 'shirt,blue shirt', $out['fields']['focus_keyword'] );
		$this->assertSame( 'FB', $out['fields']['og_title'] );
		$this->assertSame( Migration_Map::all_true(), $out['fields']['robots'] );
		$this->assertNull( $out['primary_category_id'] );
	}

	public function test_extract_yoast_term_entry() {
		$out = Migration_Map::extract_yoast_term(
			array(
				'wpseo_title'   => 'Cat title',
				'wpseo_desc'    => 'Cat desc',
				'wpseo_noindex' => 'noindex',
				'wpseo_linkdex' => '50',
			)
		);
		$this->assertSame( 'Cat title', $out['fields']['seo_title'] );
		$this->assertSame( 'Cat desc', $out['fields']['seo_description'] );
		$this->assertFalse( $out['fields']['robots']['index'] );

		$out = Migration_Map::extract_yoast_term( array( 'wpseo_noindex' => 'default' ) );
		$this->assertSame( array(), $out['fields'] );
	}

	public function test_has_variables() {
		$this->assertTrue( Migration_Map::has_variables( 'yoast', '%%title%% %%sep%% Shop' ) );
		$this->assertFalse( Migration_Map::has_variables( 'yoast', '50% off 20%' ) );
		$this->assertTrue( Migration_Map::has_variables( 'rankmath', '%title% | Shop' ) );
		$this->assertTrue( Migration_Map::has_variables( 'rankmath', 'Buy %customfield(color)% now' ) );
		$this->assertFalse( Migration_Map::has_variables( 'rankmath', 'Save 50% off 20% more' ) );
	}

	public function test_yoast_site_templates() {
		$out = Migration_Map::site_templates(
			'yoast',
			array(
				'separator'             => 'sc-ndash',
				'title-product'         => '%%title%% %%page%% %%sep%% %%sitename%%',
				'title-tax-product_cat' => '%%term_title%% Archives %%page%% %%sep%% %%sitename%%',
				'title-page'            => '%%title%% %%sep%% %%sitename%% %%sitedesc%%',
			)
		);
		$this->assertSame(
			array(
				'title_template_product'  => '{product} {sep} {store}',
				'title_template_category' => '{category} Archives {sep} {store}',
				'title_template_page'     => '{page} {sep} {store}',
				'title_separator'         => '–',
			),
			$out['templates']
		);
		$this->assertCount( 3, $out['warnings'] );
		$this->assertStringContainsString( '%%page%%', $out['warnings'][0] );
		$this->assertStringContainsString( '%%sitedesc%%', $out['warnings'][2] );
	}

	public function test_rankmath_site_templates_and_separator_entity() {
		$out = Migration_Map::site_templates(
			'rankmath',
			array(
				'title_separator'       => '&ndash;',
				'pt_product_title'      => '%title% %sep% %sitename%',
				'tax_product_cat_title' => '%term% %sep% %sitename%',
			)
		);
		$this->assertSame( '{product} {sep} {store}', $out['templates']['title_template_product'] );
		$this->assertSame( '{category} {sep} {store}', $out['templates']['title_template_category'] );
		$this->assertNull( $out['templates']['title_template_page'] );
		$this->assertSame( '–', $out['templates']['title_separator'] );
		$this->assertSame( array(), $out['warnings'] );
	}

	public function test_missing_titles_option_is_null() {
		$this->assertNull( Migration_Map::site_templates( 'yoast', null )['templates'] );
		$this->assertNull( Migration_Map::separator( 'yoast', 'sc-unknown' ) );
	}

	public function test_rankmath_redirects() {
		$rows = array(
			array(
				'sources'     => serialize(
					array(
						array( 'pattern' => 'old-shirt', 'comparison' => 'exact' ),
						array( 'pattern' => 'shirt', 'comparison' => 'contains' ),
					)
				),
				'url_to'      => 'https://shop.test/new-shirt/',
				'header_code' => '301',
			),
			array(
				'sources'     => serialize( array( array( 'pattern' => '/gone', 'comparison' => 'exact' ) ) ),
				'url_to'      => '',
				'header_code' => '410',
			),
			array(
				'sources'     => serialize( array( array( 'pattern' => 'legal', 'comparison' => 'exact' ) ) ),
				'url_to'      => '',
				'header_code' => '451',
			),
		);
		$out  = Migration_Map::rankmath_redirects( $rows, 5000 );
		$this->assertSame(
			array(
				array( 'source_path' => '/old-shirt', 'target_url' => 'https://shop.test/new-shirt/', 'status_code' => 301 ),
				array( 'source_path' => '/gone', 'target_url' => '', 'status_code' => 410 ),
			),
			$out['redirects']
		);
		$this->assertFalse( $out['truncated'] );
		$this->assertCount( 2, $out['warnings'] );
	}

	public function test_yoast_redirects_and_cap() {
		$option = array(
			array( 'origin' => 'a', 'url' => 'b', 'type' => 301, 'format' => 'plain' ),
			array( 'origin' => 'https://shop.test/c?x=1', 'url' => '/d', 'type' => 302, 'format' => 'plain' ),
			array( 'origin' => '^/re.*', 'url' => '/e', 'type' => 301, 'format' => 'regex' ),
			array( 'origin' => 'f', 'url' => '/g', 'type' => 307, 'format' => 'plain' ),
		);
		$out    = Migration_Map::yoast_redirects( $option, 2 );
		$this->assertSame(
			array(
				array( 'source_path' => '/a', 'target_url' => '/b', 'status_code' => 301 ),
				array( 'source_path' => '/c?x=1', 'target_url' => '/d', 'status_code' => 302 ),
			),
			$out['redirects']
		);
		$this->assertTrue( $out['truncated'] );
		$this->assertStringContainsString( 'regex', $out['warnings'][0] );
		$this->assertSame( array(), Migration_Map::yoast_redirects( null, 10 )['redirects'] );
	}
}
