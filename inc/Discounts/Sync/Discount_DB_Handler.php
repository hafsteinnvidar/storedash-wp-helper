<?php
declare(strict_types=1);

/**
 * Discount Database Handler
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
 * Handles CRUD operations for discounts in WordPress database.
 *
 * @since 1.0.0
 */
class Discount_DB_Handler {

	/**
	 * Table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $table_name;

	/**
	 * Request-scoped cache for active discounts.
	 *
	 * Keyed by rule_type (or '_all' for null). Avoids repeated DB queries
	 * within the same PHP request (cart recalculation fires multiple times).
	 *
	 * @since 2.1.0
	 * @var array
	 */
	protected static $active_discounts_cache = array();

	/**
	 * Columns that may be written from a sync payload.
	 *
	 * Server-controlled columns (`id`, `created_at`) are intentionally excluded so a
	 * client cannot set or overwrite them; `last_synced_at`/`updated_at` are stamped
	 * by this handler. Any key not in this list is dropped before the query, which
	 * also prevents a malformed payload from producing an "unknown column" SQL error.
	 *
	 * @since 1.3.0
	 * @var string[]
	 */
	private static $writable_columns = array(
		'supabase_id',
		'store_id',
		'name',
		'description',
		'rule_type',
		'discount_type',
		'amount',
		'priority',
		'enabled',
		'rule_config',
		'target_ids',
		'exclude_ids',
		'exclude_category_ids',
		'exclude_tag_ids',
		'exclude_brand_ids',
		'conditions',
		'start_date',
		'end_date',
		'disable_on_sale',
		'disable_lower_priority',
		'disable_with_coupons',
		'apply_to_sale_price',
		'processing_status',
		'processing_progress',
		'processing_error',
		'last_synced_at',
		'updated_at',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'storedash_discounts';
	}

	/**
	 * Restrict a data array to the writable discount columns.
	 *
	 * @since 1.3.0
	 *
	 * @param array $data Candidate column => value pairs.
	 * @return array Filtered to known writable columns only.
	 */
	private function filter_writable_columns( array $data ): array {
		return array_intersect_key( $data, array_flip( self::$writable_columns ) );
	}

	/**
	 * Get discount by ID
	 *
	 * @since 1.0.0
	 *
	 * @param int $discount_id Discount ID.
	 * @return object|null Discount object or null if not found.
	 */
	public function get_discount( $discount_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE id = %d",
				$discount_id
			)
		);
	}

	/**
	 * Get discount by Supabase ID
	 *
	 * @since 1.0.0
	 *
	 * @param string $supabase_id Supabase UUID.
	 * @return object|null Discount object or null if not found.
	 */
	public function get_discount_by_supabase_id( $supabase_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE supabase_id = %s",
				$supabase_id
			)
		);
	}

	/**
	 * Get all active discounts
	 *
	 * @since 1.0.0
	 *
	 * @param string $rule_type Optional. Filter by rule type.
	 * @return array Array of discount objects. Empty array on query error (not cached).
	 */
	public function get_active_discounts( $rule_type = null ) {
		$cache_key = $rule_type ?? '_all';

		if ( isset( self::$active_discounts_cache[ $cache_key ] ) ) {
			return self::$active_discounts_cache[ $cache_key ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$query = "SELECT * FROM {$this->table_name}
				  WHERE enabled = 1
				  AND (start_date IS NULL OR start_date <= UTC_TIMESTAMP())
				  AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())";

		if ( $rule_type ) {
			$query .= $wpdb->prepare( ' AND rule_type = %s', $rule_type );
		}

		$query .= ' ORDER BY priority ASC, id ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is built safely above
		$results = $wpdb->get_results( $query );

		// On a DB error wpdb returns null and sets last_error. Do NOT cache the
		// failure — a null cached here would fatal downstream consumers that
		// usort()/count() the result on every catalog page.
		if ( '' !== $wpdb->last_error ) {
			\StoreDash_Helpers::log_message(
				'Failed to load active discounts',
				'error',
				array(
					'wpdb_error' => $wpdb->last_error,
					'rule_type'  => $rule_type,
				)
			);
			return array();
		}

		$results = is_array( $results ) ? $results : array();

		self::$active_discounts_cache[ $cache_key ] = $results;

		return $results;
	}

	/**
	 * Clear the request-scoped active discounts cache.
	 *
	 * Called after insert/update/delete to ensure stale data isn't served
	 * if discounts are re-read within the same request.
	 *
	 * @since 2.1.0
	 */
	public static function clear_active_discounts_cache() {
		self::$active_discounts_cache = array();
	}

	/**
	 * Get active display discounts (for dynamic price display).
	 *
	 * Display discounts are: product, category, tag, brand, store_wide.
	 * These affect price display on product pages (not cart-only like BOGO/quantity).
	 *
	 * @since 2.0.0
	 *
	 * @return array Array of discount objects. Empty array on query error (not cached).
	 */
	public function get_active_display_discounts() {
		$cache_key = '_display';

		if ( isset( self::$active_discounts_cache[ $cache_key ] ) ) {
			return self::$active_discounts_cache[ $cache_key ];
		}

		global $wpdb;

		$display_types = array( 'product', 'category', 'tag', 'brand', 'store_wide' );
		$placeholders  = implode( ',', array_fill( 0, count( $display_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant), placeholders built from count
		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table_name}
			 WHERE enabled = 1
			 AND rule_type IN ($placeholders)
			 AND (start_date IS NULL OR start_date <= UTC_TIMESTAMP())
			 AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())
			 ORDER BY priority ASC, id ASC",
			...$display_types
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$results = $wpdb->get_results( $query );

		// On a DB error wpdb returns null and sets last_error. Do NOT cache the
		// failure — a null cached here would fatal downstream consumers that
		// usort()/count() the result on every catalog page.
		if ( '' !== $wpdb->last_error ) {
			\StoreDash_Helpers::log_message(
				'Failed to load active display discounts',
				'error',
				array( 'wpdb_error' => $wpdb->last_error )
			);
			return array();
		}

		$results = is_array( $results ) ? $results : array();

		self::$active_discounts_cache[ $cache_key ] = $results;

		return $results;
	}

	/**
	 * Insert discount
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Discount data.
	 * @return int|WP_Error Inserted ID or error.
	 */
	public function insert_discount( $data ) {
		global $wpdb;

		\StoreDash_Helpers::debug_log(
			'insert_discount() called',
			array(
				'table'     => $this->table_name,
				'data_keys' => array_keys( $data ),
			)
		);

		$data['last_synced_at'] = current_time( 'mysql' );

		// Whitelist columns so a sync payload cannot set id/created_at or inject
		// unknown columns.
		$data = $this->filter_writable_columns( $data );

		$result = $wpdb->insert( $this->table_name, $data );

		if ( false === $result ) {
			\StoreDash_Helpers::log_message(
				'Insert failed',
				'error',
				array(
					'wpdb_error' => $wpdb->last_error,
					'last_query' => $wpdb->last_query,
				)
			);
			return new WP_Error(
				'storedash_db_insert_failed',
				'Failed to insert discount into database: ' . $wpdb->last_error
			);
		}

		\StoreDash_Helpers::debug_log( 'Insert successful', array( 'id' => $wpdb->insert_id ) );
		self::clear_active_discounts_cache();
		return $wpdb->insert_id;
	}

	/**
	 * Update discount
	 *
	 * @since 1.0.0
	 *
	 * @param int   $discount_id Discount ID.
	 * @param array $data        Data to update.
	 * @return bool True on success, false on failure.
	 */
	public function update_discount( $discount_id, $data ) {
		global $wpdb;

		$data['updated_at']     = current_time( 'mysql' );
		$data['last_synced_at'] = current_time( 'mysql' );

		// Whitelist columns so a sync payload cannot overwrite id/created_at or
		// inject unknown columns.
		$data = $this->filter_writable_columns( $data );

		$result = $wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $discount_id )
		) !== false;

		self::clear_active_discounts_cache();

		return $result;
	}

	/**
	 * Delete discount
	 *
	 * @since 1.0.0
	 *
	 * @param int $discount_id Discount ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_discount( $discount_id ) {
		global $wpdb;

		$result = $wpdb->delete(
			$this->table_name,
			array( 'id' => $discount_id )
		);

		if ( false === $result ) {
			return false;
		}

		self::clear_active_discounts_cache();
		return true;
	}

	/**
	 * Get status statistics
	 *
	 * @since 1.0.0
	 *
	 * @return array Statistics.
	 */
	public function get_status_stats() {
		global $wpdb;

		$stats = array();

		// Total discounts
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$stats['total'] = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );

		// Active discounts
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$stats['active'] = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name}
			 WHERE enabled = 1
			 AND (start_date IS NULL OR start_date <= UTC_TIMESTAMP())
			 AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())"
		);

		// By type
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe ($wpdb->prefix + constant)
		$stats['by_type'] = $wpdb->get_results(
			"SELECT rule_type, COUNT(*) as count
			 FROM {$this->table_name}
			 GROUP BY rule_type"
		);

		return $stats;
	}
}
