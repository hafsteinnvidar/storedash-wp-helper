<?php
/**
 * Pure gift card logic: refund pro-rata + purchase reclaim, mint rules,
 * settings, recipient validation, Store API + webhook shapes, rate limiting.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\GiftCard\Blocks\Store_API_Integration;
use StoreDash\GiftCard\Engine\Issuer;
use StoreDash\GiftCard\Engine\Rate_Limiter;
use StoreDash\GiftCard\Engine\Refund_Handler;
use StoreDash\GiftCard\Gift_Card_Webhook;
use StoreDash\GiftCard\Product\Recipient_Fields;
use StoreDash\GiftCard\Serializer;
use StoreDash\GiftCard\Settings;

require_once __DIR__ . '/gift-card-stubs.php';

/**
 * Limiter with in-memory counters and a controllable clock.
 */
class Memory_Rate_Limiter extends Rate_Limiter {
	/** @var array */
	public $store = array();
	/** @var int */
	public $clock = 1000000;

	protected function read( string $key ) {
		return $this->store[ $key ] ?? null;
	}

	protected function write( string $key, array $entry, int $ttl ): void {
		$this->store[ $key ] = $entry;
	}

	protected function now(): int {
		return $this->clock;
	}
}

/**
 * @covers \StoreDash\GiftCard\Engine\Refund_Handler
 * @covers \StoreDash\GiftCard\Engine\Issuer
 * @covers \StoreDash\GiftCard\Settings
 * @covers \StoreDash\GiftCard\Product\Recipient_Fields
 * @covers \StoreDash\GiftCard\Blocks\Store_API_Integration::format_state
 * @covers \StoreDash\GiftCard\Gift_Card_Webhook::payload
 * @covers \StoreDash\GiftCard\Serializer
 * @covers \StoreDash\GiftCard\Engine\Rate_Limiter
 */
class Gift_Card_LogicTest extends TestCase {

	// ── Refunds ───────────────────────────────────────────────────────────

	public function test_full_cash_refund_returns_the_whole_card_part() {
		// 15k order: 10k card + 5k cash. Refund 5k cash → card gets 10k back.
		$share = Refund_Handler::share( 5000, 5000, 0, 0 );
		$this->assertSame( 1.0, $share );
		$this->assertSame( 10000.0, round( 10000 * $share ) );
	}

	public function test_partial_cash_refund_is_pro_rata() {
		$share = Refund_Handler::share( 1000, 5000, 0, 0 );
		$this->assertSame( 2000.0, round( 10000 * $share ) );
	}

	public function test_card_only_order_uses_refunded_line_value() {
		// 8k order fully paid by card (cash 0), 2k of lines refunded → 2k back.
		$share = Refund_Handler::share( 0, 0, 2000, 8000 );
		$this->assertSame( 0.25, $share );
		$this->assertSame( 0.0, Refund_Handler::share( 0, 0, 0, 0 ) );
	}

	public function test_distribute_pro_rata_across_cards_with_caps() {
		$this->assertSame(
			array( 7 => 6000.0, 9 => 3000.0 ),
			Refund_Handler::distribute( 9000, array( 7 => 10000, 9 => 5000 ), array( 7 => 10000, 9 => 5000 ), 0 )
		);
		// Card 7 already refunded mostly → overflow goes to card 9.
		$this->assertSame(
			array( 7 => 1000.0, 9 => 5000.0 ),
			Refund_Handler::distribute( 6000, array( 7 => 10000, 9 => 5000 ), array( 7 => 1000, 9 => 5000 ), 0 )
		);
		// Never more than the caps.
		$this->assertSame( array( 7 => 500.0 ), Refund_Handler::distribute( 9000, array( 7 => 10000 ), array( 7 => 500 ), 0 ) );
		$this->assertSame( array(), Refund_Handler::distribute( 0, array( 7 => 10000 ), array( 7 => 500 ), 0 ) );
	}

	public function test_distribute_rounding_never_loses_a_cent() {
		$out = Refund_Handler::distribute( 100, array( 1 => 1, 2 => 1, 3 => 1 ), array( 1 => 100, 2 => 100, 3 => 100 ), 2 );
		$this->assertEqualsWithDelta( 100.0, array_sum( $out ), 0.0001 );
	}

	public function test_reclaim_purchase_refund_takes_fullest_cards_first_and_reports_shortfall() {
		$plan = Refund_Handler::reclaim( 10000, array( 7 => 10000, 8 => 4000 ), 0 );
		$this->assertSame( array( 7 => 10000.0 ), $plan['take'] );
		$this->assertSame( 0.0, $plan['short'] );

		// 2 × 10k sold, one card half spent, refund both.
		$plan = Refund_Handler::reclaim( 20000, array( 7 => 10000, 8 => 5000 ), 0 );
		$this->assertSame( array( 7 => 10000.0, 8 => 5000.0 ), $plan['take'] );
		$this->assertSame( 5000.0, $plan['short'] );
	}

	// ── Mint ──────────────────────────────────────────────────────────────

	public function test_mint_status_rules() {
		$this->assertTrue( Issuer::should_issue( 'processing', 'processing' ) );
		$this->assertTrue( Issuer::should_issue( 'completed', 'processing' ) );
		$this->assertTrue( Issuer::should_issue( 'completed', 'completed' ) );
		$this->assertFalse( Issuer::should_issue( 'processing', 'completed' ) );
		$this->assertFalse( Issuer::should_issue( 'on-hold', 'processing' ) );
		$this->assertFalse( Issuer::should_issue( 'pending', 'processing' ) );
	}

	public function test_unit_value_is_subtotal_over_quantity() {
		$this->assertSame( 10000.0, Issuer::unit_value( 20000, 2, 0 ) );
		$this->assertSame( 33.33, Issuer::unit_value( 100, 3, 2 ) );
		$this->assertSame( 0.0, Issuer::unit_value( 100, 0, 2 ) );
	}

	public function test_delivery_goes_to_recipient_else_purchaser() {
		$card = (object) array(
			'recipient_email' => 'c@d.is',
			'purchaser_email' => 'a@b.is',
			'code_enc'        => '',
		);
		$this->assertSame( 'c@d.is', Issuer::delivery( $card, 'ABCDEFGHJKMNK9QZ', 'issued' )['to_email'] );
		$card->recipient_email = null;
		$this->assertSame( 'a@b.is', Issuer::delivery( $card, 'ABCDEFGHJKMNK9QZ', 'issued' )['to_email'] );
		$this->assertSame( 'x@y.is', Issuer::delivery( $card, 'ABCDEFGHJKMNK9QZ', 'resend', 'x@y.is' )['to_email'] );
		$card->purchaser_email = null;
		$this->assertNull( Issuer::delivery( $card, 'ABCDEFGHJKMNK9QZ', 'issued' ) );
	}

	// ── Settings ──────────────────────────────────────────────────────────

	public function test_settings_normalize() {
		$this->assertSame(
			array(
				'enabled'             => true,
				'issue_on_status'     => 'completed',
				'max_cards_per_order' => 20,
			),
			Settings::normalize(
				array(
					'enabled'             => 'true',
					'issue_on_status'     => 'completed',
					'max_cards_per_order' => 99,
					'expiry_days'         => 30,
				)
			)
		);
		$this->assertSame( Settings::defaults(), Settings::normalize( array( 'issue_on_status' => 'on-hold' ) ) );
		$this->assertSame( 1, Settings::normalize( array( 'max_cards_per_order' => 0 ) )['max_cards_per_order'] );
	}

	// ── Recipient fields ──────────────────────────────────────────────────

	public function test_recipient_validation_accepts_a_full_payload() {
		$result = Recipient_Fields::validate(
			array(
				'recipient_email' => ' C@D.is ',
				'recipient_name'  => 'Sigga',
				'sender_name'     => 'Jón',
				'message'         => "Til hamingju!\nKnús",
				'send_at'         => '2026-12-24',
				'unknown'         => 'dropped',
			),
			'2026-10-05'
		);
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'c@d.is', $result['data']['recipient_email'] );
		$this->assertSame( "Til hamingju!\nKnús", $result['data']['message'] );
		$this->assertSame( '2026-12-24', $result['data']['send_at'] );
		$this->assertArrayNotHasKey( 'unknown', $result['data'] );
	}

	public function test_recipient_validation_rejects_bad_input() {
		$today = '2026-10-05';
		$this->assertNotEmpty( Recipient_Fields::validate( array( 'recipient_email' => 'nope' ), $today )['errors'] );
		$this->assertNotEmpty( Recipient_Fields::validate( array( 'message' => str_repeat( 'á', 501 ) ), $today )['errors'] );
		$this->assertEmpty( Recipient_Fields::validate( array( 'message' => str_repeat( 'á', 500 ) ), $today )['errors'] );
		$this->assertNotEmpty( Recipient_Fields::validate( array( 'send_at' => '2026-10-04' ), $today )['errors'] );
		$this->assertNotEmpty( Recipient_Fields::validate( array( 'send_at' => '2027-10-06' ), $today )['errors'] );
		$this->assertEmpty( Recipient_Fields::validate( array( 'send_at' => '2027-10-05' ), $today )['errors'] );
		$this->assertNotEmpty( Recipient_Fields::validate( array( 'send_at' => '2026-02-30' ), $today )['errors'] );
		$this->assertSame( array(), Recipient_Fields::validate( array( 'recipient_email' => '' ), $today )['data'] );
	}

	public function test_send_at_is_9am_store_time_in_utc() {
		$this->assertSame( '2026-12-24 09:00:00', Recipient_Fields::send_at_utc( '2026-12-24', new DateTimeZone( 'Atlantic/Reykjavik' ) ) );
		$this->assertSame( '2026-12-24 08:00:00', Recipient_Fields::send_at_utc( '2026-12-24', new DateTimeZone( 'Europe/Copenhagen' ) ) );
		$this->assertNull( Recipient_Fields::send_at_utc( 'soon', new DateTimeZone( 'UTC' ) ) );
	}

	// ── Wire shapes ───────────────────────────────────────────────────────

	public function test_store_api_state_uses_minor_units() {
		$out = Store_API_Integration::format_state(
			array(
				'enabled'            => true,
				'max_cards'          => 5,
				'cart_has_gift_card' => false,
				'cards'              => array(
					array(
						'id'      => 7,
						'last4'   => 'K9QZ',
						'balance' => 10000.0,
						'applied' => 8000.0,
					),
				),
				'applied_total'      => 8000.0,
			),
			0
		);
		$this->assertSame(
			array(
				'enabled'            => true,
				'max_cards'          => 5,
				'cart_has_gift_card' => false,
				'cards'              => array(
					array(
						'id'      => 7,
						'last4'   => 'K9QZ',
						'balance' => '10000',
						'applied' => '8000',
					),
				),
				'applied_total'      => '8000',
			),
			$out
		);
		$this->assertSame( '1250', Store_API_Integration::format_state( array( 'applied_total' => 12.5 ), 2 )['applied_total'] );
	}

	private function card_row(): object {
		return (object) array(
			'id'              => 7,
			'code_hash'       => str_repeat( 'a', 64 ),
			'code_enc'        => 's1:xyz',
			'code_last4'      => 'K9QZ',
			'initial_amount'  => '10000.0000',
			'balance'         => '2000.0000',
			'currency'        => 'ISK',
			'status'          => 'active',
			'source'          => 'purchase',
			'order_id'        => '9911',
			'order_item_id'   => '55',
			'unit_index'      => '1',
			'product_id'      => '321',
			'purchaser_email' => 'a@b.is',
			'recipient_email' => 'c@d.is',
			'recipient_name'  => 'Sigga',
			'sender_name'     => 'Jón',
			'message'         => 'Til hamingju!',
			'send_at'         => '2026-12-24 09:00:00',
			'sent_at'         => null,
			'note'            => null,
			'created_at'      => '2026-10-05 16:00:00',
			'updated_at'      => '2026-10-05 16:00:00',
		);
	}

	public function test_gift_card_serializer_matches_contract_and_hides_code_material() {
		$out = Serializer::card( $this->card_row(), '1043', 0 );
		$this->assertSame(
			array( 'id', 'store_id', 'code_last4', 'initial_amount', 'balance', 'currency', 'status', 'source', 'order_id', 'order_item_id', 'product_id', 'purchaser_email', 'recipient_email', 'recipient_name', 'sender_name', 'message', 'send_at', 'sent_at', 'note', 'created_at', 'updated_at' ),
			array_keys( $out )
		);
		$this->assertSame( '1043', $out['store_id'] );
		$this->assertSame( '10000.00', $out['initial_amount'] );
		$this->assertSame( '2000.00', $out['balance'] );
		$this->assertSame( 9911, $out['order_id'] );
		$this->assertSame( '2026-12-24T09:00:00Z', $out['send_at'] );
		$this->assertNull( $out['sent_at'] );
	}

	public function test_ledger_row_serializer_matches_contract() {
		$out = Serializer::row(
			(object) array(
				'id'            => 42,
				'card_id'       => 7,
				'type'          => 'spend',
				'amount'        => '-8000.0000',
				'balance_after' => '2000.0000',
				'currency'      => 'ISK',
				'order_id'      => 9920,
				'refund_id'     => null,
				'note'          => '',
				'idem_key'      => 'spend:9920:7:1',
				'created_at'    => '2026-10-05 16:00:00',
			),
			0
		);
		$this->assertSame(
			array(
				'id'            => 42,
				'card_id'       => 7,
				'type'          => 'spend',
				'amount'        => '-8000.00',
				'balance_after' => '2000.00',
				'currency'      => 'ISK',
				'order_id'      => 9920,
				'refund_id'     => null,
				'note'          => null,
				'created_at'    => '2026-10-05T16:00:00Z',
			),
			$out
		);
	}

	public function test_webhook_payload_carries_delivery_only_for_issued_and_send() {
		$card     = Serializer::card( $this->card_row(), '1043', 0 );
		$delivery = array(
			'code'     => 'abcdefghjkmnk9qz',
			'to_email' => 'c@d.is',
			'reason'   => 'issued',
		);

		$issued = Gift_Card_Webhook::payload( 'issued', $card, null, $delivery, '1043', 'https://skinroom.is', '2026-10-05 16:00:00' );
		$this->assertSame( 'giftcard.issued', $issued['action'] );
		$this->assertSame(
			array(
				'code'     => 'ABCD-EFGH-JKMN-K9QZ',
				'to_email' => 'c@d.is',
				'reason'   => 'issued',
			),
			$issued['delivery']
		);
		$this->assertSame( array( 'action', 'store_id', 'store_url', 'timestamp', 'card', 'row', 'delivery' ), array_keys( $issued ) );

		$spent = Gift_Card_Webhook::payload( 'spent', $card, array( 'id' => 1 ), $delivery, '1043', 'https://skinroom.is', '2026-10-05 16:00:00' );
		$this->assertNull( $spent['delivery'], 'never leak a code on other events' );
		$this->assertStringNotContainsString( 'K9QZ-', wp_json_encode( $spent ) );

		$send = Gift_Card_Webhook::payload( 'send', $card, null, array_merge( $delivery, array( 'reason' => 'resend' ) ), '1043', 'u', 't' );
		$this->assertSame( 'resend', $send['delivery']['reason'] );
	}

	// ── Rate limiting ─────────────────────────────────────────────────────

	public function test_sixth_failed_code_within_ten_minutes_is_blocked() {
		$limiter = new Memory_Rate_Limiter();
		$keys    = array( 'session-key', 'ip-key' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( $limiter->is_limited( $keys ) );
			$limiter->record_failure( $keys );
		}
		$this->assertTrue( $limiter->is_limited( $keys ) );
		$this->assertTrue( $limiter->is_limited( array( 'other-session', 'ip-key' ) ), 'same IP, new session is still blocked' );

		$limiter->clock += 601;
		$this->assertFalse( $limiter->is_limited( $keys ) );
	}
}
