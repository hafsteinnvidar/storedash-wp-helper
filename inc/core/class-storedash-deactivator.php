<?php
/**
 * StoreDash Deactivation Handler
 *
 * Handles plugin deactivation tasks.
 * Note: Deactivation != Uninstall. Data is preserved on deactivation.
 *
 * @package StoreDash
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation handler class
 */
class StoreDash_Deactivator {

	/**
	 * Plugin deactivation tasks
	 *
	 * Clear cron jobs but preserve data. Data is only removed on uninstall.
	 */
	public static function deactivate(): void {
		// Clear cron jobs (both legacy and new names). woodash_sync_carts_batch is
		// included so the removed Cart_Batch_Sync feature's orphaned 5-minute event is
		// unscheduled on existing installs.
		$cron_jobs = array(
			'storedash_check_abandoned_carts',
			'woodash_check_abandoned_carts',
			'storedash_check_discount_schedules',
			'woodash_check_discount_schedules',
			'storedash_sync_discount_usage',
			'woodash_sync_discount_usage',
			'woodash_sync_carts_batch',
			'woodash_purge_expired_carts',
			'storedash_purge_customer_tokens',
			'storedash_credit_expire',
		);

		foreach ( $cron_jobs as $job ) {
			wp_clear_scheduled_hook( $job );
		}

		// Clear transient cache
		wp_cache_delete( 'storedash_active_operations', 'storedash' );

		// Flush rewrite rules
		flush_rewrite_rules();

		// Log deactivation
		if ( class_exists( 'StoreDash_Helpers' ) ) {
			StoreDash_Helpers::log_message( 'Plugin deactivated', 'info' );
		}
	}
}
