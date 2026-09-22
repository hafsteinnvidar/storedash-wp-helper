<?php
/**
 * Rewards credit outbound webhook (contract D).
 *
 * Fired after every ledger write so Storedash can mirror the ledger. Signed
 * exactly like the cart webhooks: hex HMAC-SHA256 over the exact JSON bytes
 * with the store's webhook secret, in `X-WooDash-Signature`. Fire-and-forget.
 *
 * @package StoreDash\Credit
 * @since   1.17.0
 */

namespace StoreDash\Credit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends `credit.*` events.
 *
 * @since 1.17.0
 */
class Credit_Webhook {

	/**
	 * Option overriding the default Hookdeck endpoint.
	 */
	const URL_OPTION = 'storedash_credit_webhook_url';

	/**
	 * Default Hookdeck source URL for credit events.
	 */
	const DEFAULT_URL = 'https://webhooks.storedash.io/qruffal2hfp3sy';

	/**
	 * Map of ledger result → webhook action.
	 *
	 * @param string $event Logical event: earned|spent|reversed|expired|granted|adjusted|released.
	 * @return string
	 */
	public static function action_name( string $event ): string {
		return 'credit.' . $event;
	}

	/**
	 * Send a ledger event.
	 *
	 * @param string $event    Logical event (earned|spent|reversed|expired|granted|adjusted|released).
	 * @param object $row      Ledger row (DB object).
	 * @param array  $affected Earn rows whose `remaining` changed: [{id, remaining}].
	 * @return bool Whether a request was dispatched.
	 */
	public static function send( string $event, $row, array $affected = array() ): bool {
		if ( ! $row || ! \StoreDash_Helpers::is_store_connected() ) {
			return false;
		}

		$url = \StoreDash_Helpers::resolve_webhook_url( self::URL_OPTION, self::DEFAULT_URL );
		if ( '' === $url ) {
			return false;
		}

		$decimals = wc_get_price_decimals();
		$store_id = (string) get_option( 'woodash_store_id', '' );

		$affected_out = array();
		foreach ( $affected as $item ) {
			if ( ! isset( $item['id'] ) ) {
				continue;
			}
			$affected_out[] = array(
				'id'        => (int) $item['id'],
				'remaining' => Money::to_decimal_string( $item['remaining'] ?? 0, $decimals ),
			);
		}

		$payload = array(
			'action'    => self::action_name( $event ),
			'store_id'  => $store_id,
			'store_url' => get_site_url(),
			'timestamp' => current_time( 'mysql', true ),
			'row'       => Money::format_row( $row, $store_id, $decimals ),
			'affected'  => $affected_out,
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
			\StoreDash_Helpers::log_message( 'Rewards credit webhook dispatch failed: ' . $response->get_error_message(), 'error', array( 'action' => $payload['action'] ) );
			return false;
		}

		return true;
	}
}
