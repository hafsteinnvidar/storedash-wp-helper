<?php
/**
 * Unit tests for StoreDash\Services\DK\DK_Config.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Services\DK\DK_Config;

require_once __DIR__ . '/dk-stubs.php';

/**
 * @covers \StoreDash\Services\DK\DK_Config
 */
class DK_ConfigTest extends TestCase {

	protected function setUp(): void {
		DK_Test_WP::reset();
		// Drop the per-request cache between tests.
		$ref = new ReflectionProperty( DK_Config::class, 'cache' );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$ref->setValue( null, null );
	}

	public function test_defaults_keep_the_field_off(): void {
		$config = DK_Config::get();
		$this->assertSame( 'off', $config['kennitala_mode'] );
		$this->assertSame( 'top', $config['kennitala_position'] );
		$this->assertFalse( DK_Config::kennitala_enabled() );
		$this->assertFalse( DK_Config::kennitala_required() );
		$this->assertSame( 5, DK_Config::kennitala_priority() );
	}

	public function test_sanitize_whitelists_keys_and_values(): void {
		$clean = DK_Config::sanitize(
			array(
				'kennitala_mode'     => 'Required',
				'kennitala_position' => 'after_phone',
				'api_key'            => 'never',
				'auto_invoice'       => true,
			)
		);
		$this->assertSame(
			array(
				'kennitala_mode'     => 'required',
				'kennitala_position' => 'after_phone',
			),
			$clean
		);
	}

	public function test_sanitize_falls_back_on_unknown_values(): void {
		$clean = DK_Config::sanitize(
			array(
				'kennitala_mode'     => 'always',
				'kennitala_position' => 'sidebar',
			)
		);
		$this->assertSame( 'off', $clean['kennitala_mode'] );
		$this->assertSame( 'top', $clean['kennitala_position'] );
	}

	public function test_sanitize_ignores_absent_keys(): void {
		$this->assertSame( array(), DK_Config::sanitize( array( 'foo' => 'bar' ) ) );
		$this->assertSame( array( 'kennitala_mode' => 'optional' ), DK_Config::sanitize( array( 'kennitala_mode' => 'optional' ) ) );
	}

	public function test_update_merges_persists_and_hashes(): void {
		$first = DK_Config::update( array( 'kennitala_mode' => 'optional' ) );
		$this->assertSame( 'optional', $first['kennitala_mode'] );
		$this->assertSame( 'top', $first['kennitala_position'] );
		$this->assertSame( '2026-10-19 12:00:00', $first['updated_at'] );
		$this->assertSame( 32, strlen( $first['config_hash'] ) );
		$this->assertSame( $first, $GLOBALS['__seo_options'][ DK_Config::OPTION ] );

		$second = DK_Config::update( array( 'kennitala_position' => 'bottom' ) );
		$this->assertSame( 'optional', $second['kennitala_mode'], 'untouched key survives' );
		$this->assertSame( 'bottom', $second['kennitala_position'] );
		$this->assertNotSame( $first['config_hash'], $second['config_hash'] );

		$this->assertTrue( DK_Config::kennitala_enabled() );
		$this->assertFalse( DK_Config::kennitala_required() );
		$this->assertSame( 120, DK_Config::kennitala_priority() );
	}

	public function test_hash_is_order_independent(): void {
		$a = DK_Config::hash( array( 'kennitala_mode' => 'required', 'kennitala_position' => 'top' ) );
		$b = DK_Config::hash( array( 'kennitala_position' => 'top', 'kennitala_mode' => 'required' ) );
		$this->assertSame( $a, $b );
	}

	public function test_positions_map_between_woocommerce_billing_priorities(): void {
		$this->assertSame(
			array(
				'top'           => 5,
				'after_name'    => 25,
				'after_address' => 95,
				'after_phone'   => 105,
				'bottom'        => 120,
			),
			DK_Config::POSITIONS
		);
	}

	public function test_corrupt_option_falls_back_to_defaults(): void {
		$GLOBALS['__seo_options'][ DK_Config::OPTION ] = 'garbage';
		$this->assertSame( 'off', DK_Config::get()['kennitala_mode'] );
	}
}
