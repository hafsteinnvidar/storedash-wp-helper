<?php
/**
 * The shopper's "use my credit" request, stored in the WooCommerce session.
 *
 * Session key `storedash_credit_request` = { apply: bool, amount: string|null }
 * (contract C). Set by the classic-checkout AJAX handler, the Store API update
 * callback, and the block-checkout additional field bridge; read by the fee
 * hook.
 *
 * @package StoreDash\Credit\Engine
 * @since   1.17.0
 */

namespace StoreDash\Credit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session accessor.
 *
 * @since 1.17.0
 */
class Credit_Request {

	/**
	 * WC session key.
	 */
	const SESSION_KEY = 'storedash_credit_request';

	/**
	 * Read the request.
	 *
	 * @return array { apply: bool, amount: string|null }
	 */
	public static function get(): array {
		$default = array(
			'apply'  => false,
			'amount' => null,
		);
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return $default;
		}
		$stored = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $stored ) ) {
			return $default;
		}
		return array(
			'apply'  => ! empty( $stored['apply'] ),
			'amount' => isset( $stored['amount'] ) && '' !== $stored['amount'] && null !== $stored['amount'] ? (string) $stored['amount'] : null,
		);
	}

	/**
	 * Store the request.
	 *
	 * @param bool              $apply  Whether to apply credit.
	 * @param string|float|null $amount Requested amount in major units (null = max).
	 */
	public static function set( bool $apply, $amount = null ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		if ( null !== $amount && '' !== $amount ) {
			$amount = (float) $amount;
			$amount = $amount > 0 ? (string) $amount : null;
		} else {
			$amount = null;
		}
		WC()->session->set(
			self::SESSION_KEY,
			array(
				'apply'  => $apply,
				'amount' => $amount,
			)
		);
	}

	/**
	 * Remove the request (after the order is placed).
	 */
	public static function clear(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}
}
