<?php
/**
 * `POST /credit/grant` and `POST /credit/adjust` — manual credit (contract B).
 *
 * @package StoreDash\Credit\Controllers
 * @since   1.17.0
 */

namespace StoreDash\Credit\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Credit_Webhook;
use StoreDash\Credit\Ledger;
use WP_REST_Request;

/**
 * Grant / adjust controller.
 *
 * @since 1.17.0
 */
class Credit_Grant_Controller extends Abstract_Credit_Controller {

	/**
	 * Grant credit (type=manual, amount > 0).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function grant( WP_REST_Request $request ) {
		$data = $this->body( $request );

		$key = $this->customer_key_from_email( $data['email'] ?? '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$amount = isset( $data['amount'] ) ? (float) $data['amount'] : 0.0;
		if ( $amount <= 0 ) {
			return $this->error( 'storedash_credit_invalid_amount', 'amount must be greater than zero' );
		}

		$idem = isset( $data['idem_key'] ) ? trim( (string) $data['idem_key'] ) : '';
		if ( '' === $idem ) {
			return $this->error( 'storedash_credit_missing_idem', 'idem_key is required' );
		}

		$expires_at = null;
		if ( ! empty( $data['expires_at'] ) && is_string( $data['expires_at'] ) ) {
			$ts = strtotime( $data['expires_at'] );
			if ( false === $ts ) {
				return $this->error( 'storedash_credit_invalid_expiry', 'expires_at must be an ISO-8601 datetime' );
			}
			$expires_at = gmdate( 'Y-m-d H:i:s', $ts );
		}

		$result = $this->ledger->add_credit(
			array(
				'customer_key' => $key,
				'user_id'      => $this->user_id_for( $key ),
				'amount'       => $amount,
				'expires_at'   => $expires_at,
				'note'         => isset( $data['note'] ) ? sanitize_text_field( (string) $data['note'] ) : null,
				'idem_key'     => 'grant:' . $idem,
				'type'         => Ledger::TYPE_MANUAL,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message(), 500 );
		}

		if ( ! empty( $result['created'] ) ) {
			Credit_Webhook::send( 'granted', $result['row'] );
		}

		return rest_ensure_response( array( 'row' => $this->format_row( $result['row'] ) ) );
	}

	/**
	 * Adjust credit down (type=adjust, amount < 0, capped at the balance).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function adjust( WP_REST_Request $request ) {
		$data = $this->body( $request );

		$key = $this->customer_key_from_email( $data['email'] ?? '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$amount = isset( $data['amount'] ) ? (float) $data['amount'] : 0.0;
		if ( $amount >= 0 ) {
			return $this->error( 'storedash_credit_invalid_amount', 'amount must be negative' );
		}

		$idem = isset( $data['idem_key'] ) ? trim( (string) $data['idem_key'] ) : '';
		if ( '' === $idem ) {
			return $this->error( 'storedash_credit_missing_idem', 'idem_key is required' );
		}

		$note = isset( $data['note'] ) ? sanitize_text_field( (string) $data['note'] ) : '';
		if ( '' === $note ) {
			return $this->error( 'storedash_credit_missing_note', 'note is required' );
		}

		// Cap at the balance so an adjust can never push a customer negative.
		$balance = $this->ledger->balance( $key );
		$take    = min( abs( $amount ), $balance );
		if ( $take <= 0 ) {
			return $this->error( 'storedash_credit_insufficient', 'Customer has no rewards credit to adjust', 409 );
		}

		$result = $this->ledger->consume(
			$key,
			$take,
			array(
				'idem_key' => 'adjust:' . $idem,
				'type'     => Ledger::TYPE_ADJUST,
				'user_id'  => $this->user_id_for( $key ),
				'note'     => $note,
			)
		);

		if ( is_wp_error( $result ) ) {
			$status = 'storedash_credit_insufficient' === $result->get_error_code() ? 409 : 500;
			return $this->error( $result->get_error_code(), $result->get_error_message(), $status );
		}

		if ( ! empty( $result['created'] ) ) {
			Credit_Webhook::send( 'adjusted', $result['row'], $result['affected'] );
		}

		return rest_ensure_response( array( 'row' => $this->format_row( $result['row'] ) ) );
	}
}
