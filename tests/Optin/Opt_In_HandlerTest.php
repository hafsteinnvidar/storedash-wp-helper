<?php
/**
 * Unit tests for the checkout SMS consent path in Opt_In_Handler (+ the block
 * checkout bridge), and a pin that the e-mail path's webhook is unchanged.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Services\Optin\Blocks_Optin_Field;
use StoreDash\Services\Optin\Blocks_Sms_Optin_Field;
use StoreDash\Services\Optin\Opt_In_Handler;

require_once __DIR__ . '/optin-stubs.php';

/**
 * @covers \StoreDash\Services\Optin\Opt_In_Handler
 * @covers \StoreDash\Services\Optin\Blocks_Sms_Optin_Field
 */
class Opt_In_HandlerTest extends TestCase {

	/** @var Opt_In_Handler */
	private $handler;

	protected function setUp(): void {
		Optin_Test_WP::reset();
		$GLOBALS['__seo_options']['woodash_store_id']       = 77;
		$GLOBALS['__seo_options']['woodash_webhook_secret'] = 'secret';
		// Constructor registers hooks via the recorded add_action stub; harmless.
		$this->handler = new Opt_In_Handler();
		$this->reset_processed( 'processed_sms_order_ids' );
		$this->reset_processed( 'processed_order_ids' );
	}

	private function reset_processed( string $prop ): void {
		$ref = new ReflectionProperty( Opt_In_Handler::class, $prop );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$ref->setValue( null, array() );
	}

	private function enable_sms(): void {
		$GLOBALS['__seo_options'][ Opt_In_Handler::SMS_ENABLED_OPTION ] = '1';
	}

	private function order( int $id = 501 ): WC_Order {
		$order     = new WC_Order();
		$order->id = $id;
		return $order;
	}

	private function last_payload(): array {
		$this->assertCount( 1, Optin_Test_WP::$posts, 'exactly one webhook POST expected' );
		$post = Optin_Test_WP::$posts[0];
		$body = json_decode( $post['args']['body'], true );
		$this->assertSame( hash_hmac( 'sha256', $post['args']['body'], 'secret' ), $post['args']['headers']['X-WooDash-Signature'] );
		return $body;
	}

	// ── Classic checkout rendering ──────────────────────────────────────

	public function test_classic_checkbox_hidden_when_disabled(): void {
		ob_start();
		$this->handler->display_checkout_sms_optin();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_classic_checkbox_renders_default_label_when_enabled(): void {
		$this->enable_sms();
		ob_start();
		$this->handler->display_checkout_sms_optin();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="woodash_sms_optin"', $html );
		$this->assertStringContainsString( 'Send me offers and news by SMS', $html );
		$this->assertStringNotContainsString( 'checked', $html, 'consent is never pre-checked' );
	}

	public function test_classic_checkbox_uses_custom_label(): void {
		$this->enable_sms();
		$GLOBALS['__seo_options']['woodash_checkout_sms_optin_label'] = 'Texti <b>frá</b> kaupmanni';
		ob_start();
		$this->handler->display_checkout_sms_optin();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Texti &lt;b&gt;frá&lt;/b&gt; kaupmanni', $html );
	}

	// ── Order placed: meta + webhook ────────────────────────────────────

	public function test_disabled_feature_writes_nothing(): void {
		$_POST['woodash_sms_optin'] = '1';
		$order                      = $this->order();
		$this->handler->handle_order_sms_optin( $order );

		$this->assertSame( array(), $order->meta );
		$this->assertSame( array(), Optin_Test_WP::$posts );
	}

	public function test_checked_box_persists_meta_label_and_sends_sms_event(): void {
		$this->enable_sms();
		$_POST['woodash_sms_optin'] = '1';
		$order                      = $this->order();
		$order->customer_id         = 12;
		$order->billing_email       = 'Buyer@Example.com';

		$this->handler->handle_order_sms_optin( $order );

		$this->assertSame( 1, $order->meta['_woodash_sms_optin'] );
		$this->assertSame( 'Send me offers and news by SMS', $order->meta['_woodash_sms_optin_label'] );
		$this->assertSame( 1, $order->meta['_woodash_sms_optin_webhook_sent'] );
		$this->assertGreaterThan( 0, $order->saves );

		$payload = $this->last_payload();
		$this->assertSame( 'subscription.sms_optin', $payload['event'] );
		$this->assertSame( 77, $payload['store_id'] );
		$this->assertSame( 'https://shop.test', $payload['store_url'] );
		$this->assertSame( 'uuid-test', $payload['webhook_id'] );
		$this->assertSame(
			array(
				'email'        => 'buyer@example.com',
				'customer_id'  => 12,
				'source'       => 'checkout_optin',
				'initiator'    => 'customer',
				'phone'        => '5551234',
				'country'      => 'IS',
				'first_name'   => 'Anna',
				'last_name'    => 'Example',
				'consent_text' => 'Send me offers and news by SMS',
			),
			$payload['data']
		);
	}

	public function test_consent_text_is_the_label_shown_at_checkout(): void {
		$this->enable_sms();
		$GLOBALS['__seo_options']['woodash_checkout_sms_optin_label'] = 'Senda mér SMS';
		$_POST['woodash_sms_optin']                                   = '1';
		$order                                                        = $this->order();

		$this->handler->handle_order_sms_optin( $order );

		$this->assertSame( 'Senda mér SMS', $order->meta['_woodash_sms_optin_label'] );
		$this->assertSame( 'Senda mér SMS', $this->last_payload()['data']['consent_text'] );
	}

	public function test_guest_sends_null_customer_id(): void {
		$this->enable_sms();
		$_POST['woodash_sms_optin'] = '1';
		$this->handler->handle_order_sms_optin( $this->order() );

		$this->assertNull( $this->last_payload()['data']['customer_id'] );
	}

	public function test_unchecked_box_persists_zero_and_sends_nothing(): void {
		$this->enable_sms();
		$_POST['woodash_sms_optin'] = '';
		$order                      = $this->order();

		$this->handler->handle_order_sms_optin( $order );

		$this->assertSame( 0, $order->meta['_woodash_sms_optin'] );
		$this->assertArrayNotHasKey( '_woodash_sms_optin_label', $order->meta );
		$this->assertSame( array(), Optin_Test_WP::$posts );
	}

	public function test_no_billing_phone_keeps_consent_meta_but_sends_nothing(): void {
		$this->enable_sms();
		$_POST['woodash_sms_optin'] = '1';
		$order                      = $this->order();
		$order->billing_phone       = '  ';

		$this->handler->handle_order_sms_optin( $order );

		$this->assertSame( 1, $order->meta['_woodash_sms_optin'] );
		$this->assertArrayNotHasKey( '_woodash_sms_optin_webhook_sent', $order->meta );
		$this->assertSame( array(), Optin_Test_WP::$posts );
	}

	public function test_bridged_block_meta_fires_without_post(): void {
		$this->enable_sms();
		$order                             = $this->order();
		$order->meta['_woodash_sms_optin'] = 1;

		$this->handler->handle_order_sms_optin( $order );

		$this->assertSame( 'subscription.sms_optin', $this->last_payload()['event'] );
	}

	public function test_sends_at_most_once_per_order(): void {
		$this->enable_sms();
		$_POST['woodash_sms_optin'] = '1';
		$order                      = $this->order();

		$this->handler->handle_order_sms_optin( $order );
		$this->handler->handle_order_sms_optin( $order ); // second hook, same request
		$this->reset_processed( 'processed_sms_order_ids' );
		$this->handler->handle_order_sms_optin( $order ); // later request, meta flag set

		$this->assertCount( 1, Optin_Test_WP::$posts );
	}

	public function test_sms_not_sent_when_webhook_url_cleared(): void {
		$this->enable_sms();
		$GLOBALS['__seo_options']['woodash_optin_webhook_url'] = '';
		$_POST['woodash_sms_optin']                            = '1';
		$order                                                 = $this->order();

		$this->handler->handle_order_sms_optin( $order );

		$this->assertSame( array(), Optin_Test_WP::$posts );
		$this->assertArrayNotHasKey( '_woodash_sms_optin_webhook_sent', $order->meta );
	}

	// ── send_optin_webhook: e-mail callers unchanged ────────────────────

	public function test_email_webhook_payload_unchanged(): void {
		$sent = Opt_In_Handler::send_optin_webhook(
			array(
				'email'       => ' Foo@Bar.com ',
				'customer_id' => 5,
				'source'      => 'signup_widget',
			)
		);

		$this->assertTrue( $sent );
		$payload = $this->last_payload();
		$this->assertSame( 'subscription.optin', $payload['event'] );
		$this->assertSame(
			array(
				'email'       => 'foo@bar.com',
				'customer_id' => 5,
				'source'      => 'signup_widget',
				'initiator'   => 'customer',
			),
			$payload['data']
		);
		$this->assertSame( Opt_In_Handler::DEFAULT_WEBHOOK_URL, Optin_Test_WP::$posts[0]['url'] );
	}

	public function test_email_webhook_requires_email(): void {
		$this->assertFalse( Opt_In_Handler::send_optin_webhook( array( 'email' => '', 'source' => 'checkout_optin' ) ) );
		$this->assertSame( array(), Optin_Test_WP::$posts );
	}

	public function test_sms_webhook_requires_phone_but_not_email(): void {
		$this->assertFalse(
			Opt_In_Handler::send_optin_webhook( array( 'email' => 'a@b.c' ), Opt_In_Handler::SMS_EVENT, array( 'phone' => '' ) )
		);
		$this->assertTrue(
			Opt_In_Handler::send_optin_webhook( array( 'email' => '' ), Opt_In_Handler::SMS_EVENT, array( 'phone' => '5551234' ) )
		);
		$payload = $this->last_payload();
		$this->assertSame( '', $payload['data']['email'] );
		$this->assertSame( '5551234', $payload['data']['phone'] );
	}

	// ── Block checkout bridge ───────────────────────────────────────────

	public function test_block_bridge_copies_field_into_sms_meta(): void {
		$this->enable_sms();
		$bridge = new Blocks_Sms_Optin_Field();
		$order  = $this->order();

		$bridge->bridge_optin_to_order_meta( $order ); // field absent → untouched
		$this->assertArrayNotHasKey( '_woodash_sms_optin', $order->meta );

		$order->meta[ Blocks_Optin_Field::OTHER_FIELDS_PREFIX . Blocks_Sms_Optin_Field::FIELD_ID ] = '1';
		$bridge->bridge_optin_to_order_meta( $order );
		$this->assertSame( 1, $order->meta['_woodash_sms_optin'] );

		$order->meta[ Blocks_Optin_Field::OTHER_FIELDS_PREFIX . Blocks_Sms_Optin_Field::FIELD_ID ] = '';
		$bridge->bridge_optin_to_order_meta( $order );
		$this->assertSame( 0, $order->meta['_woodash_sms_optin'] );
	}

	public function test_block_bridge_inert_when_disabled(): void {
		$bridge = new Blocks_Sms_Optin_Field();
		$order  = $this->order();
		$order->meta[ Blocks_Optin_Field::OTHER_FIELDS_PREFIX . Blocks_Sms_Optin_Field::FIELD_ID ] = '1';

		$bridge->bridge_optin_to_order_meta( $order );

		$this->assertArrayNotHasKey( '_woodash_sms_optin', $order->meta );
	}
}
