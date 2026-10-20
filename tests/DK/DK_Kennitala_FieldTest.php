<?php
/**
 * Unit tests for StoreDash\Services\DK\DK_Kennitala_Field.
 *
 * Hook registration + the classic/block callbacks that decide what is saved.
 * Rendering and WooCommerce's own required-field validation are WordPress
 * glue verified on a live store.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Services\DK\DK_Config;
use StoreDash\Services\DK\DK_Kennitala;
use StoreDash\Services\DK\DK_Kennitala_Field;

require_once __DIR__ . '/dk-stubs.php';

/**
 * @covers \StoreDash\Services\DK\DK_Kennitala_Field
 */
class DK_Kennitala_FieldTest extends TestCase {

	const VALID   = '0101302989';
	const INVALID = '0101302999';

	protected function setUp(): void {
		DK_Test_WP::reset();
		$ref = new ReflectionProperty( DK_Config::class, 'cache' );
		$ref->setValue( null, null );
		// The file self-instantiates once; load it lazily so the first load
		// happens with a known (off) config and registers nothing.
		if ( ! class_exists( DK_Kennitala_Field::class ) ) {
			require_once __DIR__ . '/../../inc/services/dk/class-dk-kennitala-field.php';
			$this->assertSame( array(), DK_Test_WP::$hooks, 'mode off → no hooks' );
			DK_Test_WP::reset();
		}
	}

	private function configure( string $mode, string $position = 'top' ): DK_Kennitala_Field {
		DK_Config::update( array( 'kennitala_mode' => $mode, 'kennitala_position' => $position ) );
		DK_Test_WP::$hooks = array();
		return new DK_Kennitala_Field();
	}

	private function billing_fields(): array {
		return array(
			'billing' => array(
				'billing_first_name' => array( 'priority' => 10 ),
				'billing_country'    => array( 'priority' => 40 ),
				'billing_email'      => array( 'priority' => 110 ),
			),
		);
	}

	// ── Registration ────────────────────────────────────────────────────

	public function test_off_registers_nothing(): void {
		$this->configure( 'off' );
		$this->assertSame( array(), DK_Test_WP::$hooks );
	}

	public function test_optional_registers_classic_and_block_hooks(): void {
		// In the full suite another test file may have stubbed add_filter() as
		// a no-op first; hook recording then is impossible, not wrong.
		add_filter( 'dk_test_probe', 'dk_test_probe' );
		if ( ! isset( DK_Test_WP::$hooks['dk_test_probe'] ) ) {
			$this->markTestSkipped( 'add_filter() stubbed elsewhere without recording' );
		}
		$this->configure( 'optional' );
		$hooks = array_keys( DK_Test_WP::$hooks );
		foreach ( array(
			'woocommerce_checkout_fields',
			'woocommerce_after_checkout_validation',
			'woocommerce_checkout_create_order',
			'woocommerce_checkout_update_customer',
			'woocommerce_checkout_get_value',
			'wp_footer',
			'woocommerce_init',
			'woocommerce_blocks_validate_location_address_fields',
			'woocommerce_store_api_checkout_order_processed',
			'woocommerce_admin_order_data_after_billing_address',
		) as $hook ) {
			$this->assertContains( $hook, $hooks );
		}
		$this->assertSame( 30, DK_Test_WP::$hooks['woocommerce_checkout_fields'][0]['priority'], 'after DKSync add(10)+move(20)' );
	}

	// ── Classic: field definition ───────────────────────────────────────

	public function test_classic_field_added_with_position_priority(): void {
		$field  = $this->configure( 'optional', 'after_phone' );
		$fields = $field->add_classic_field( $this->billing_fields() );
		$def    = $fields['billing']['billing_kennitala'];
		$this->assertSame( 105, $def['priority'] );
		$this->assertSame( 'text', $def['type'] );
		$this->assertFalse( $def['required'] );
		$this->assertSame( 'numeric', $def['custom_attributes']['inputmode'] );
	}

	public function test_classic_required_only_for_icelandic_posted_country(): void {
		$field = $this->configure( 'required' );

		$_POST['billing_country'] = 'IS';
		$this->assertTrue( $field->add_classic_field( $this->billing_fields() )['billing']['billing_kennitala']['required'] );

		$_POST['billing_country'] = 'DK';
		$this->assertFalse( $field->add_classic_field( $this->billing_fields() )['billing']['billing_kennitala']['required'] );

		unset( $_POST['billing_country'] );
		$this->assertFalse( $field->add_classic_field( $this->billing_fields() )['billing']['billing_kennitala']['required'], 'unknown country → not required' );
	}

	public function test_classic_field_not_added_when_dksync_has_one(): void {
		$field  = $this->configure( 'required' );
		$fields = $this->billing_fields();
		$fields['billing']['billing_dk_ssn'] = array( 'priority' => 5 );
		$out = $field->add_classic_field( $fields );
		$this->assertArrayNotHasKey( 'billing_kennitala', $out['billing'] );
		$this->assertSame( $fields, $out );
	}

	public function test_classic_field_tolerates_non_array_input(): void {
		$field = $this->configure( 'optional' );
		$this->assertNull( $field->add_classic_field( null ) );
	}

	// ── Classic: validation ─────────────────────────────────────────────

	public function test_classic_validation_rejects_bad_checksum_for_iceland(): void {
		$field  = $this->configure( 'optional' );
		$errors = new DK_Test_Errors();
		$field->validate_classic( array( 'billing_country' => 'IS', 'billing_kennitala' => self::INVALID ), $errors );
		$this->assertSame( array( 'storedash_kennitala' ), $errors->get_error_codes() );
	}

	public function test_classic_validation_accepts_valid_and_empty(): void {
		$field  = $this->configure( 'required' );
		$errors = new DK_Test_Errors();
		$field->validate_classic( array( 'billing_country' => 'IS', 'billing_kennitala' => '010130-2989' ), $errors );
		$field->validate_classic( array( 'billing_country' => 'IS', 'billing_kennitala' => '' ), $errors );
		$field->validate_classic( array( 'billing_country' => 'IS' ), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'required is WooCommerce\'s check, not ours' );
	}

	public function test_classic_validation_skips_other_countries(): void {
		$field  = $this->configure( 'required' );
		$errors = new DK_Test_Errors();
		$field->validate_classic( array( 'billing_country' => 'DK', 'billing_kennitala' => self::INVALID ), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	// ── Classic: persistence ────────────────────────────────────────────

	public function test_classic_saves_normalised_value_on_order(): void {
		$field = $this->configure( 'optional' );
		$order = new WC_Order();
		$field->save_to_order_classic( $order, array( 'billing_country' => 'IS', 'billing_kennitala' => '010130-2989' ) );
		$this->assertSame( self::VALID, $order->meta['billing_kennitala'] );
		$this->assertSame( 0, $order->saves, 'create_order hook: WooCommerce saves afterwards' );
	}

	public function test_classic_drops_invalid_or_foreign_values(): void {
		$field = $this->configure( 'optional' );

		$order = new WC_Order();
		$field->save_to_order_classic( $order, array( 'billing_country' => 'IS', 'billing_kennitala' => self::INVALID ) );
		$this->assertArrayNotHasKey( 'billing_kennitala', $order->meta );

		$order = new WC_Order();
		$field->save_to_order_classic( $order, array( 'billing_country' => 'NO', 'billing_kennitala' => self::VALID ) );
		$this->assertArrayNotHasKey( 'billing_kennitala', $order->meta );

		$order = new WC_Order();
		$field->save_to_order_classic( $order, array( 'billing_country' => 'IS' ) );
		$this->assertArrayNotHasKey( 'billing_kennitala', $order->meta );
	}

	public function test_classic_saves_to_customer_too(): void {
		$field    = $this->configure( 'optional' );
		$customer = new WC_Order(); // Same meta surface as WC_Customer for this purpose.
		$field->save_to_customer_classic( $customer, array( 'billing_country' => 'IS', 'billing_kennitala' => self::VALID ) );
		$this->assertSame( self::VALID, $customer->meta['billing_kennitala'] );
	}

	public function test_classic_prefill_uses_customer_meta_formatted(): void {
		$field = $this->configure( 'optional' );
		$this->assertSame( 'x', $field->prefill_classic( 'x', 'billing_kennitala' ), 'existing value wins' );
		$this->assertNull( $field->prefill_classic( null, 'billing_kennitala' ), 'guest → untouched' );

		DK_Test_WP::$user_id   = 7;
		DK_Test_WP::$user_meta = array( 'billing_kennitala' => self::VALID );
		$this->assertSame( '010130-2989', $field->prefill_classic( null, 'billing_kennitala' ) );
		$this->assertSame( 'other', $field->prefill_classic( 'other', 'billing_email' ), 'other fields untouched' );
	}

	// ── Block checkout ──────────────────────────────────────────────────

	public function test_block_required_check_only_for_icelandic_billing(): void {
		$field = $this->configure( 'required' );

		$errors = new DK_Test_Errors();
		$field->validate_block_address( $errors, array( 'country' => 'IS', 'storedash/kennitala' => '' ), 'billing' );
		$this->assertSame( array( 'storedash_kennitala_required' ), $errors->get_error_codes() );

		$errors = new DK_Test_Errors();
		$field->validate_block_address( $errors, array( 'country' => 'IS', 'storedash/kennitala' => '' ), 'shipping' );
		$field->validate_block_address( $errors, array( 'country' => 'SE', 'storedash/kennitala' => '' ), 'billing' );
		$field->validate_block_address( $errors, array( 'country' => 'IS', 'storedash/kennitala' => self::VALID ), 'billing' );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_block_required_check_is_silent_in_optional_mode(): void {
		$field  = $this->configure( 'optional' );
		$errors = new DK_Test_Errors();
		$field->validate_block_address( $errors, array( 'country' => 'IS', 'storedash/kennitala' => '' ), 'billing' );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_block_bridge_copies_to_canonical_meta(): void {
		$field = $this->configure( 'optional' );
		$order = new WC_Order();
		$order->meta['_wc_billing/storedash/kennitala'] = '010130-2989';
		$field->bridge_block_to_order_meta( $order );
		$this->assertSame( self::VALID, $order->meta['billing_kennitala'] );
		$this->assertSame( 1, $order->saves );
	}

	public function test_block_bridge_skips_invalid_foreign_or_already_set(): void {
		$field = $this->configure( 'optional' );

		$order = new WC_Order();
		$order->meta['_wc_billing/storedash/kennitala'] = self::INVALID;
		$field->bridge_block_to_order_meta( $order );
		$this->assertArrayNotHasKey( 'billing_kennitala', $order->meta );

		$order                  = new WC_Order();
		$order->billing_country = 'DE';
		$order->meta['_wc_billing/storedash/kennitala'] = self::VALID;
		$field->bridge_block_to_order_meta( $order );
		$this->assertArrayNotHasKey( 'billing_kennitala', $order->meta );

		$order = new WC_Order();
		$order->meta['billing_kennitala'] = '5302697609';
		$order->meta['_wc_billing/storedash/kennitala'] = self::VALID;
		$field->bridge_block_to_order_meta( $order );
		$this->assertSame( '5302697609', $order->meta['billing_kennitala'], 'classic value kept' );
		$this->assertSame( 0, $order->saves );
	}

	public function test_is_iceland(): void {
		$this->assertTrue( DK_Kennitala_Field::is_iceland( 'is' ) );
		$this->assertTrue( DK_Kennitala_Field::is_iceland( ' IS ' ) );
		$this->assertFalse( DK_Kennitala_Field::is_iceland( '' ) );
		$this->assertFalse( DK_Kennitala_Field::is_iceland( 'ISL' ) );
	}
}
