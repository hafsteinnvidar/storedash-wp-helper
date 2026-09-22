<?php
/**
 * StoreDash Helper Functions
 *
 * Global helper functions for the plugin.
 *
 * @package StoreDash
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the cart table name - supports both legacy (woodash) and new (storedash) table names
 *
 * @return string The cart table name to use
 */
function storedash_get_cart_table_name() {
	global $wpdb;
	static $table_name = null;

	// Cache the result
	if ( $table_name !== null ) {
		return $table_name;
	}

	// Check if legacy table exists
	$legacy_table = $wpdb->prefix . 'woodash_carts';
	$new_table    = $wpdb->prefix . 'storedash_carts';

	// Query to check if table exists
	$legacy_exists = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
			DB_NAME,
			$legacy_table
		)
	);

	// Use legacy table if it exists, otherwise use new table name
	$table_name = $legacy_exists ? $legacy_table : $new_table;

	return $table_name;
}
