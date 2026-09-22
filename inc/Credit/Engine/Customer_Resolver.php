<?php
/**
 * Resolves the ledger customer key for the current shopper / a user / an order.
 *
 * The ledger is keyed by lower-cased email so guests can earn (by billing
 * email) and later spend once they register with that address. A logged-in
 * shopper ALWAYS resolves to the account email (wp_users.user_email) — never
 * the billing email typed at checkout or saved in user meta, since both are
 * shopper-editable and would let a logged-in user spend another email's
 * balance. Orders placed by a registered customer resolve through the customer
 * id for the same reason. Headless storefronts are covered because
 * StoreDash_Customer_Auth_Bridge has already set the current user on Store API
 * requests.
 *
 * @package StoreDash\Credit\Engine
 * @since   1.17.0
 */

namespace StoreDash\Credit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Ledger;

/**
 * Customer key resolution.
 *
 * @since 1.17.0
 */
class Customer_Resolver {

	/**
	 * Key for the currently logged-in shopper ('' for guests).
	 *
	 * Account email only — the checkout billing field is ignored on purpose.
	 *
	 * @return string
	 */
	public static function current_key(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		return self::key_for_user( get_current_user_id() );
	}

	/**
	 * Key for a WordPress user id ('' when unknown). Account email only.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function key_for_user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$user = get_user_by( 'id', $user_id );
		return $user ? Ledger::customer_key( (string) $user->user_email ) : '';
	}

	/**
	 * Key for an order: the customer's account email when the order belongs to
	 * a registered customer, otherwise the guest's billing email.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function key_for_order( $order ): string {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			$key = self::key_for_user( $customer_id );
			if ( '' !== $key ) {
				return $key;
			}
		}
		return Ledger::customer_key( (string) $order->get_billing_email() );
	}

	/**
	 * User id for an email, or null.
	 *
	 * @param string $email Email.
	 * @return int|null
	 */
	public static function user_id_for_email( string $email ) {
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : null;
	}
}
