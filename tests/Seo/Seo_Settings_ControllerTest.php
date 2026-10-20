<?php
/**
 * Seo_Settings_Controller: PUT validation, storage, reset_cache, GET/status shape.
 *
 * @package StoreDash\Tests\Seo
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Seo\Controllers\Seo_Settings_Controller;
use StoreDash\Seo\Engine\Plugin_Conflict_Guard;
use StoreDash\Seo\Settings;

require_once __DIR__ . '/seo-stubs.php';

/**
 * @covers \StoreDash\Seo\Controllers\Seo_Settings_Controller
 */
class Seo_Settings_ControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__seo_options'] = array();
		Settings::reset_cache();
	}

	protected function tearDown(): void {
		$GLOBALS['__seo_options'] = array();
		Settings::reset_cache();
	}

	private function controller(): Seo_Settings_Controller {
		return new Seo_Settings_Controller(
			new Plugin_Conflict_Guard(
				static function ( $c ) {
					return 'WPSEO_VERSION' === $c;
				},
				static function () {
					return array();
				}
			)
		);
	}

	private function body( array $settings = array() ): array {
		return array(
			'enabled'  => true,
			'settings' => $settings,
			'hash'     => 'abc',
		);
	}

	public function test_put_stores_options_resets_cache_and_echoes() {
		// Prime the static cache with defaults first.
		$this->assertSame( '|', Settings::get()['title_separator'] );

		$out = $this->controller()->put_settings(
			new Seo_Test_Request(
				$this->body(
					array(
						'title_separator'  => '–',
						'noindex_tags'     => true,
						'noindex_filtered' => false,
						'schema_org'       => array( 'name' => 'Shop' ),
					)
				)
			)
		);

		$this->assertTrue( $out['enabled'] );
		$this->assertSame( 'abc', $out['hash'] );
		$this->assertSame( '–', $out['settings']['title_separator'] );
		$this->assertTrue( $out['settings']['noindex_tags'] );
		$this->assertFalse( $out['settings']['noindex_filtered'] );
		$this->assertSame( '{product} {sep} {store}', $out['settings']['title_template_product'] );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( $out['settings'] ) );

		$this->assertSame( '1', $GLOBALS['__seo_options'][ Settings::ENABLED_OPTION ] );
		$this->assertSame( 'abc', $GLOBALS['__seo_options'][ Seo_Settings_Controller::HASH_OPTION ] );
		$this->assertSame( '–', Settings::get()['title_separator'] );
	}

	public function test_put_disabled_then_get() {
		$this->controller()->put_settings( new Seo_Test_Request( array( 'enabled' => false, 'settings' => array(), 'hash' => 'h2' ) ) );
		$out = $this->controller()->get_settings( new Seo_Test_Request( array() ) );
		$this->assertFalse( $out['enabled'] );
		$this->assertSame( 'h2', $out['hash'] );
		$this->assertSame( Settings::defaults(), $out['settings'] );
	}

	public function test_get_without_push_has_null_hash() {
		$out = $this->controller()->get_settings( new Seo_Test_Request( array() ) );
		$this->assertNull( $out['hash'] );
		$this->assertFalse( $out['enabled'] );
	}

	public function test_invalid_put_returns_400_and_writes_nothing() {
		$out = $this->controller()->put_settings( new Seo_Test_Request( $this->body( array( 'bogus' => 1, 'other' => 2 ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'storedash_seo_unknown_settings', $out->get_error_code() );
		$this->assertStringContainsString( 'bogus, other', $out->get_error_message() );
		$this->assertSame( array( 'status' => 400 ), $out->get_error_data() );
		$this->assertSame( array(), $GLOBALS['__seo_options'] );
	}

	/**
	 * @dataProvider invalid_bodies
	 */
	public function test_validate_rejects( array $body, string $code, string $needle ) {
		$out = Seo_Settings_Controller::validate_payload( $body );
		$this->assertSame( $code, $out['error']['code'] ?? null );
		$this->assertStringContainsString( $needle, $out['error']['message'] );
	}

	public function invalid_bodies(): array {
		$ok = array( 'enabled' => true, 'hash' => 'h' );
		return array(
			'missing enabled'  => array( array( 'settings' => array(), 'hash' => 'h' ), 'storedash_seo_invalid_enabled', 'enabled' ),
			'string enabled'   => array( array( 'enabled' => 'true', 'settings' => array(), 'hash' => 'h' ), 'storedash_seo_invalid_enabled', 'enabled' ),
			'missing hash'     => array( array( 'enabled' => true, 'settings' => array() ), 'storedash_seo_invalid_hash', 'hash' ),
			'empty hash'       => array( array( 'enabled' => true, 'settings' => array(), 'hash' => ' ' ), 'storedash_seo_invalid_hash', 'hash' ),
			'missing settings' => array( $ok, 'storedash_seo_invalid_settings', 'settings must be an object' ),
			'bool as string'   => array( $ok + array( 'settings' => array( 'noindex_tags' => 'yes' ) ), 'storedash_seo_invalid_settings', 'noindex_tags must be a boolean' ),
			'string as bool'   => array( $ok + array( 'settings' => array( 'title_separator' => true ) ), 'storedash_seo_invalid_settings', 'title_separator must be a string' ),
			'too long'         => array( $ok + array( 'settings' => array( 'title_template_page' => str_repeat( 'a', 501 ) ) ), 'storedash_seo_invalid_settings', 'at most 500' ),
			'array as string'  => array( $ok + array( 'settings' => array( 'ai_bots' => 'all' ) ), 'storedash_seo_invalid_settings', 'ai_bots must be an object' ),
			'relative og url'  => array( $ok + array( 'settings' => array( 'default_og_image_url' => '/og.jpg' ) ), 'storedash_seo_invalid_settings', 'default_og_image_url' ),
		);
	}

	public function test_null_values_take_defaults_and_empty_og_url_allowed() {
		$out = Seo_Settings_Controller::validate_payload(
			$this->body(
				array(
					'title_template_category' => null,
					'default_og_image_url'    => '',
					'noindex_search'          => false,
				)
			)
		);
		$this->assertSame( '{category} {sep} {store}', $out['settings']['title_template_category'] );
		$this->assertSame( '', $out['settings']['default_og_image_url'] );
		$this->assertFalse( $out['settings']['noindex_search'] );
	}

	public function test_put_rejects_string_the_sanitiser_would_change() {
		foreach ( array( 'title_separator' => '<', 'title_template_product' => '{product} <b>{store}</b>' ) as $key => $value ) {
			$out = $this->controller()->put_settings( new Seo_Test_Request( $this->body( array( $key => $value ) ) ) );
			$this->assertInstanceOf( WP_Error::class, $out, $key );
			$this->assertSame( 'storedash_seo_invalid_setting', $out->get_error_code() );
			$this->assertStringContainsString( $key, $out->get_error_message() );
			$this->assertSame( array( 'status' => 400 ), $out->get_error_data() );
		}
		$this->assertSame( array(), $GLOBALS['__seo_options'], 'nothing stored' );

		// The guillemet the migrator substitutes is accepted as-is.
		$ok = Seo_Settings_Controller::validate_payload( $this->body( array( 'title_separator' => '‹' ) ) );
		$this->assertSame( '‹', $ok['settings']['title_separator'] );
		// Surrounding whitespace is trimmed, not rejected.
		$ok = Seo_Settings_Controller::validate_payload( $this->body( array( 'title_separator' => ' | ' ) ) );
		$this->assertSame( '|', $ok['settings']['title_separator'] );
	}

	public function test_status_shape() {
		$GLOBALS['__seo_options'][ Seo_Settings_Controller::HASH_OPTION ] = 'abc';
		$out = $this->controller()->get_status( new Seo_Test_Request( array() ) );

		$this->assertSame(
			array( 'enabled', 'conflict', 'version', 'sitemap_url', 'detected_plugins', 'settings_hash', 'wc_version', 'wp_version', 'permalinks_pretty', 'robots_txt_physical' ),
			array_keys( $out )
		);
		$this->assertFalse( $out['enabled'] );
		$this->assertNull( $out['sitemap_url'] );
		$this->assertSame( 'abc', $out['settings_hash'] );
		$this->assertSame(
			array( 'yoast' => true, 'rankmath' => false, 'seopress' => false, 'aioseo' => false, 'seoframework' => false ),
			$out['detected_plugins']
		);
		$this->assertFalse( $out['permalinks_pretty'] );
		$this->assertSame( '6.8', $out['wp_version'] );
		$this->assertIsBool( $out['robots_txt_physical'] );
	}

	public function test_settings_payload_carries_catalog_token_and_put_can_rotate_it() {
		$get   = $this->controller()->get_settings( new Seo_Test_Request( array() ) );
		$token = $get['catalog_token'];
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{32}$/', $token ) );
		$this->assertSame( array( 'enabled', 'settings', 'hash', 'catalog_token' ), array_keys( $get ) );

		$put = $this->controller()->put_settings( new Seo_Test_Request( $this->body() ) );
		$this->assertSame( $token, $put['catalog_token'], 'a normal push keeps the token' );

		$body                         = $this->body();
		$body['rotate_catalog_token'] = 'yes';
		$this->assertSame( $token, $this->controller()->put_settings( new Seo_Test_Request( $body ) )['catalog_token'], 'only literal true rotates' );

		$body['rotate_catalog_token'] = true;
		$rotated                      = $this->controller()->put_settings( new Seo_Test_Request( $body ) )['catalog_token'];
		$this->assertNotSame( $token, $rotated );
		$this->assertSame( 'abc', $GLOBALS['__seo_options'][ Seo_Settings_Controller::HASH_OPTION ], 'rotation is not a setting; hash unchanged' );
	}
}
