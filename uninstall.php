<?php
/**
 * StoreDash Uninstall
 *
 * Fired when the plugin is uninstalled.
 * Removes all plugin data from the database.
 *
 * @package StoreDash
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Only run if user has proper permissions
if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

// Check if user has opted into clean uninstall
$clean_uninstall = get_option( 'storedash_clean_uninstall', false );
if ( ! $clean_uninstall ) {
	return;
}

// Drop every table this plugin creates (both legacy woodash_ and current
// storedash_ names), including tables only created on older versions
// (storedash_webhook_logs is dropped by a bootstrap migration but may still
// exist). The enquiry and customer-tokens tables hold customer PII / auth
// session material and MUST be dropped on a clean uninstall. Any change to
// StoreDash_Activator table creation MUST be mirrored here.
$tables = array(
	$wpdb->prefix . 'woodash_carts',
	$wpdb->prefix . 'storedash_carts',
	$wpdb->prefix . 'storedash_discounts',
	$wpdb->prefix . 'storedash_discount_products',
	$wpdb->prefix . 'storedash_product_waitlist',
	$wpdb->prefix . 'storedash_product_enquiries',
	$wpdb->prefix . 'storedash_customer_tokens',
	$wpdb->prefix . 'storedash_webhook_logs',
	$wpdb->prefix . 'storedash_credit_ledger',
	$wpdb->prefix . 'storedash_credit_rules',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix + literal; DROP has no core API; uninstall context.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Delete ALL plugin options by prefix, rather than a hand-maintained whitelist
// that repeatedly drifted from what the code actually writes. Every option this
// plugin sets is prefixed woodash_ or storedash_. esc_like() escapes the '_' so
// it is a literal prefix match.
$like_woodash   = $wpdb->esc_like( 'woodash_' ) . '%';
$like_storedash = $wpdb->esc_like( 'storedash_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk option cleanup on uninstall; prepared LIKE.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$like_woodash,
		$like_storedash
	)
);

// Clear transients (wildcards)
$wpdb->query(
	"DELETE FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_woodash_%'
    OR option_name LIKE '_transient_timeout_woodash_%'
    OR option_name LIKE '_transient_storedash_%'
    OR option_name LIKE '_transient_timeout_storedash_%'"
);

// Clear scheduled cron jobs (legacy + current names, incl. the removed
// batch-sync event so it is unscheduled on existing installs).
$cron_jobs = array(
	'storedash_check_abandoned_carts',
	'woodash_check_abandoned_carts',
	'storedash_check_discount_schedules',
	'woodash_check_discount_schedules',
	'storedash_sync_discount_usage',
	'woodash_sync_discount_usage',
	'woodash_sync_carts_batch',
	'storedash_sync_recommendation_tracking',
	'woodash_purge_expired_carts',
	'storedash_purge_customer_tokens',
	'storedash_credit_expire',
);

foreach ( $cron_jobs as $job ) {
	wp_clear_scheduled_hook( $job );
}

// Clear WooCommerce Action Scheduler actions for discounts
if ( class_exists( 'WC_Queue' ) ) {
	$queue = WC()->queue();
	$queue->cancel_all( 'storedash_activate_discount' );
	$queue->cancel_all( 'storedash_deactivate_discount' );
	$queue->cancel_all( 'storedash_batch_apply_discount' );
}

// Delete user meta. This plugin writes UNDERSCORE-prefixed keys
// (_woodash_cart_token, _woodash_pending_recovery,
// _woodash_customer_email_opt_out, _woodash_marketing_optin), so the leading
// underscore variants must be matched too — the previous woodash_%/storedash_%
// patterns matched none of them.
$wpdb->query(
	"DELETE FROM {$wpdb->usermeta}
    WHERE meta_key LIKE 'woodash\_%'
    OR meta_key LIKE 'storedash\_%'
    OR meta_key LIKE '\_woodash\_%'
    OR meta_key LIKE '\_storedash\_%'"
);

// Delete the clean uninstall option itself
delete_option( 'storedash_clean_uninstall' );

// Clear any cached data
wp_cache_flush();
