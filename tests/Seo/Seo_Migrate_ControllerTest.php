<?php
/**
 * Seo_Migrate_Controller: args, pagination across object types, dry run,
 * overwrite, skip reasons, site-level data on offset 0.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Controllers\Seo_Migrate_Controller;

require_once __DIR__ . '/seo-stubs.php';

/**
 * Controller over an in-memory store.
 */
class Seo_Test_Migrate_Controller extends Seo_Migrate_Controller {
	/** @var array<string,array[]> object => list of { id, taxonomy?, meta } */
	public $objects = array();
	/** @var array */
	public $options = array();
	/** @var array|null */
	public $rankmath_rows = null;
	/** @var int */
	public $fetch_calls = 0;

	protected function count_objects( string $object ): int {
		return count( $this->objects[ $object ] ?? array() );
	}

	protected function fetch_objects( string $object, int $offset, int $limit ): array {
		++$this->fetch_calls;
		$slice = array_slice( $this->objects[ $object ] ?? array(), $offset, $limit );
		return array_map(
			static function ( $o ) {
				unset( $o['meta'] );
				return $o;
			},
			$slice
		);
	}

	private function meta( string $type, int $id ): array {
		foreach ( $this->objects as $object => $list ) {
			if ( ( 'term' === $object ) !== ( 'term' === $type ) ) {
				continue;
			}
			foreach ( $list as $o ) {
				if ( $o['id'] === $id ) {
					return $o['meta'];
				}
			}
		}
		return array();
	}

	protected function post_meta( int $id ): array {
		return $this->meta( 'post', $id );
	}

	protected function term_meta( int $id ): array {
		return $this->meta( 'term', $id );
	}

	protected function option( string $name ) {
		return $this->options[ $name ] ?? null;
	}

	protected function rankmath_redirect_rows( int $limit ): ?array {
		return $this->rankmath_rows;
	}
}

/**
 * @covers \StoreDash\Seo\Controllers\Seo_Migrate_Controller
 */
class Seo_Migrate_ControllerTest extends TestCase {

	/** @var array */
	private $log = array();

	private function controller(): Seo_Test_Migrate_Controller {
		$this->log = array();
		$c         = new Seo_Test_Migrate_Controller( seo_recording_writer( $this->log ) );
		$c->objects = array(
			'product' => array(
				array(
					'id'   => 10,
					'meta' => array(
						'_yoast_wpseo_title'               => array( 'Yoast product title' ),
						'_yoast_wpseo_metadesc'            => array( 'Yoast desc' ),
						'_yoast_wpseo_meta-robots-noindex' => array( '1' ),
						'_yoast_wpseo_primary_product_cat' => array( '5' ),
					),
				),
				array(
					'id'   => 11,
					'meta' => array(
						'_yoast_wpseo_title'      => array( '%%title%% %%sep%% %%sitename%%' ),
						'_yoast_wpseo_metadesc'   => array( 'Second desc' ),
						'_yoast_wpseo_canonical'  => array( 'relative/path' ),
						'_storedash_seo_description' => array( 'Ours already' ),
					),
				),
				array(
					'id'   => 12,
					'meta' => array(),
				),
			),
			'term'    => array(
				array(
					'id'       => 5,
					'taxonomy' => 'product_cat',
					'meta'     => array( 'thumbnail_id' => array( '3' ) ),
				),
			),
			'page'    => array(
				array(
					'id'   => 30,
					'meta' => array( '_yoast_wpseo_bctitle' => array( 'Crumb' ) ),
				),
			),
		);
		$c->options = array(
			'wpseo_taxonomy_meta' => array( 'product_cat' => array( 5 => array( 'wpseo_title' => 'Cat SEO', 'wpseo_noindex' => 'noindex' ) ) ),
			'wpseo_titles'        => array(
				'separator'     => 'sc-pipe',
				'title-product' => '%%title%% %%sep%% %%sitename%%',
			),
		);
		return $c;
	}

	private function args( array $over = array() ): array {
		return Seo_Migrate_Controller::normalize_args( array_merge( array( 'from' => 'yoast' ), $over ) );
	}

	public function test_normalize_args_defaults_and_errors() {
		$args = $this->args();
		$this->assertSame(
			array( 'from' => 'yoast', 'dry_run' => true, 'limit' => 100, 'offset' => 0, 'objects' => array( 'product', 'term', 'post', 'page' ), 'overwrite' => false ),
			$args
		);
		$this->assertSame( array( 'product', 'page' ), $this->args( array( 'objects' => array( 'page', 'product' ) ) )['objects'] );

		foreach ( array(
			array( 'from' => 'seopress' ),
			array( 'from' => 'yoast', 'limit' => 201 ),
			array( 'from' => 'yoast', 'limit' => 0 ),
			array( 'from' => 'yoast', 'offset' => -1 ),
			array( 'from' => 'yoast', 'dry_run' => 'false' ),
			array( 'from' => 'yoast', 'objects' => array( 'order' ) ),
			array( 'from' => 'yoast', 'objects' => array() ),
		) as $bad ) {
			$this->assertArrayHasKey( 'error', Seo_Migrate_Controller::normalize_args( $bad ), json_encode( $bad ) );
		}
	}

	public function test_dry_run_writes_nothing_and_reports() {
		$c   = $this->controller();
		$out = $c->run( $this->args() );

		$this->assertSame( array(), $this->log );
		$this->assertTrue( $out['dry_run'] );
		$this->assertSame( 5, $out['processed'] );
		$this->assertSame( 4, $out['written'] );
		$this->assertSame( 1, $out['skipped'] );
		$this->assertSame( 0, $out['remaining'] );
		$this->assertNull( $out['next_offset'] );
		$this->assertSame( array( array( 'product_id' => 10, 'term_id' => 5 ) ), $out['primary_categories'] );

		$first = $out['sample'][0];
		$this->assertSame( array( 'seo_title', 'seo_description', 'robots' ), $first['fields_written'] );
		$this->assertSame( 'Yoast product title', $first['values']['seo_title'] );

		$second = $out['sample'][1];
		$this->assertSame( array( 'seo_title' ), $second['fields_written'] );
		$this->assertSame( '{product} {sep} {store}', $second['values']['seo_title'] );
		$this->assertSame(
			array(
				array( 'field' => 'seo_description', 'reason' => 'exists' ),
				array( 'field' => 'canonical', 'reason' => 'invalid' ),
			),
			$second['fields_skipped']
		);

		$term = $out['sample'][2];
		$this->assertSame( array( 'term', 5, 'product_cat' ), array( $term['object'], $term['id'], $term['taxonomy'] ) );
		$this->assertSame( array( 'seo_title', 'robots' ), $term['fields_written'] );

		$this->assertSame( '{product} {sep} {store}', $out['templates']['title_template_product'] );
		$this->assertSame( '|', $out['templates']['title_separator'] );
		$this->assertSame( array(), $out['redirects'] );
	}

	public function test_real_run_writes_our_keys_only() {
		$c   = $this->controller();
		$out = $c->run( $this->args( array( 'dry_run' => false ) ) );

		$this->assertFalse( $out['dry_run'] );
		$keys = array();
		foreach ( $this->log as $entry ) {
			if ( 'update' === $entry[0] ) {
				$keys[] = $entry[1] . ':' . $entry[2] . ':' . $entry[3];
				$this->assertStringStartsWith( '_storedash_seo_', $entry[3] );
			}
			$this->assertNotSame( 'delete', $entry[0] );
		}
		$this->assertSame(
			array(
				'post:10:_storedash_seo_title',
				'post:10:_storedash_seo_description',
				'post:10:_storedash_seo_robots',
				'post:11:_storedash_seo_title',
				'term:5:_storedash_seo_title',
				'term:5:_storedash_seo_robots',
				'post:30:_storedash_seo_breadcrumb_title',
			),
			$keys
		);
	}

	public function test_overwrite_replaces_existing_values() {
		$c   = $this->controller();
		$out = $c->run( $this->args( array( 'overwrite' => true, 'objects' => array( 'product' ) ) ) );
		$this->assertSame( array( 'seo_title', 'seo_description' ), $out['sample'][1]['fields_written'] );
		$this->assertSame( 'Second desc', $out['sample'][1]['values']['seo_description'] );
	}

	public function test_pagination_spans_object_types() {
		$c = $this->controller();

		$page1 = $c->run( $this->args( array( 'limit' => 2 ) ) );
		$this->assertSame( 2, $page1['processed'] );
		$this->assertSame( 3, $page1['remaining'] );
		$this->assertSame( 2, $page1['next_offset'] );

		$page2 = $c->run( $this->args( array( 'limit' => 2, 'offset' => 2 ) ) );
		$this->assertSame( array( array( 'product', 12 ), array( 'term', 5 ) ), $this->ids( $page2 ) );
		$this->assertSame( 1, $page2['remaining'] );
		$this->assertSame( 4, $page2['next_offset'] );
		// Site-level data only on offset 0.
		$this->assertNull( $page2['templates'] );

		$page3 = $c->run( $this->args( array( 'limit' => 2, 'offset' => 4 ) ) );
		$this->assertSame( array( array( 'page', 30 ) ), $this->ids( $page3 ) );
		$this->assertSame( 0, $page3['remaining'] );
		$this->assertNull( $page3['next_offset'] );

		$beyond = $c->run( $this->args( array( 'offset' => 50 ) ) );
		$this->assertSame( 0, $beyond['processed'] );
		$this->assertSame( 0, $beyond['remaining'] );
	}

	/**
	 * Object/id of every processed item (sample holds all items with activity;
	 * product 12 has none, so it is reconstructed from counts).
	 */
	private function ids( array $out ): array {
		$ids = array_map(
			static function ( $s ) {
				return array( $s['object'], $s['id'] );
			},
			$out['sample']
		);
		if ( $out['skipped'] > 0 ) {
			array_unshift( $ids, array( 'product', 12 ) );
		}
		return $ids;
	}

	public function test_rankmath_term_meta_and_missing_redirect_table() {
		$c          = $this->controller();
		$c->objects = array(
			'term' => array(
				array(
					'id'       => 7,
					'taxonomy' => 'product_tag',
					'meta'     => array(
						'rank_math_title'  => array( 'Tag title' ),
						'rank_math_robots' => array( serialize( array( 'noindex', 'follow' ) ) ),
					),
				),
			),
		);
		$c->options = array( 'rank-math-options-titles' => array( 'title_separator' => '&raquo;' ) );

		$out = $c->run( Seo_Migrate_Controller::normalize_args( array( 'from' => 'rankmath', 'dry_run' => false ) ) );
		$this->assertSame( array( 'seo_title', 'robots' ), $out['sample'][0]['fields_written'] );
		$this->assertSame( '»', $out['templates']['title_separator'] );
		$this->assertContains( 'RankMath redirections table not found', $out['warnings'] );

		$c->rankmath_rows = array(
			array(
				'sources'     => serialize( array( array( 'pattern' => 'old', 'comparison' => 'exact' ) ) ),
				'url_to'      => '/new',
				'header_code' => '301',
			),
		);
		$out = $c->run( Seo_Migrate_Controller::normalize_args( array( 'from' => 'rankmath' ) ) );
		$this->assertSame( array( array( 'source_path' => '/old', 'target_url' => '/new', 'status_code' => 301 ) ), $out['redirects'] );
	}

	public function test_title_variables_translate_or_skip() {
		$plan = static function ( string $from, array $fields, string $kind = 'product' ) {
			return Seo_Migrate_Controller::plan_fields( $from, $fields, StoreDash\Seo\Engine\Meta_Reader::normalize( array() ), false, $kind );
		};

		// Yoast default incl. %%page%% (empty on single objects) → translated.
		$p = $plan( 'yoast', array( 'seo_title' => '%%title%% %%page%% %%sep%% %%sitename%%' ) );
		$this->assertSame( '{product} {sep} {store}', $p['values']['seo_title'] );
		$this->assertSame( array( '_storedash_seo_title' => '{product} {sep} {store}' ), $p['prepared']['set'] );

		$p = $plan( 'rankmath', array( 'seo_title' => '%term% %sep% %sitename%' ), 'category' );
		$this->assertSame( '{category} {sep} {store}', $p['values']['seo_title'] );

		// Variable without a Storedash equivalent → skipped.
		$p = $plan( 'yoast', array( 'seo_title' => '%%title%% %%sitedesc%%' ) );
		$this->assertSame( array( array( 'field' => 'seo_title', 'reason' => 'template_variables' ) ), $p['skipped'] );

		// Variables in other fields stay skipped (printed verbatim).
		$p = $plan( 'yoast', array( 'seo_description' => '%%excerpt%%', 'og_title' => '%%title%%' ) );
		$this->assertSame( array(), $p['written'] );
		$this->assertCount( 2, $p['skipped'] );
	}

	public function test_empty_page_ends_the_walk() {
		// Counts say 3 products, but the page query returns none (caps / private
		// posts): must not hand the client the same offset back.
		$c = new class( seo_recording_writer( $this->log ) ) extends Seo_Test_Migrate_Controller {
			protected function fetch_objects( string $object, int $offset, int $limit ): array {
				++$this->fetch_calls;
				return array();
			}
		};
		$c->objects = $this->controller()->objects;

		$out = $c->run( $this->args( array( 'limit' => 2, 'offset' => 2 ) ) );
		$this->assertSame( 0, $out['processed'] );
		$this->assertSame( 0, $out['remaining'] );
		$this->assertNull( $out['next_offset'] );
		$this->assertGreaterThan( 0, $c->fetch_calls );
	}

	public function test_objects_with_non_string_elements_rejected_without_notice() {
		foreach ( array( array( array( 'product' ) ), array( 'product', 1 ), array( null ), array( 'product', array() ) ) as $objects ) {
			$out = Seo_Migrate_Controller::normalize_args( array( 'from' => 'yoast', 'objects' => $objects ) );
			$this->assertSame( 'objects must contain strings only', $out['error'] ?? null, json_encode( $objects ) );
		}
	}

	public function test_controller_returns_400_on_bad_body() {
		$out = $this->controller()->migrate( new Seo_Test_Request( array( 'from' => 'aioseo' ) ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( array( 'status' => 400 ), $out->get_error_data() );
	}
}
