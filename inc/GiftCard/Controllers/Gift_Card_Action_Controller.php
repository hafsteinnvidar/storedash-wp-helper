<?php
/**
 * Write routes (contract C): issue, adjust, disable, enable, resend.
 *
 * @package StoreDash\GiftCard\Controllers
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Code;
use StoreDash\GiftCard\Engine\Issuer;
use StoreDash\GiftCard\Gift_Card_Webhook;
use StoreDash\GiftCard\Product\Recipient_Fields;
use WP_REST_Request;

/**
 * Manual card operations.
 *
 * @since 1.24.0
 */
class Gift_Card_Action_Controller extends Abstract_Gift_Card_Controller {

	/**
	 * `POST /gift-cards/issue`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function issue( WP_REST_Request $request ) {
		$data = $this->body( $request );

		$amount = isset( $data['amount'] ) && is_numeric( $data['amount'] ) ? round( (float) $data['amount'], 4 ) : 0.0;
		if ( $amount <= 0 ) {
			return $this->error( 'storedash_gift_card_invalid_amount', 'amount must be greater than zero' );
		}
		$idem = isset( $data['idem_key'] ) && is_scalar( $data['idem_key'] ) ? trim( (string) $data['idem_key'] ) : '';
		if ( '' === $idem ) {
			return $this->error( 'storedash_gift_card_missing_idem', 'idem_key is required' );
		}

		$email = $this->optional_email( $data['recipient_email'] ?? null );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$message = $this->optional_text( $data['message'] ?? null, true );
		if ( null !== $message && ( function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message ) ) > Recipient_Fields::MESSAGE_MAX ) {
			return $this->error( 'storedash_gift_card_invalid_message', 'message is too long' );
		}

		$send_at = null;
		if ( ! empty( $data['send_at'] ) && is_string( $data['send_at'] ) ) {
			$ts = strtotime( $data['send_at'] );
			if ( false === $ts ) {
				return $this->error( 'storedash_gift_card_invalid_send_at', 'send_at must be an ISO-8601 datetime' );
			}
			$send_at = gmdate( 'Y-m-d H:i:s', $ts );
		}

		Code::check_key_fingerprint();

		$result = $this->ledger->issue(
			array(
				'initial_amount'  => $amount,
				'currency'        => get_woocommerce_currency(),
				'source'          => Card_Ledger::SOURCE_MANUAL,
				'recipient_email' => $email,
				'recipient_name'  => $this->optional_text( $data['recipient_name'] ?? null ),
				'sender_name'     => $this->optional_text( $data['sender_name'] ?? null ),
				'message'         => $message,
				'send_at'         => $send_at,
				'note'            => $this->optional_text( $data['note'] ?? null ),
			),
			'issue:manual:' . $idem
		);

		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message(), 500 );
		}

		if ( ! empty( $result['created'] ) ) {
			$delivery = null !== $email ? Issuer::delivery( $result['card'], $result['code'], 'issued' ) : null;
			Gift_Card_Webhook::send( 'issued', $result['card'], $result['row'], $delivery );
		}

		return rest_ensure_response( array( 'card' => $this->format_card( $result['card'] ) ) );
	}

	/**
	 * `POST /gift-cards/{id}/adjust`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function adjust( WP_REST_Request $request ) {
		$card = $this->card_from_request( $request );
		if ( is_wp_error( $card ) ) {
			return $card;
		}
		$data = $this->body( $request );

		$amount = isset( $data['amount'] ) && is_numeric( $data['amount'] ) ? round( (float) $data['amount'], 4 ) : 0.0;
		if ( 0.0 === $amount ) {
			return $this->error( 'storedash_gift_card_invalid_amount', 'amount must be a non-zero number' );
		}
		$note = $this->optional_text( $data['note'] ?? null );
		if ( null === $note ) {
			return $this->error( 'storedash_gift_card_missing_note', 'note is required' );
		}
		$idem = isset( $data['idem_key'] ) && is_scalar( $data['idem_key'] ) ? trim( (string) $data['idem_key'] ) : '';
		if ( '' === $idem ) {
			return $this->error( 'storedash_gift_card_missing_idem', 'idem_key is required' );
		}

		// Negative adjustments are capped at the balance (never below 0).
		$result = $this->ledger->move( (int) $card->id, $amount, Card_Ledger::TYPE_ADJUST, 'adjust:' . $idem, array( 'note' => $note ) );
		if ( null === $result ) {
			return $this->error( 'storedash_gift_card_insufficient', 'Gift card has no balance to adjust', 409 );
		}
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message(), 500 );
		}
		if ( (int) $result['row']->card_id !== (int) $card->id ) {
			return $this->error( 'storedash_gift_card_idem_conflict', 'idem_key was already used for another card', 409 );
		}

		if ( ! empty( $result['created'] ) ) {
			Gift_Card_Webhook::send( 'adjusted', $result['card'], $result['row'] );
		}

		return rest_ensure_response(
			array(
				'card' => $this->format_card( $result['card'] ),
				'row'  => $this->format_row( $result['row'] ),
			)
		);
	}

	/**
	 * `POST /gift-cards/{id}/disable`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function disable( WP_REST_Request $request ) {
		return $this->set_status( $request, Card_Ledger::STATUS_DISABLED );
	}

	/**
	 * `POST /gift-cards/{id}/enable`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enable( WP_REST_Request $request ) {
		return $this->set_status( $request, Card_Ledger::STATUS_ACTIVE );
	}

	/**
	 * `POST /gift-cards/{id}/resend`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resend( WP_REST_Request $request ) {
		$card = $this->card_from_request( $request );
		if ( is_wp_error( $card ) ) {
			return $card;
		}
		if ( Card_Ledger::STATUS_ACTIVE !== $card->status ) {
			return $this->error( 'storedash_gift_card_disabled', 'Gift card is disabled', 409 );
		}
		$data = $this->body( $request );

		$to = $this->optional_email( $data['to_email'] ?? null );
		if ( is_wp_error( $to ) ) {
			return $to;
		}

		$delivery = Issuer::delivery( $card, '', 'resend', $to );
		if ( null === $delivery ) {
			$has_recipient = null !== $to || ! empty( $card->recipient_email ) || ! empty( $card->purchaser_email );
			return $has_recipient
				? $this->error( 'storedash_gift_card_code_unavailable', 'The gift card code cannot be recovered on this store (WordPress salts changed?)', 409 )
				: $this->error( 'storedash_gift_card_no_recipient', 'No email to send the gift card to', 400 );
		}

		// Not sent → 503 with the cause (not connected / webhook URL empty /
		// dispatch failed) so the dashboard can show it instead of a generic failure.
		$dispatched = Gift_Card_Webhook::dispatch( 'send', $card, null, $delivery );
		if ( is_wp_error( $dispatched ) ) {
			return $dispatched;
		}

		return rest_ensure_response( array( 'queued' => true ) );
	}

	/**
	 * Shared disable / enable.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $status  Target status.
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function set_status( WP_REST_Request $request, string $status ) {
		$card = $this->card_from_request( $request );
		if ( is_wp_error( $card ) ) {
			return $card;
		}
		$data   = $this->body( $request );
		$result = $this->ledger->set_status( (int) $card->id, $status, (string) $this->optional_text( $data['note'] ?? null ) );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message(), 500 );
		}

		if ( ! empty( $result['created'] ) ) {
			Gift_Card_Webhook::send( Card_Ledger::STATUS_DISABLED === $status ? 'disabled' : 'enabled', $result['card'], $result['row'] );
		}

		return rest_ensure_response(
			array(
				'card' => $this->format_card( $result['card'] ),
				'row'  => $this->format_row( $result['row'] ),
			)
		);
	}
}
