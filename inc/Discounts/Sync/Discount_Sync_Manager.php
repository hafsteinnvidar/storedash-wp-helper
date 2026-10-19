<?php
/**
 * Discount Sync Manager
 *
 * Manages sync between Supabase and WordPress.
 * All discounts now use dynamic pricing (no static sale_price writes).
 *
 * @package StoreDash\Discounts\Sync
 * @since   1.0.0
 */

namespace StoreDash\Discounts\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Manages bidirectional sync between Supabase and WordPress.
 *
 * @since 1.0.0
 * @since 3.0.0 Simplified - no batch processing, all discounts are dynamic.
 */
class Discount_Sync_Manager {

	/**
	 * DB handler instance.
	 *
	 * @since 1.0.0
	 * @var Discount_DB_Handler
	 */
	protected $db_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->db_handler = new Discount_DB_Handler();
	}

	/**
	 * Sync discount from Supabase to WordPress
	 *
	 * Simply stores/updates discount rules in WordPress database.
	 * No batch processing needed - prices are calculated dynamically via hooks.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Simplified - removed batch scheduling.
	 *
	 * @param array $discount_data Discount data from Supabase.
	 * @return array|WP_Error Result with wp_rule_id or error.
	 */
	public function sync_from_supabase( $discount_data ) {
		\StoreDash_Helpers::debug_log( 'sync_from_supabase() called' );

		// Validate required fields
		if ( empty( $discount_data['supabase_id'] ) ) {
			\StoreDash_Helpers::debug_log( 'Missing supabase_id' );
			return new WP_Error(
				'storedash_missing_supabase_id',
				'Supabase ID is required'
			);
		}

		\StoreDash_Helpers::debug_log( 'Looking for existing discount', array( 'supabase_id' => $discount_data['supabase_id'] ) );

		// Check if discount already exists
		$existing = $this->db_handler->get_discount_by_supabase_id( $discount_data['supabase_id'] );
		\StoreDash_Helpers::debug_log(
			'Existing discount check',
			array(
				'found' => $existing ? true : false,
				'id'    => $existing ? $existing->id : null,
			)
		);

		if ( $existing ) {
			// Update existing discount
			$updated = $this->db_handler->update_discount( $existing->id, $discount_data );

			if ( ! $updated ) {
				return new WP_Error(
					'storedash_update_failed',
					'Failed to update discount in database'
				);
			}

			$wp_rule_id = $existing->id;
			$message    = 'Discount updated successfully';
		} else {
			// Insert new discount
			$wp_rule_id = $this->db_handler->insert_discount( $discount_data );

			if ( is_wp_error( $wp_rule_id ) ) {
				return $wp_rule_id;
			}

			$message = 'Discount created successfully';
		}

		// No scheduling: start_date / end_date are enforced at read time by
		// Discount_DB_Handler::get_active_discounts() (UTC_TIMESTAMP() window),
		// so `enabled` is only ever written by the dashboard (sync / toggle).

		\StoreDash_Helpers::debug_log(
			'Discount synced (dynamic pricing - no batch processing)',
			array(
				'wp_rule_id' => $wp_rule_id,
				'rule_type'  => $discount_data['rule_type'] ?? 'unknown',
				'enabled'    => $discount_data['enabled'] ?? false,
			)
		);

		return array(
			'wp_rule_id' => $wp_rule_id,
			'message'    => $message,
		);
	}
}
