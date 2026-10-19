<?php
/**
 * Seo_Manager gates: sitemap + redirects boot only when the module is on and
 * no other SEO plugin is active (Yoast / RankMath → everything stays off).
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Plugin_Conflict_Guard;
use StoreDash\Seo\Engine\Sitemap_Generator;
use StoreDash\Seo\Seo_Manager;
use StoreDash\Seo\Settings;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Seo_Manager
 */
class Seo_ManagerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__seo_options'] = array();
		Settings::reset_cache();
		Sitemap_Generator::reset_active();
	}

	protected function tearDown(): void {
		$GLOBALS['__seo_options'] = array();
		Settings::reset_cache();
		Sitemap_Generator::reset_active();
	}

	private function manager( ?string $conflict_constant ): Seo_Manager {
		return new Seo_Manager(
			new Plugin_Conflict_Guard(
				static function ( $c ) use ( $conflict_constant ) {
					return null !== $conflict_constant && $c === $conflict_constant;
				},
				static function () {
					return array();
				}
			)
		);
	}

	public function test_off_when_disabled() {
		$m = $this->manager( null );
		$m->init();
		$this->assertFalse( $m->is_module_on() );
		$this->assertFalse( $m->should_render() );
		$this->assertNull( $m->get_sitemap() );
		$this->assertNull( $m->get_redirects() );
		$this->assertFalse( Sitemap_Generator::is_active() );
	}

	public function test_fully_off_when_yoast_is_active() {
		$GLOBALS['__seo_options'][ Settings::ENABLED_OPTION ] = '1';
		$m = $this->manager( 'WPSEO_VERSION' );
		$m->init();
		$this->assertSame( 'yoast', $m->filter_conflict() );
		$this->assertFalse( $m->is_module_on() );
		$this->assertFalse( $m->should_render() );
		$this->assertNull( $m->get_sitemap(), 'Yoast ships its own sitemap' );
		$this->assertNull( $m->get_redirects(), 'Yoast Premium ships its own redirects' );
		$this->assertFalse( Sitemap_Generator::is_active() );
	}

	public function test_fully_off_when_rankmath_is_active() {
		$GLOBALS['__seo_options'][ Settings::ENABLED_OPTION ] = '1';
		$m = $this->manager( 'RANK_MATH_VERSION' );
		$m->init();
		$this->assertSame( 'rankmath', $m->filter_conflict() );
		$this->assertNull( $m->get_sitemap() );
		$this->assertNull( $m->get_redirects() );
	}

	public function test_on_when_enabled_and_no_conflict() {
		$GLOBALS['__seo_options'][ Settings::ENABLED_OPTION ] = '1';
		$m = $this->manager( null );
		$m->init();
		$this->assertTrue( $m->is_module_on() );
		$this->assertTrue( $m->should_render() );
		$this->assertInstanceOf( Sitemap_Generator::class, $m->get_sitemap() );
		$this->assertInstanceOf( \StoreDash\Seo\Engine\Redirect_Manager::class, $m->get_redirects() );
		$this->assertTrue( Sitemap_Generator::is_active() );
	}
}
