<?php
/**
 * Unit tests for the waitlist notification channel (Email / SMS / Both):
 * config sanitising, entry validation, the changed-channel re-signup path,
 * and the `notify_channel` key on the outbound webhook.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Services\Waitlist\Waitlist_Config;
use StoreDash\Services\Waitlist\Waitlist_Handler;

require_once __DIR__ . '/waitlist-stubs.php';
require_once __DIR__ . '/../../inc/services/waitlist/class-waitlist-config.php';
require_once __DIR__ . '/../../inc/services/waitlist/class-waitlist-handler.php';

/**
 * @covers \StoreDash\Services\Waitlist\Waitlist_Config
 * @covers \StoreDash\Services\Waitlist\Waitlist_Handler
 */
class Waitlist_ChannelTest extends TestCase {

	/** @var Waitlist_Test_WPDB */
	private $db;

	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		Optin_Test_WP::reset();
		$GLOBALS['__seo_options']['woodash_store_id']       = 77;
		$GLOBALS['__seo_options']['woodash_webhook_secret'] = 'secret';
		$GLOBALS['__test_products'][10]                    = new Waitlist_Test_Product( 10 );

		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new Waitlist_Test_WPDB();
		$GLOBALS['wpdb']     = $this->db;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		unset( $GLOBALS['__test_products'][10] );
	}

	private function entry( array $overrides = array() ): array {
		return array_merge(
			array(
				'product_id'     => 10,
				'customer_email' => 'shopper@example.com',
				'customer_name'  => 'Shopper',
			),
			$overrides
		);
	}

	/** Decoded `data` object of the last webhook POST. */
	private function last_webhook_data(): array {
		$this->assertNotEmpty( Optin_Test_WP::$posts, 'expected a webhook POST' );
		$post = end( Optin_Test_WP::$posts );

		return json_decode( $post['args']['body'], true )['data'];
	}

	public function test_sanitize_channel_whitelists_and_defaults_to_email(): void {
		$this->assertSame( 'email', Waitlist_Config::sanitize_channel( 'email' ) );
		$this->assertSame( 'sms', Waitlist_Config::sanitize_channel( ' SMS ' ) );
		$this->assertSame( 'both', Waitlist_Config::sanitize_channel( 'both' ) );
		$this->assertSame( 'email', Waitlist_Config::sanitize_channel( 'whatsapp' ) );
		$this->assertSame( 'email', Waitlist_Config::sanitize_channel( '' ) );
		$this->assertSame( 'email', Waitlist_Config::sanitize_channel( null ) );
		$this->assertSame( 'email', Waitlist_Config::sanitize_channel( array( 'sms' ) ) );
	}

	public function test_sms_choice_enabled_defaults_off_and_sanitizes_to_bool(): void {
		$this->assertFalse( Waitlist_Config::defaults()['sms_choice_enabled'] );
		$this->assertSame( array( 'sms_choice_enabled' => true ), Waitlist_Config::sanitize( array( 'sms_choice_enabled' => 1 ) ) );
		$this->assertSame( array( 'sms_choice_enabled' => false ), Waitlist_Config::sanitize( array( 'sms_choice_enabled' => '' ) ) );
		$this->assertArrayNotHasKey( 'sms_choice_enabled', Waitlist_Config::sanitize( array( 'enabled' => true ) ) );
	}

	public function test_sms_without_phone_is_rejected_with_phone_required(): void {
		foreach ( array( 'sms', 'both' ) as $channel ) {
			$result = Waitlist_Handler::create_entry( $this->entry( array( 'notify_channel' => $channel ) ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'phone_required', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
		$this->assertEmpty( $this->db->inserts );
	}

	public function test_implausible_phone_is_rejected_for_sms(): void {
		$result = Waitlist_Handler::create_entry(
			$this->entry( array( 'notify_channel' => 'sms', 'customer_phone' => 'n/a' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'phone_invalid', $result->get_error_code() );
	}

	public function test_both_with_phone_is_stored_and_sent_on_webhook(): void {
		$result = Waitlist_Handler::create_entry(
			$this->entry( array( 'notify_channel' => 'both', 'customer_phone' => '+354 555 1234' ) )
		);

		$this->assertSame( array( 'id' => 901, 'updated' => false ), $result );
		$this->assertSame( 'both', $this->db->inserts[0]['notify_channel'] );
		$this->assertSame( '+354 555 1234', $this->db->inserts[0]['customer_phone'] );

		$data = $this->last_webhook_data();
		$this->assertSame( 'both', $data['notify_channel'] );
		$this->assertSame( '+354 555 1234', $data['customer_phone'] );
	}

	public function test_unknown_channel_falls_back_to_email_without_phone(): void {
		$result = Waitlist_Handler::create_entry( $this->entry( array( 'notify_channel' => 'pigeon' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'email', $this->db->inserts[0]['notify_channel'] );
		$this->assertSame( 'email', $this->last_webhook_data()['notify_channel'] );
	}

	public function test_missing_channel_keeps_legacy_email_behaviour(): void {
		$result = Waitlist_Handler::create_entry( $this->entry() );

		$this->assertIsArray( $result );
		$this->assertSame( 'email', $this->db->inserts[0]['notify_channel'] );
	}

	public function test_resignup_with_same_channel_is_a_duplicate(): void {
		$this->db->pending_row = array(
			'id'             => 5,
			'store_id'       => 77,
			'customer_name'  => 'Stored Name',
			'customer_phone' => null,
			'notify_channel' => 'email',
			'language'       => 'is',
		);

		$result = Waitlist_Handler::create_entry( $this->entry() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_waitlist_duplicate', $result->get_error_code() );
		$this->assertEmpty( $this->db->updates );
		$this->assertEmpty( Optin_Test_WP::$posts );
	}

	public function test_resignup_with_new_channel_updates_pending_row_and_resends(): void {
		$this->db->pending_row = array(
			'id'             => 5,
			'store_id'       => 77,
			'customer_name'  => 'Stored Name',
			'customer_phone' => null,
			'notify_channel' => 'email',
			'language'       => 'is',
		);

		$result = Waitlist_Handler::create_entry(
			$this->entry(
				array(
					'notify_channel' => 'sms',
					'customer_phone' => '5551234',
					'customer_name'  => '',
				)
			)
		);

		$this->assertSame( array( 'id' => 5, 'updated' => true ), $result );
		$this->assertEmpty( $this->db->inserts );
		$this->assertSame( 'sms', $this->db->updates[0]['data']['notify_channel'] );
		$this->assertSame( '5551234', $this->db->updates[0]['data']['customer_phone'] );
		$this->assertSame( array( 'id' => 5, 'status' => 'pending' ), $this->db->updates[0]['where'] );

		// The receiver upserts name/language too, so they must come from the row.
		$data = $this->last_webhook_data();
		$this->assertSame( 5, $data['id'] );
		$this->assertSame( 'sms', $data['notify_channel'] );
		$this->assertSame( 'Stored Name', $data['customer_name'] );
		$this->assertSame( 'is', $data['language'] );
	}

	public function test_resignup_without_phone_never_wipes_stored_phone(): void {
		$this->db->pending_row = array(
			'id'             => 5,
			'store_id'       => 77,
			'customer_name'  => 'Stored Name',
			'customer_phone' => '5551234',
			'notify_channel' => 'sms',
			'language'       => 'is',
		);

		$result = Waitlist_Handler::create_entry( $this->entry( array( 'notify_channel' => 'email' ) ) );

		$this->assertSame( array( 'id' => 5, 'updated' => true ), $result );
		$this->assertSame( 'email', $this->db->updates[0]['data']['notify_channel'] );
		$this->assertSame( '5551234', $this->db->updates[0]['data']['customer_phone'] );
	}

	public function test_default_channel_sanitizes_and_only_applies_with_choice_on(): void {
		$this->assertSame( 'email', Waitlist_Config::defaults()['default_channel'] );
		$this->assertSame( array( 'default_channel' => 'sms' ), Waitlist_Config::sanitize( array( 'default_channel' => 'SMS' ) ) );
		$this->assertSame( array( 'default_channel' => 'email' ), Waitlist_Config::sanitize( array( 'default_channel' => 'fax' ) ) );

		$this->assertSame(
			'email',
			Waitlist_Config::effective_default_channel( array( 'sms_choice_enabled' => false, 'default_channel' => 'sms' ) )
		);
		$this->assertSame(
			'both',
			Waitlist_Config::effective_default_channel( array( 'sms_choice_enabled' => true, 'default_channel' => 'both' ) )
		);
	}

	public function test_sms_only_without_email_is_accepted_and_sends_null_email(): void {
		$result = Waitlist_Handler::create_entry(
			$this->entry( array( 'customer_email' => '', 'notify_channel' => 'sms', 'customer_phone' => '555 1234' ) )
		);

		$this->assertSame( array( 'id' => 901, 'updated' => false ), $result );
		$this->assertNull( $this->db->inserts[0]['customer_email'] );
		$this->assertSame( 'sms', $this->db->inserts[0]['notify_channel'] );

		$data = $this->last_webhook_data();
		$this->assertArrayHasKey( 'customer_email', $data );
		$this->assertNull( $data['customer_email'] );
	}

	public function test_email_or_both_without_email_is_rejected_with_email_required(): void {
		foreach ( array( 'email', 'both', '' ) as $channel ) {
			$result = Waitlist_Handler::create_entry(
				$this->entry( array( 'customer_email' => '', 'notify_channel' => $channel, 'customer_phone' => '5551234' ) )
			);

			$this->assertInstanceOf( WP_Error::class, $result, "channel '$channel'" );
			$this->assertSame( 'email_required', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
		$this->assertEmpty( $this->db->inserts );
	}

	public function test_sms_only_without_email_still_needs_a_phone(): void {
		$result = Waitlist_Handler::create_entry( $this->entry( array( 'customer_email' => '', 'notify_channel' => 'sms' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'phone_required', $result->get_error_code() );
	}

	public function test_invalid_email_is_rejected_even_for_sms(): void {
		$result = Waitlist_Handler::create_entry(
			$this->entry( array( 'customer_email' => 'not-an-email', 'notify_channel' => 'sms', 'customer_phone' => '5551234' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_waitlist_invalid_email', $result->get_error_code() );
	}

	public function test_email_less_signup_dedupes_on_phone_digits(): void {
		$this->db->pending_row = array(
			'id'             => 8,
			'store_id'       => 77,
			'customer_name'  => 'Guest',
			'customer_phone' => '5551234',
			'notify_channel' => 'sms',
			'language'       => 'is',
		);

		$result = Waitlist_Handler::create_entry(
			$this->entry( array( 'customer_email' => '', 'notify_channel' => 'sms', 'customer_phone' => '5551234' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'storedash_waitlist_duplicate', $result->get_error_code() );
		// Looked up by phone digits among email-less rows, not by email.
		$this->assertStringContainsString( 'customer_email IS NULL', $this->db->last_query );
		$this->assertSame( array( 10, 0, '5551234' ), $this->db->last_args );
		$this->assertEmpty( $this->db->inserts );
	}

	public function test_email_less_lookup_normalises_phone_formatting(): void {
		Waitlist_Handler::create_entry(
			$this->entry( array( 'customer_email' => '', 'notify_channel' => 'sms', 'customer_phone' => '+354 (555) 12-34' ) )
		);

		$this->assertSame( array( 10, 0, '3545551234' ), $this->db->last_args );
		$this->assertCount( 1, $this->db->inserts );
	}

	public function test_signup_with_email_still_dedupes_on_email(): void {
		Waitlist_Handler::create_entry(
			$this->entry( array( 'notify_channel' => 'sms', 'customer_phone' => '5551234' ) )
		);

		$this->assertStringNotContainsString( 'customer_email IS NULL', $this->db->last_query );
		$this->assertSame( array( 10, 0, 'shopper@example.com' ), $this->db->last_args );
	}
}
