<?php
/**
 * Shared helpers for the gift card REST controllers.
 *
 * @package StoreDash\GiftCard\Controllers
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Serializer;
use WP_Error;
use WP_REST_Request;

/**
 * Base controller.
 *
 * @since 1.24.0
 */
abstract class Abstract_Gift_Card_Controller {

	/**
	 * Card store.
	 *
	 * @var Card_Ledger
	 */
	protected $ledger;

	/**
	 * Constructor.
	 *
	 * @param Card_Ledger|null $ledger Card store.
	 */
	public function __construct( $ledger = null ) {
		$this->ledger = $ledger ? $ledger : new Card_Ledger();
	}

	/**
	 * Decode the JSON body (falls back to request params).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	protected function body( WP_REST_Request $request ): array {
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Error response.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	protected function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Contract-C GiftCard.
	 *
	 * @param object $card Card row.
	 * @return array
	 */
	protected function format_card( $card ): array {
		return Serializer::card( $card, (string) get_option( 'woodash_store_id', '' ), wc_get_price_decimals() );
	}

	/**
	 * Contract-C LedgerRow (null passthrough).
	 *
	 * @param object|null $row Row.
	 * @return array|null
	 */
	protected function format_row( $row ) {
		return $row ? Serializer::row( $row, wc_get_price_decimals() ) : null;
	}

	/**
	 * `since` param → UTC MySQL datetime ('' when absent).
	 *
	 * @param mixed $since Raw value.
	 * @return string|WP_Error
	 */
	protected function parse_since( $since ) {
		$since = is_string( $since ) ? trim( $since ) : '';
		if ( '' === $since ) {
			return '';
		}
		$ts = strtotime( $since );
		if ( false === $ts ) {
			return $this->error( 'storedash_gift_card_invalid_since', 'since must be an ISO-8601 datetime' );
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Card from the `{id}` route param.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return object|WP_Error
	 */
	protected function card_from_request( WP_REST_Request $request ) {
		$card = $this->ledger->get_card( (int) $request->get_param( 'id' ) );
		return $card ? $card : $this->error( 'storedash_gift_card_not_found', 'Gift card not found', 404 );
	}

	/**
	 * Optional email field.
	 *
	 * @param mixed $value Raw.
	 * @return string|null|WP_Error Null when empty.
	 */
	protected function optional_email( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( '' === $value ) {
			return null;
		}
		return is_email( $value ) ? $value : $this->error( 'storedash_gift_card_invalid_email', 'A valid email is required' );
	}

	/**
	 * Optional text field.
	 *
	 * @param mixed $value Raw.
	 * @param bool  $multiline Keep line breaks.
	 * @return string|null
	 */
	protected function optional_text( $value, bool $multiline = false ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = $multiline ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
		return '' === $value ? null : $value;
	}
}
