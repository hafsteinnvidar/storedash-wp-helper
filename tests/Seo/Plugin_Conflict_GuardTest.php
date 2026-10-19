<?php
/**
 * Plugin_Conflict_Guard: slug per constant, active-plugin fallback, precedence.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Engine\Plugin_Conflict_Guard;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Engine\Plugin_Conflict_Guard
 */
class Plugin_Conflict_GuardTest extends TestCase {

	private function guard( array $constants, array $active = array(), &$active_calls = 0 ): Plugin_Conflict_Guard {
		return new Plugin_Conflict_Guard(
			static function ( $name ) use ( $constants ) {
				return in_array( $name, $constants, true );
			},
			static function () use ( $active, &$active_calls ) {
				++$active_calls;
				return $active;
			}
		);
	}

	public function constant_provider(): array {
		return array(
			'yoast'        => array( 'WPSEO_VERSION', 'yoast' ),
			'rankmath'     => array( 'RANK_MATH_VERSION', 'rankmath' ),
			'seopress'     => array( 'SEOPRESS_VERSION', 'seopress' ),
			'aioseo'       => array( 'AIOSEO_VERSION', 'aioseo' ),
			'seoframework' => array( 'THE_SEO_FRAMEWORK_VERSION', 'seoframework' ),
		);
	}

	/**
	 * @dataProvider constant_provider
	 */
	public function test_constant_maps_to_slug( string $constant, string $slug ) {
		$calls = 0;
		$guard = $this->guard( array( $constant ), array(), $calls );
		$this->assertSame( $slug, $guard->conflict() );
		$this->assertTrue( $guard->detected()[ $slug ] );
		$this->assertSame( 0, $calls, 'active plugins list is not read when a constant matched' );
	}

	public function test_no_seo_plugin_means_no_conflict() {
		$guard = $this->guard( array(), array( 'woocommerce/woocommerce.php', 'storedash/storedash.php' ) );
		$this->assertNull( $guard->conflict() );
		$this->assertSame( array( false ), array_values( array_unique( $guard->detected() ) ) );
	}

	public function test_active_plugin_file_is_detected_without_constant() {
		$this->assertSame( 'rankmath', $this->guard( array(), array( 'seo-by-rank-math/rank-math.php' ) )->conflict() );
		$this->assertSame( 'yoast', $this->guard( array(), array( 'wordpress-seo-premium/wp-seo-premium.php' ) )->conflict() );
		$this->assertSame( 'aioseo', $this->guard( array(), array( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ) )->conflict() );
	}

	public function test_first_plugin_wins_and_result_is_memoized() {
		$calls = 0;
		$guard = $this->guard( array(), array( 'autodescription/autodescription.php', 'wordpress-seo/wp-seo.php' ), $calls );
		$this->assertSame( 'yoast', $guard->conflict() );
		$guard->conflict();
		$guard->detected();
		$this->assertSame( 1, $calls );
		$this->assertTrue( $guard->detected()['seoframework'] );
	}
}
