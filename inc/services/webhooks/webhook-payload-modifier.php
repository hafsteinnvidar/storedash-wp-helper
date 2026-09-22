<?php

/**
 * Webhook Payload Modifier for Storedash
 * Modifies WooCommerce webhook payloads to include additional data like order notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreDash_Webhook_Payload_Modifier {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter( 'woocommerce_webhook_payload', array( $this, 'modify_webhook_payload' ), 10, 4 );
	}

	/**
	 * Modify webhook payload to include additional data
	 *
	 * @param array  $payload The webhook payload
	 * @param string $resource The resource type (e.g., 'order', 'product')
	 * @param int    $resource_id The resource ID
	 * @param string $webhook_id The webhook ID
	 * @return array Modified payload
	 */
	public function modify_webhook_payload( $payload, $resource, $resource_id, $webhook_id ) {
		// Only modify order payloads
		if ( $resource !== 'order' ) {
			return $payload;
		}

		// Order notes can contain private, internal admin notes. Only enrich
		// StoreDash-bound webhooks — never leak internal notes to a merchant's other
		// (third-party) webhook destinations.
		if ( ! $this->is_storedash_destination( $webhook_id ) ) {
			return $payload;
		}

		// Add order notes to the payload
		$payload['notes'] = $this->get_order_notes( $resource_id );

		return $payload;
	}

	/**
	 * Whether a webhook delivers to a StoreDash-controlled endpoint.
	 *
	 * @param int|string $webhook_id The webhook being delivered.
	 * @return bool
	 */
	private function is_storedash_destination( $webhook_id ) {
		if ( ! function_exists( 'wc_get_webhook' ) ) {
			return false;
		}

		$webhook = wc_get_webhook( $webhook_id );
		if ( ! $webhook ) {
			return false;
		}

		$host = wp_parse_url( $webhook->get_delivery_url(), PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}
		$host = strtolower( $host );

		// Default: any host under a storedash.* domain (covers storedash.io,
		// storedash.app, and storedash-*.ondigitalocean.app). Filterable so a
		// self-hosted StoreDash receiver can opt in.
		$is_storedash = ( false !== strpos( $host, 'storedash' ) );

		return (bool) apply_filters( 'storedash_webhook_is_storedash_destination', $is_storedash, $host, $webhook );
	}

	/**
	 * Get formatted order notes
	 *
	 * @param int $order_id The order ID
	 * @return array Formatted order notes
	 */
	private function get_order_notes( $order_id ) {
		$notes = wc_get_order_notes( array( 'order_id' => $order_id ) );

		// Resolve added_by logins to user IDs and prime the WP user cache
		$login_to_id = array();
		$logins      = array();
		foreach ( $notes as $note ) {
			if ( ! empty( $note->added_by ) && 'system' !== $note->added_by ) {
				$logins[] = $note->added_by;
			}
		}
		$logins = array_unique( $logins );
		if ( ! empty( $logins ) ) {
			$users = get_users(
				array(
					'login__in' => $logins,
					'fields'    => 'all',
				)
			);
			$ids   = array();
			foreach ( $users as $u ) {
				$login_to_id[ $u->user_login ] = $u->ID;
				$ids[]                         = $u->ID;
			}
			if ( ! empty( $ids ) ) {
				cache_users( $ids );
			}
		}

		return array_map(
			function ( $note ) use ( $login_to_id ) {
				// System-generated notes are attributed to 'system' by WooCommerce
				// (wc_get_order_notes sets added_by === 'system'). Use that structural
				// signal rather than scanning note content, which mislabels manual
				// admin notes that happen to mention payment/status/order.
				$is_system_note = empty( $note->added_by ) || 'system' === $note->added_by;

				// Get user info if available (cache already primed above)
				$author = 'WooCommerce';
				if ( ! $is_system_note && isset( $login_to_id[ $note->added_by ] ) ) {
					$user = get_userdata( $login_to_id[ $note->added_by ] );
					if ( $user ) {
						$author = $user->display_name;
					}
				}

				return array(
					'id'             => $note->id,
					'date_created'   => $this->format_date( $note->date_created ),
					'note'           => $note->content,
					'customer_note'  => (bool) $note->customer_note,
					'author'         => $author,
					'is_system_note' => $is_system_note,
				);
			},
			$notes
		);
	}

	/**
	 * Format a date to ISO 8601
	 *
	 * @param mixed $date The date to format
	 * @return string|null Formatted date or null
	 */
	private function format_date( $date ) {
		if ( ! $date ) {
			return null;
		}
		if ( is_string( $date ) ) {
			return $date;
		}
		// Handle DateTime object
		if ( $date instanceof \DateTime || $date instanceof \WC_DateTime ) {
			return $date->format( 'c' ); // ISO 8601
		}
		// Handle timestamp
		if ( is_numeric( $date ) ) {
			return gmdate( 'c', $date );
		}
		// Handle array/object
		if ( is_array( $date ) || is_object( $date ) ) {
			$date = (array) $date;
			if ( isset( $date['date'] ) ) {
				return ( new \DateTime( $date['date'] ) )->format( 'c' );
			}
		}
		return null;
	}
}

// Initialize the webhook payload modifier
new StoreDash_Webhook_Payload_Modifier();
