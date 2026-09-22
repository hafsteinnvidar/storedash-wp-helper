<?php
/**
 * Rewards credit rules mirror.
 *
 * Rules are owned by Storedash and pushed whole via `POST /credit/sync`; this
 * table is a full-replace mirror keyed by `supabase_id`.
 *
 * @package StoreDash\Credit
 * @since   1.17.0
 */

namespace StoreDash\Credit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for `{prefix}storedash_credit_rules`.
 *
 * @since 1.17.0
 */
class Rules_Repository {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'storedash_credit_rules';
	}

	/**
	 * Normalize a rule payload (contract A) into a row.
	 *
	 * Pure function — unit tested. Returns null when the rule is unusable.
	 *
	 * @param array $raw Rule payload.
	 * @return array|null
	 */
	public static function normalize( array $raw ) {
		$supabase_id = isset( $raw['supabase_id'] ) ? substr( trim( (string) $raw['supabase_id'] ), 0, 36 ) : '';
		if ( '' === $supabase_id ) {
			return null;
		}

		$kind  = ( isset( $raw['kind'] ) && 'fixed' === $raw['kind'] ) ? 'fixed' : 'percent';
		$value = isset( $raw['value'] ) ? (float) $raw['value'] : 0.0;
		if ( $value <= 0 ) {
			return null;
		}

		$conditions = isset( $raw['conditions'] ) && is_array( $raw['conditions'] ) ? $raw['conditions'] : array();
		$conditions = array(
			'min_order_total'  => isset( $conditions['min_order_total'] ) && null !== $conditions['min_order_total'] && '' !== $conditions['min_order_total'] ? (float) $conditions['min_order_total'] : null,
			'max_order_total'  => isset( $conditions['max_order_total'] ) && null !== $conditions['max_order_total'] && '' !== $conditions['max_order_total'] ? (float) $conditions['max_order_total'] : null,
			'first_order_only' => ! empty( $conditions['first_order_only'] ) && Settings::to_bool( $conditions['first_order_only'] ),
			'customer_roles'   => isset( $conditions['customer_roles'] ) && is_array( $conditions['customer_roles'] ) ? array_values( array_filter( array_map( 'strval', $conditions['customer_roles'] ) ) ) : array(),
			'category_ids'     => isset( $conditions['category_ids'] ) && is_array( $conditions['category_ids'] ) ? array_values( array_filter( array_map( 'intval', $conditions['category_ids'] ) ) ) : array(),
			'brand_ids'        => isset( $conditions['brand_ids'] ) && is_array( $conditions['brand_ids'] ) ? array_values( array_filter( array_map( 'intval', $conditions['brand_ids'] ) ) ) : array(),
			'start_date'       => self::to_utc( $conditions['start_date'] ?? null ),
			'end_date'         => self::to_utc( $conditions['end_date'] ?? null ),
		);

		$expiry = isset( $raw['expiry_days'] ) && null !== $raw['expiry_days'] && '' !== $raw['expiry_days'] ? (int) $raw['expiry_days'] : null;

		return array(
			'supabase_id' => $supabase_id,
			'name'        => isset( $raw['name'] ) ? substr( (string) $raw['name'], 0, 191 ) : '',
			'enabled'     => isset( $raw['enabled'] ) ? ( Settings::to_bool( $raw['enabled'] ) ? 1 : 0 ) : 1,
			'priority'    => isset( $raw['priority'] ) ? (int) $raw['priority'] : 0,
			'kind'        => $kind,
			'value'       => $value,
			'conditions'  => $conditions,
			'expiry_days' => ( null === $expiry || $expiry <= 0 ) ? null : $expiry,
		);
	}

	/**
	 * ISO / free-form date → UTC MySQL datetime, or null.
	 *
	 * @param mixed $value Date string.
	 * @return string|null
	 */
	private static function to_utc( $value ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return null;
		}
		$ts = strtotime( $value );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Replace every rule with the given set (full sync).
	 *
	 * @param array $rules Raw rule payloads.
	 * @return int Number of rules stored.
	 */
	public function replace_all( array $rules ): int {
		global $wpdb;
		$table = self::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$count = 0;

		$wpdb->query( 'START TRANSACTION' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		$wpdb->query( "DELETE FROM {$table}" );

		foreach ( $rules as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$rule = self::normalize( $raw );
			if ( ! $rule ) {
				continue;
			}
			$ok = $wpdb->insert(
				$table,
				array(
					'supabase_id' => $rule['supabase_id'],
					'name'        => $rule['name'],
					'enabled'     => $rule['enabled'],
					'priority'    => $rule['priority'],
					'kind'        => $rule['kind'],
					'value'       => $rule['value'],
					'conditions'  => wp_json_encode( $rule['conditions'] ),
					'expiry_days' => $rule['expiry_days'],
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%s', '%d', '%d', '%s', '%f', '%s', null === $rule['expiry_days'] ? null : '%d', '%s', '%s' )
			);
			if ( false === $ok ) {
				\StoreDash_Helpers::log_message( 'Rewards credit rule insert failed: ' . $wpdb->last_error, 'error', array( 'supabase_id' => $rule['supabase_id'] ) );
				continue;
			}
			++$count;
		}

		$wpdb->query( 'COMMIT' );
		return $count;
	}

	/**
	 * Enabled rules with decoded conditions.
	 *
	 * @return array[] Rule arrays (normalize() shape).
	 */
	public function get_enabled(): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY priority DESC, supabase_id ASC" );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$conditions = json_decode( (string) $row->conditions, true );
			$out[]      = array(
				'supabase_id' => (string) $row->supabase_id,
				'name'        => (string) $row->name,
				'enabled'     => (bool) $row->enabled,
				'priority'    => (int) $row->priority,
				'kind'        => (string) $row->kind,
				'value'       => (float) $row->value,
				'conditions'  => is_array( $conditions ) ? $conditions : array(),
				'expiry_days' => ( null === $row->expiry_days || '' === $row->expiry_days ) ? null : (int) $row->expiry_days,
			);
		}
		return $out;
	}

	/**
	 * Total number of stored rules.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
