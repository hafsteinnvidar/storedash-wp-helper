<?php
/**
 * Gift card outbound webhook (contract F).
 *
 * One signed POST per card event. Signed exactly like Credit_Webhook: hex
 * HMAC-SHA256 over the exact JSON bytes with the store's webhook secret, in
 * `X-WooDash-Signature`. Fire-and-forget.
 *
 * `delivery` (the only place a raw code leaves WordPress) is attached to
 * `giftcard.issued` and `giftcard.send` only. Never log the payload.
 *
 * @package StoreDash\GiftCard
 * @since   1.24.0
 */

namespace StoreDash\GiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends `giftcard.*` events.
 *
 * @since 1.24.0
 */
class Gift_Card_Webhook {

	/**
	 * Option overriding the default endpoint.
	 */
	const URL_OPTION = 'storedash_gift_card_webhook_url';

	/**
	 * Default endpoint — dedicated Hookdeck source `woo-gift-card`
	 * (-> storedash-sync /webhooks/gift-card).
	 */
	const DEFAULT_URL = 'https://webhooks.storedash.io/hjduk3dhzxzd7c';

	/**
	 * Allowed events.
	 */
	const EVENTS = array( 'issued', 'spent', 'released', 'refunded', 'adjusted', 'disabled', 'enabled', 'send' );

	/**
	 * Build the payload.
	 *
	 * Pure (given its inputs) — unit tested.
	 *
	 * @param string      $event     Event (see EVENTS).
	 * @param array       $card      Serialized GiftCard.
	 * @param array|null  $row       Serialized LedgerRow or null.
	 * @param array|null  $delivery  { code, to_email, reason } or null.
	 * @param string      $store_id  Store id.
	 * @param string      $store_url Store URL.
	 * @param string      $timestamp UTC "Y-m-d H:i:s".
	 * @return array
	 */
	public static function payload( string $event, array $card, $row, $delivery, string $store_id, string $store_url, string $timestamp ): array {
		$with_delivery = in_array( $event, array( 'issued', 'send' ), true ) && is_array( $delivery ) && ! empty( $delivery['code'] ) && ! empty( $delivery['to_email'] );
		return array(
			'action'    => 'giftcard.' . $event,
			'store_id'  => $store_id,
			'store_url' => $store_url,
			'timestamp' => $timestamp,
			'card'      => $card,
			'row'       => is_array( $row ) ? $row : null,
			'delivery'  => $with_delivery ? array(
				'code'     => Code::format( Code::normalize( $delivery['code'] ) ),
				'to_email' => (string) $delivery['to_email'],
				'reason'   => 'resend' === ( $delivery['reason'] ?? '' ) ? 'resend' : 'issued',
			) : null,
		);
	}

	/**
	 * Send an event.
	 *
	 * @param string      $event    Event.
	 * @param object      $card     Card DB row.
	 * @param object|null $row      Ledger DB row.
	 * @param array|null  $delivery { code, to_email, reason }.
	 * @return bool Whether a request was dispatched.
	 */
	public static function send( string $event, $card, $row = null, $delivery = null ): bool {
		return ! is_wp_error( self::dispatch( $event, $card, $row, $delivery ) );
	}

	/**
	 * Send an event, reporting WHY it could not be dispatched. Codes (all 503):
	 * `storedash_gift_card_not_connected`, `storedash_gift_card_webhook_disabled`,
	 * `storedash_gift_card_dispatch_failed`. Controllers that must tell the
	 * dashboard the cause (resend) use this; fire-and-forget callers use `send()`.
	 *
	 * @param string      $event    Event.
	 * @param object      $card     Card DB row.
	 * @param object|null $row      Ledger DB row.
	 * @param array|null  $delivery { code, to_email, reason }.
	 * @return true|\WP_Error
	 */
	public static function dispatch( string $event, $card, $row = null, $delivery = null ) {
		if ( ! $card || ! in_array( $event, self::EVENTS, true ) ) {
			return new \WP_Error( 'storedash_gift_card_dispatch_failed', 'Unknown gift card event', array( 'status' => 503 ) );
		}
		if ( ! \StoreDash_Helpers::is_store_connected() ) {
			return new \WP_Error( 'storedash_gift_card_not_connected', 'Store is not connected to Storedash', array( 'status' => 503 ) );
		}

		$url = \StoreDash_Helpers::resolve_webhook_url( self::URL_OPTION, self::DEFAULT_URL );
		if ( '' === $url ) {
			return new \WP_Error( 'storedash_gift_card_webhook_disabled', 'Gift card webhook URL is empty', array( 'status' => 503 ) );
		}

		$decimals = wc_get_price_decimals();
		$store_id = (string) get_option( 'woodash_store_id', '' );
		$payload  = self::payload(
			$event,
			Serializer::card( $card, $store_id, $decimals ),
			$row ? Serializer::row( $row, $decimals ) : null,
			$delivery,
			$store_id,
			get_site_url(),
			current_time( 'mysql', true )
		);

		// Sign the EXACT bytes that are sent so the Go receiver's HMAC matches.
		$body    = wp_json_encode( $payload );
		$headers = array( 'Content-Type' => 'application/json' );

		$secret = (string) get_option( 'woodash_webhook_secret', '' );
		if ( '' !== $secret ) {
			$headers['X-WooDash-Signature'] = hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_remote_post(
			$url,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 10,
				'blocking'  => false,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Action + card id only — never the payload (it may carry a code).
			\StoreDash_Helpers::log_message(
				'Gift card webhook dispatch failed: ' . $response->get_error_message(),
				'error',
				array(
					'action'  => $payload['action'],
					'card_id' => (int) $card->id,
				)
			);
			return new \WP_Error( 'storedash_gift_card_dispatch_failed', 'Gift card webhook could not be sent: ' . $response->get_error_message(), array( 'status' => 503 ) );
		}

		return true;
	}
}
