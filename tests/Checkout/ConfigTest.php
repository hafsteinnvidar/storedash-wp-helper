<?php
/**
 * Checkout Config: defaults, sanitize whitelist, partial update, hash.
 *
 * @package StoreDash\Tests\Checkout
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Checkout\Config;

require_once __DIR__ . '/checkout-stubs.php';

/**
 * @covers \StoreDash\Checkout\Config
 */
class ConfigTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__seo_options'] = array();
		Config::flush_cache();
	}

	public function test_defaults_keep_every_feature_off(): void {
		$config = Config::get();
		$this->assertFalse( $config['funnel']['enabled'] );
		$this->assertFalse( $config['free_shipping_bar']['enabled'] );
		$this->assertFalse( $config['delivery_estimate']['enabled'] );
		$this->assertSame( '14:00', $config['delivery_estimate']['cutoff_time'] );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), $config['delivery_estimate']['ship_days'] );
		$this->assertSame( 'woo', $config['free_shipping_bar']['include_tax'] );
		$this->assertSame( '', $config['config_hash'] );
		$this->assertFalse( Config::is_feature_active( 'funnel' ) );
		$this->assertFalse( Config::is_feature_active( 'free_shipping_bar' ) );
		$this->assertFalse( Config::is_feature_active( 'delivery_estimate' ) );
		$this->assertFalse( Config::is_feature_active( 'checkout_bump' ) );
	}

	public function test_sanitize_whitelists_keys_and_coerces_types(): void {
		$clean = Config::sanitize(
			array(
				'funnel'            => array(
					'enabled' => 'true',
					'extra'   => 1,
				),
				'free_shipping_bar' => array(
					'enabled'            => '0',
					'threshold'          => '4990.5',
					'threshold_mode'     => 'manual',
					'show_on'            => array( 'checkout', 'product', 'cart' ),
					'include_tax'        => 'incl',
					'ignore_discounts'   => 1,
					'hide_when_unlocked' => 'false',
					'copy'               => array(
						'remaining' => ' Spend {amount} more ',
						'bogus'     => 'x',
					),
					'tokens'             => array(
						'bg'     => '#fff; background:url(x)',
						'radius' => '8px',
						'evil'   => 'red',
					),
				),
				'delivery_estimate' => array(
					'enabled'     => true,
					'cutoff_time' => '09:30',
					'ship_days'   => array( 5, '1', 1, 9, 0 ),
					'default'     => array(
						'min_days' => '2',
						'max_days' => 4,
					),
					'rules'       => array(
						array(
							'rate_id'  => 'flat_rate:3',
							'min_days' => 1,
							'max_days' => 2,
							'kind'     => 'delivery',
						),
						array(
							'rate_id' => 'local_pickup',
							'kind'    => 'hide',
						),
						array(
							'rate_id'  => 'Bad Rate',
							'min_days' => 1,
							'max_days' => 2,
						),
						array(
							'rate_id'  => 'flat_rate:4',
							'min_days' => 5,
							'max_days' => 2,
						),
					),
					'holidays'    => array( '2026-12-25', '2026-02-30', 'nope', '2026-12-25' ),
					'copy'        => array( 'single' => 'Arrives {date}' ),
					'date_format' => 'long',
				),
				'checkout_bump'     => array( 'enabled' => true ),
				'config_hash'       => 'spoof',
			)
		);

		$this->assertSame( array( 'enabled' => true ), $clean['funnel'] );
		$this->assertArrayNotHasKey( 'checkout_bump', $clean );
		$this->assertArrayNotHasKey( 'config_hash', $clean );

		$bar = $clean['free_shipping_bar'];
		$this->assertFalse( $bar['enabled'] );
		$this->assertSame( 4990.5, $bar['threshold'] );
		$this->assertSame( array( 'cart', 'checkout' ), $bar['show_on'] );
		$this->assertSame( 'incl', $bar['include_tax'] );
		$this->assertTrue( $bar['ignore_discounts'] );
		$this->assertFalse( $bar['hide_when_unlocked'] );
		$this->assertSame( array( 'remaining' => 'Spend {amount} more' ), $bar['copy'] );
		$this->assertSame(
			array(
				'bg'     => '#fff backgroundurl(x)',
				'radius' => '8px',
			),
			$bar['tokens']
		);

		$de = $clean['delivery_estimate'];
		$this->assertTrue( $de['enabled'] );
		$this->assertSame( '09:30', $de['cutoff_time'] );
		$this->assertSame( array( 1, 5 ), $de['ship_days'] );
		$this->assertSame(
			array(
				'min_days' => 2,
				'max_days' => 4,
			),
			$de['default']
		);
		$this->assertSame(
			array(
				array(
					'rate_id'  => 'flat_rate:3',
					'min_days' => 1,
					'max_days' => 2,
					'kind'     => 'delivery',
				),
				array(
					'rate_id'  => 'local_pickup',
					'min_days' => 0,
					'max_days' => 0,
					'kind'     => 'hide',
				),
			),
			$de['rules']
		);
		$this->assertSame( array( '2026-12-25' ), $de['holidays'] );
		$this->assertSame( array( 'single' => 'Arrives {date}' ), $de['copy'] );
		$this->assertSame( 'long', $de['date_format'] );
	}

	public function test_sanitize_drops_bad_values_instead_of_resetting(): void {
		$clean = Config::sanitize(
			array(
				'free_shipping_bar' => array(
					'threshold'      => -5,
					'threshold_mode' => 'auto',
					'include_tax'    => 'maybe',
				),
				'delivery_estimate' => array(
					'cutoff_time' => '25:00',
					'default'     => array(
						'min_days' => 1,
						'max_days' => 99,
					),
					'date_format' => 'iso',
				),
			)
		);
		$this->assertSame( array(), $clean );
	}

	public function test_sanitize_caps_rules_and_holidays_and_copy_length(): void {
		$rules    = array();
		$holidays = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$rules[] = array(
				'rate_id'  => 'flat_rate:' . $i,
				'min_days' => 1,
				'max_days' => 1,
			);
		}
		for ( $i = 0; $i < 120; $i++ ) {
			$holidays[] = sprintf( '2027-%02d-%02d', 1 + intdiv( $i, 28 ), 1 + ( $i % 28 ) );
		}
		$clean = Config::sanitize(
			array(
				'delivery_estimate' => array(
					'rules'    => $rules,
					'holidays' => $holidays,
					'copy'     => array( 'range' => str_repeat( 'a', 250 ) ),
				),
			)
		);
		$this->assertCount( Config::MAX_RULES, $clean['delivery_estimate']['rules'] );
		$this->assertCount( Config::MAX_HOLIDAYS, $clean['delivery_estimate']['holidays'] );
		$this->assertSame( Config::MAX_COPY_LEN, strlen( $clean['delivery_estimate']['copy']['range'] ) );
	}

	public function test_partial_update_keeps_other_groups_and_nested_keys(): void {
		Config::update(
			Config::sanitize(
				array(
					'free_shipping_bar' => array(
						'enabled'   => true,
						'threshold' => 5000,
						'copy'      => array(
							'remaining' => 'R',
							'unlocked'  => 'U',
						),
					),
				)
			)
		);
		$after = Config::update( Config::sanitize( array( 'funnel' => array( 'enabled' => true ) ) ) );

		$this->assertTrue( $after['funnel']['enabled'] );
		$this->assertTrue( $after['free_shipping_bar']['enabled'] );
		$this->assertSame( 5000.0, $after['free_shipping_bar']['threshold'] );
		$this->assertTrue( Config::is_feature_active( 'funnel' ) );
		$this->assertTrue( Config::is_feature_active( 'free_shipping_bar' ) );
		$this->assertFalse( Config::is_feature_active( 'delivery_estimate' ) );

		// Nested copy merges key-by-key.
		$after = Config::update( Config::sanitize( array( 'free_shipping_bar' => array( 'copy' => array( 'unlocked' => 'U2' ) ) ) ) );
		$this->assertSame(
			array(
				'remaining' => 'R',
				'unlocked'  => 'U2',
			),
			$after['free_shipping_bar']['copy']
		);

		// Persisted + autoloaded, survives a cache flush.
		$this->assertTrue( $GLOBALS['__seo_option_autoload'][ Config::OPTION ] );
		Config::flush_cache();
		$this->assertSame( 'U2', Config::get()['free_shipping_bar']['copy']['unlocked'] );
	}

	public function test_hash_changes_on_content_and_ignores_meta(): void {
		$first = Config::update( Config::sanitize( array( 'funnel' => array( 'enabled' => true ) ) ) );
		$this->assertSame( 32, strlen( $first['config_hash'] ) );
		$this->assertNotSame( '', $first['updated_at'] );

		// Same content again → same hash (updated_at and the old hash are excluded).
		$same = Config::update( Config::sanitize( array( 'funnel' => array( 'enabled' => true ) ) ) );
		$this->assertSame( $first['config_hash'], $same['config_hash'] );

		// A spoofed hash/updated_at in the stored option does not change the computed hash.
		$GLOBALS['__seo_options'][ Config::OPTION ]['config_hash'] = 'tampered';
		$GLOBALS['__seo_options'][ Config::OPTION ]['updated_at']  = '1999-01-01 00:00:00';
		Config::flush_cache();
		$again = Config::update( array() );
		$this->assertSame( $first['config_hash'], $again['config_hash'] );

		$changed = Config::update( Config::sanitize( array( 'funnel' => array( 'enabled' => false ) ) ) );
		$this->assertNotSame( $first['config_hash'], $changed['config_hash'] );
	}
}
