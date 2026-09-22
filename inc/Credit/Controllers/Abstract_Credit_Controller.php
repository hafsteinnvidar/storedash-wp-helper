<?php
/**
 * Shared helpers for the rewards credit REST controllers.
 *
 * @package StoreDash\Credit\Controllers
 * @since   1.17.0
 */

namespace StoreDash\Credit\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Engine\Customer_Resolver;
use StoreDash\Credit\Ledger;
use StoreDash\Credit\Money;
use StoreDash\Credit\Settings;
use WP_Error;
use WP_REST_Request;

/**
 * Base controller.
 *
 * @since 1.17.0
 */
abstract class Abstract_Credit_Controller {

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	protected $ledger;

	/**
	 * Constructor.
	 *
	 * @param Ledger|null $ledger Ledger.
	 */
	public function __construct( $ledger = null ) {
		$this->ledger = $ledger ? $ledger : new Ledger();
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
	 * Contract-B LedgerRow for a DB row.
	 *
	 * @param object $row Row.
	 * @return array
	 */
	protected function format_row( $row ): array {
		return Money::format_row( $row, (string) get_option( 'woodash_store_id', '' ), wc_get_price_decimals() );
	}

	/**
	 * Balance payload for a customer key (shared by /balance and /me).
	 *
	 * @param string $customer_key Customer key.
	 * @return array
	 */
	protected function balance_payload( string $customer_key ): array {
		$decimals = wc_get_price_decimals();
		$expiring = $this->ledger->expiring_next( $customer_key );

		return array(
			'enabled'       => Settings::is_enabled(),
			'email'         => $customer_key,
			'currency'      => get_woocommerce_currency(),
			'balance'       => Money::to_decimal_string( $this->ledger->balance( $customer_key ), $decimals ),
			'expiring_next' => $expiring ? array(
				'amount' => Money::to_decimal_string( $expiring['amount'], $decimals ),
				'at'     => Money::to_iso( $expiring['at'] ),
			) : null,
			'entries'       => array_map( array( $this, 'format_row' ), $this->ledger->entries( $customer_key, 50 ) ),
		);
	}

	/**
	 * Validate + normalize an email parameter into a customer key.
	 *
	 * @param mixed $email Raw email.
	 * @return string|WP_Error
	 */
	protected function customer_key_from_email( $email ) {
		$email = is_string( $email ) ? trim( $email ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return $this->error( 'storedash_credit_invalid_email', 'A valid email is required' );
		}
		return Ledger::customer_key( $email );
	}

	/**
	 * User id for a customer key (null for guests).
	 *
	 * @param string $customer_key Customer key.
	 * @return int|null
	 */
	protected function user_id_for( string $customer_key ) {
		return Customer_Resolver::user_id_for_email( $customer_key );
	}
}
