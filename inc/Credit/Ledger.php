<?php
/**
 * Rewards credit ledger.
 *
 * The ledger table is the source of truth for customer balances. Credit-holding
 * rows (earn, manual, migrate, and positive reverse rows created when a refund
 * is returned as credit) carry a `remaining` column that is decremented FIFO
 * (soonest expiry first, then oldest) by spend / adjust / reverse / expire rows,
 * which carry `remaining = NULL` and record the touched rows in JSON `meta`.
 *
 * Balance = SUM(remaining) of unexpired credit-holding rows — so correctness
 * never depends on the expiry cron having run.
 *
 * Every write is idempotent via the UNIQUE `idem_key` column: a second call with
 * the same key returns the existing row and touches nothing.
 *
 * Storage access goes through the protected `db_*` primitives so the FIFO /
 * idempotency logic can be unit-tested against an in-memory double.
 *
 * @package StoreDash\Credit
 * @since   1.17.0
 */

namespace StoreDash\Credit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Ledger operations.
 *
 * @since 1.17.0
 */
class Ledger {

	/**
	 * Row types (contract B).
	 */
	const TYPE_EARN    = 'earn';
	const TYPE_SPEND   = 'spend';
	const TYPE_REVERSE = 'reverse';
	const TYPE_EXPIRE  = 'expire';
	const TYPE_MANUAL  = 'manual';
	const TYPE_ADJUST  = 'adjust';
	const TYPE_MIGRATE = 'migrate';

	/**
	 * Table name (with prefix).
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Currency code written on new rows.
	 *
	 * @var string
	 */
	protected $currency;

	/**
	 * Constructor.
	 *
	 * @param string|null $currency Currency code (defaults to the store currency).
	 */
	public function __construct( $currency = null ) {
		global $wpdb;
		$this->table    = isset( $wpdb->prefix ) ? $wpdb->prefix . 'storedash_credit_ledger' : 'storedash_credit_ledger';
		$this->currency = null !== $currency ? (string) $currency : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' );
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'storedash_credit_ledger';
	}

	/**
	 * Normalize an email into the ledger customer key.
	 *
	 * @param string $email Email address.
	 * @return string Lower-cased, trimmed; '' when empty.
	 */
	public static function customer_key( $email ): string {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	protected function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	// ── Reads ─────────────────────────────────────────────────────────────

	/**
	 * Spendable balance for a customer.
	 *
	 * @param string $customer_key Customer key.
	 * @return float
	 */
	public function balance( string $customer_key ): float {
		if ( '' === $customer_key ) {
			return 0.0;
		}
		return round( $this->db_sum_open( $customer_key, $this->now() ), 4 );
	}

	/**
	 * Soonest-expiring open credit for a customer.
	 *
	 * @param string $customer_key Customer key.
	 * @return array|null { amount: float, at: string (UTC MySQL) } or null.
	 */
	public function expiring_next( string $customer_key ) {
		foreach ( $this->open_rows( $customer_key ) as $row ) {
			if ( ! empty( $row->expires_at ) ) {
				return array(
					'amount' => (float) $row->remaining,
					'at'     => (string) $row->expires_at,
				);
			}
		}
		return null;
	}

	/**
	 * Open (unexpired, remaining > 0) credit rows, FIFO order.
	 *
	 * @param string $customer_key Customer key.
	 * @return object[]
	 */
	public function open_rows( string $customer_key ): array {
		if ( '' === $customer_key ) {
			return array();
		}
		return $this->db_open_rows( $customer_key, $this->now(), false );
	}

	/**
	 * Latest ledger entries for a customer.
	 *
	 * @param string $customer_key Customer key.
	 * @param int    $limit        Max rows.
	 * @return object[]
	 */
	public function entries( string $customer_key, int $limit = 50 ): array {
		if ( '' === $customer_key ) {
			return array();
		}
		return $this->db_rows_for_customer( $customer_key, $limit );
	}

	/**
	 * Reconciliation feed: rows created since a moment, oldest first.
	 *
	 * @param string $since_utc UTC MySQL datetime ('' = beginning of time).
	 * @param int    $page      1-based page.
	 * @param int    $per_page  Page size.
	 * @return array { rows: object[], total: int }
	 */
	public function feed( string $since_utc, int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 500, $per_page ) );
		return array(
			'rows'  => $this->db_rows_since( $since_utc, ( $page - 1 ) * $per_page, $per_page ),
			'total' => $this->db_count_since( $since_utc ),
		);
	}

	/**
	 * Fetch a row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public function get( int $id ) {
		return $this->db_get( $id );
	}

	/**
	 * Fetch a row by idempotency key.
	 *
	 * @param string $idem_key Idempotency key.
	 * @return object|null
	 */
	public function find_by_idem( string $idem_key ) {
		return $this->db_find_by_idem( $idem_key );
	}

	// ── Writes ────────────────────────────────────────────────────────────

	/**
	 * Add a credit-holding row (earn / manual / migrate / refund-as-credit).
	 *
	 * @param array $args {
	 *     @type string      $customer_key Required.
	 *     @type string      $idem_key     Required.
	 *     @type float       $amount       Required, > 0.
	 *     @type string      $type         earn|manual|migrate|reverse (default earn).
	 *     @type int|null    $user_id
	 *     @type int|null    $order_id
	 *     @type int|null    $refund_id
	 *     @type string|null $rule_id
	 *     @type string|null $note
	 *     @type string|null $expires_at   UTC MySQL datetime or null.
	 *     @type array       $meta
	 * }
	 * @return array|WP_Error { row: object, affected: array, created: bool }
	 */
	public function add_credit( array $args ) {
		$amount = round( (float) ( $args['amount'] ?? 0 ), 4 );
		if ( empty( $args['customer_key'] ) || empty( $args['idem_key'] ) ) {
			return new WP_Error( 'storedash_credit_invalid', 'customer_key and idem_key are required' );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'storedash_credit_invalid_amount', 'Amount must be greater than zero' );
		}

		$existing = $this->db_find_by_idem( $args['idem_key'] );
		if ( $existing ) {
			return $this->result( $existing, array(), false );
		}

		$type = $args['type'] ?? self::TYPE_EARN;
		if ( ! in_array( $type, array( self::TYPE_EARN, self::TYPE_MANUAL, self::TYPE_MIGRATE, self::TYPE_REVERSE ), true ) ) {
			$type = self::TYPE_EARN;
		}

		$id = $this->db_insert( $this->new_row( $args, $type, $amount, $amount ) );
		if ( ! $id ) {
			// UNIQUE(idem_key) race: another request inserted first.
			$existing = $this->db_find_by_idem( $args['idem_key'] );
			return $existing ? $this->result( $existing, array(), false ) : new WP_Error( 'storedash_credit_db', 'Failed to insert ledger row' );
		}

		return $this->result( $this->db_get( $id ), array(), true );
	}

	/**
	 * Consume credit FIFO (spend at checkout, or a negative manual adjustment).
	 *
	 * Runs inside a transaction with the open rows locked (SELECT … FOR UPDATE),
	 * so two concurrent checkouts cannot both spend the same credit.
	 *
	 * @param string $customer_key Customer key.
	 * @param float  $amount       Positive amount to consume.
	 * @param array  $args         idem_key (required), type (spend|adjust), user_id, order_id, note, meta.
	 * @return array|WP_Error { row, affected: [{id, remaining}], created }.
	 *                        WP_Error 'storedash_credit_insufficient' when balance < amount.
	 */
	public function consume( string $customer_key, float $amount, array $args ) {
		$amount = round( $amount, 4 );
		if ( '' === $customer_key || empty( $args['idem_key'] ) ) {
			return new WP_Error( 'storedash_credit_invalid', 'customer_key and idem_key are required' );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'storedash_credit_invalid_amount', 'Amount must be greater than zero' );
		}

		$type = ( isset( $args['type'] ) && self::TYPE_ADJUST === $args['type'] ) ? self::TYPE_ADJUST : self::TYPE_SPEND;

		$existing = $this->db_find_by_idem( $args['idem_key'] );
		if ( $existing ) {
			return $this->result( $existing, $this->affected_from_meta( $existing ), false );
		}

		$this->db_begin();
		try {
			$existing = $this->db_find_by_idem( $args['idem_key'] );
			if ( $existing ) {
				$this->db_commit();
				return $this->result( $existing, $this->affected_from_meta( $existing ), false );
			}

			$open      = $this->db_open_rows( $customer_key, $this->now(), true );
			$available = 0.0;
			foreach ( $open as $row ) {
				$available += (float) $row->remaining;
			}

			if ( ! Money::gte( $available, $amount ) ) {
				$this->db_rollback();
				return new WP_Error(
					'storedash_credit_insufficient',
					'Insufficient rewards credit',
					array(
						'available' => round( $available, 4 ),
						'requested' => $amount,
					)
				);
			}

			$allocation = self::allocate_fifo( $open, $amount );
			$affected   = array();
			foreach ( $allocation as $row_id => $taken ) {
				$new_remaining = round( (float) $this->open_row_remaining( $open, $row_id ) - $taken, 4 );
				$this->db_update_remaining( (int) $row_id, max( 0.0, $new_remaining ) );
				$affected[] = array(
					'id'        => (int) $row_id,
					'remaining' => max( 0.0, $new_remaining ),
				);
			}

			$meta                 = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
			$meta['allocated']    = $allocation;
			$args['meta']         = $meta;
			$args['customer_key'] = $customer_key;

			$id = $this->db_insert( $this->new_row( $args, $type, -$amount, null ) );
			if ( ! $id ) {
				$this->db_rollback();
				$existing = $this->db_find_by_idem( $args['idem_key'] );
				return $existing ? $this->result( $existing, $this->affected_from_meta( $existing ), false ) : new WP_Error( 'storedash_credit_db', 'Failed to insert ledger row' );
			}

			$this->db_commit();
			return $this->result( $this->db_get( $id ), $affected, true );
		} catch ( \Throwable $e ) {
			$this->db_rollback();
			return new WP_Error( 'storedash_credit_db', $e->getMessage() );
		}
	}

	/**
	 * Release the credit consumed by an order (cancelled / failed order).
	 *
	 * Restores `remaining` on exactly the rows the spend row took from (rows
	 * that have since expired are not restored — that credit is gone) and
	 * writes a positive `reverse` row with `remaining = NULL`.
	 *
	 * @param int    $order_id Order id.
	 * @param string $idem_key Idempotency key (release:{order_id}[:n]).
	 * @param array  $args     user_id, note.
	 * @return array|WP_Error|null { row, affected, created }; null when nothing to release.
	 */
	public function release_order( int $order_id, string $idem_key, array $args = array() ) {
		$existing = $this->db_find_by_idem( $idem_key );
		if ( $existing ) {
			return $this->result( $existing, $this->affected_from_meta( $existing ), false );
		}

		$spend = $this->latest_unreleased_spend( $order_id );
		if ( ! $spend ) {
			return null;
		}

		$this->db_begin();
		try {
			$allocation = $this->affected_from_meta( $spend, 'allocated' );
			$affected   = array();
			$restored   = 0.0;
			$now        = $this->now();

			foreach ( $allocation as $row_id => $taken ) {
				$earn = $this->db_get( (int) $row_id, true );
				if ( ! $earn ) {
					continue;
				}
				if ( ! empty( $earn->expires_at ) && $earn->expires_at <= $now ) {
					continue; // Expired meanwhile — cannot come back.
				}
				$new_remaining = round( (float) $earn->remaining + (float) $taken, 4 );
				$this->db_update_remaining( (int) $row_id, $new_remaining );
				$affected[] = array(
					'id'        => (int) $row_id,
					'remaining' => $new_remaining,
				);
				$restored  += (float) $taken;
			}

			$row_args = array(
				'customer_key' => $spend->customer_key,
				'user_id'      => $args['user_id'] ?? $spend->user_id,
				'order_id'     => $order_id,
				'idem_key'     => $idem_key,
				'note'         => $args['note'] ?? null,
				'meta'         => array(
					'released_spend_id' => (int) $spend->id,
					'allocated'         => $allocation,
					'restored'          => $restored,
				),
			);

			$id = $this->db_insert( $this->new_row( $row_args, self::TYPE_REVERSE, abs( (float) $spend->amount ), null ) );
			if ( ! $id ) {
				$this->db_rollback();
				$existing = $this->db_find_by_idem( $idem_key );
				return $existing ? $this->result( $existing, $this->affected_from_meta( $existing ), false ) : new WP_Error( 'storedash_credit_db', 'Failed to insert ledger row' );
			}

			$spend_meta             = $this->decode_meta( $spend );
			$spend_meta['released'] = (int) $id;
			$this->db_update_meta( (int) $spend->id, $spend_meta );

			$this->db_commit();
			return $this->result( $this->db_get( $id ), $affected, true );
		} catch ( \Throwable $e ) {
			$this->db_rollback();
			return new WP_Error( 'storedash_credit_db', $e->getMessage() );
		}
	}

	/**
	 * Claw back part of an earned row (refund).
	 *
	 * Decrements the earn row's `remaining` (capped at what is left) and writes a
	 * negative `reverse` row.
	 *
	 * @param int    $earn_row_id Earn row id.
	 * @param float  $amount      Requested claw-back (positive).
	 * @param string $idem_key    Idempotency key (reverse:{refund_id}).
	 * @param array  $args        refund_id, order_id, user_id, note.
	 * @return array|WP_Error|null { row, affected, created }; null when nothing left to reverse.
	 */
	public function reverse_earn( int $earn_row_id, float $amount, string $idem_key, array $args = array() ) {
		$existing = $this->db_find_by_idem( $idem_key );
		if ( $existing ) {
			return $this->result( $existing, $this->affected_from_meta( $existing ), false );
		}

		$this->db_begin();
		try {
			$earn = $this->db_get( $earn_row_id, true );
			if ( ! $earn || null === $earn->remaining ) {
				$this->db_rollback();
				return null;
			}
			$take = round( min( (float) $earn->remaining, max( 0.0, $amount ) ), 4 );
			if ( $take <= 0 ) {
				$this->db_rollback();
				return null;
			}

			$new_remaining = round( (float) $earn->remaining - $take, 4 );
			$this->db_update_remaining( $earn_row_id, $new_remaining );

			$row_args = array(
				'customer_key' => $earn->customer_key,
				'user_id'      => $args['user_id'] ?? $earn->user_id,
				'order_id'     => $args['order_id'] ?? $earn->order_id,
				'refund_id'    => $args['refund_id'] ?? null,
				'rule_id'      => $earn->rule_id,
				'idem_key'     => $idem_key,
				'note'         => $args['note'] ?? null,
				'meta'         => array( 'allocated' => array( $earn_row_id => $take ) ),
			);

			$id = $this->db_insert( $this->new_row( $row_args, self::TYPE_REVERSE, -$take, null ) );
			if ( ! $id ) {
				$this->db_rollback();
				$existing = $this->db_find_by_idem( $idem_key );
				return $existing ? $this->result( $existing, $this->affected_from_meta( $existing ), false ) : new WP_Error( 'storedash_credit_db', 'Failed to insert ledger row' );
			}

			$this->db_commit();
			return $this->result(
				$this->db_get( $id ),
				array(
					array(
						'id'        => $earn_row_id,
						'remaining' => $new_remaining,
					),
				),
				true
			);
		} catch ( \Throwable $e ) {
			$this->db_rollback();
			return new WP_Error( 'storedash_credit_db', $e->getMessage() );
		}
	}

	/**
	 * Expire every credit-holding row whose expiry has passed.
	 *
	 * Bookkeeping only — the balance query already ignores expired rows. Writes
	 * one `expire` row per earn row (idem expire:{earn_id}) and zeroes `remaining`.
	 *
	 * @return array[] List of { row, affected, created } for rows expired in this run.
	 */
	public function expire_due(): array {
		$results = array();
		$now     = $this->now();

		foreach ( $this->db_due_rows( $now ) as $due ) {
			$idem     = 'expire:' . (int) $due->id;
			$existing = $this->db_find_by_idem( $idem );
			if ( $existing ) {
				// Expire row already written (previous run died mid-way): self-heal remaining.
				$this->db_update_remaining( (int) $due->id, 0.0 );
				continue;
			}

			$remaining = round( (float) $due->remaining, 4 );
			if ( $remaining <= 0 ) {
				continue;
			}

			$this->db_begin();
			try {
				$this->db_update_remaining( (int) $due->id, 0.0 );
				$id = $this->db_insert(
					$this->new_row(
						array(
							'customer_key' => $due->customer_key,
							'user_id'      => $due->user_id,
							'order_id'     => $due->order_id,
							'rule_id'      => $due->rule_id,
							'idem_key'     => $idem,
							'meta'         => array( 'allocated' => array( (int) $due->id => $remaining ) ),
						),
						self::TYPE_EXPIRE,
						-$remaining,
						null
					)
				);
				if ( ! $id ) {
					$this->db_rollback();
					continue;
				}
				$this->db_commit();
				$results[] = $this->result(
					$this->db_get( $id ),
					array(
						array(
							'id'        => (int) $due->id,
							'remaining' => 0.0,
						),
					),
					true
				);
			} catch ( \Throwable $e ) {
				$this->db_rollback();
			}
		}

		return $results;
	}

	// ── Pure helpers ──────────────────────────────────────────────────────

	/**
	 * FIFO allocation of an amount across open rows.
	 *
	 * Pure function — unit tested. Rows must already be in FIFO order.
	 *
	 * @param object[] $rows   Open rows with `id` and `remaining`.
	 * @param float    $amount Amount to allocate.
	 * @return array Map of row id => amount taken (only rows that were touched).
	 */
	public static function allocate_fifo( array $rows, float $amount ): array {
		$left       = round( $amount, 4 );
		$allocation = array();

		foreach ( $rows as $row ) {
			if ( $left <= Money::EPSILON ) {
				break;
			}
			$remaining = (float) $row->remaining;
			if ( $remaining <= 0 ) {
				continue;
			}
			$take = round( min( $remaining, $left ), 4 );
			if ( $take <= 0 ) {
				continue;
			}
			$allocation[ (int) $row->id ] = $take;
			$left                         = round( $left - $take, 4 );
		}

		return $allocation;
	}

	/**
	 * Read the `allocated` (or any) map out of a row's JSON meta as [{id, remaining}]
	 * or as the raw id => amount map.
	 *
	 * @param object $row Ledger row.
	 * @param string $key 'affected' returns [{id, remaining}] shape derived from the
	 *                    stored allocation; 'allocated' returns id => taken map.
	 * @return array
	 */
	protected function affected_from_meta( $row, string $key = 'affected' ): array {
		$meta      = $this->decode_meta( $row );
		$allocated = isset( $meta['allocated'] ) && is_array( $meta['allocated'] ) ? $meta['allocated'] : array();

		if ( 'allocated' === $key ) {
			$out = array();
			foreach ( $allocated as $id => $taken ) {
				$out[ (int) $id ] = (float) $taken;
			}
			return $out;
		}

		// Re-read current remaining for the touched rows (idempotent replays
		// report the live state, never a stale snapshot).
		$out = array();
		foreach ( $allocated as $id => $taken ) {
			$earn  = $this->db_get( (int) $id );
			$out[] = array(
				'id'        => (int) $id,
				'remaining' => $earn ? (float) $earn->remaining : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Decode a row's JSON meta.
	 *
	 * @param object $row Ledger row.
	 * @return array
	 */
	protected function decode_meta( $row ): array {
		if ( empty( $row->meta ) ) {
			return array();
		}
		$decoded = is_array( $row->meta ) ? $row->meta : json_decode( (string) $row->meta, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * The most recent spend row for an order that has not been released.
	 *
	 * @param int $order_id Order id.
	 * @return object|null
	 */
	protected function latest_unreleased_spend( int $order_id ) {
		$rows = $this->db_spend_rows_for_order( $order_id );
		$last = null;
		foreach ( $rows as $row ) {
			$meta = $this->decode_meta( $row );
			if ( empty( $meta['released'] ) ) {
				$last = $row;
			}
		}
		return $last;
	}

	/**
	 * Remaining of a row in an already-fetched open-rows list.
	 *
	 * @param object[] $open   Open rows.
	 * @param int      $row_id Row id.
	 * @return float
	 */
	private function open_row_remaining( array $open, $row_id ): float {
		foreach ( $open as $row ) {
			if ( (int) $row->id === (int) $row_id ) {
				return (float) $row->remaining;
			}
		}
		return 0.0;
	}

	/**
	 * Build a row array for insertion.
	 *
	 * @param array      $args      Caller args.
	 * @param string     $type      Row type.
	 * @param float      $amount    Signed amount.
	 * @param float|null $remaining Remaining (credit-holding rows) or null.
	 * @return array
	 */
	protected function new_row( array $args, string $type, float $amount, $remaining ): array {
		$expires_at = $args['expires_at'] ?? null;
		return array(
			'customer_key' => (string) $args['customer_key'],
			'user_id'      => ! empty( $args['user_id'] ) ? (int) $args['user_id'] : null,
			'type'         => $type,
			'amount'       => round( $amount, 4 ),
			'remaining'    => null === $remaining ? null : round( $remaining, 4 ),
			'currency'     => $this->currency,
			'order_id'     => ! empty( $args['order_id'] ) ? (int) $args['order_id'] : null,
			'refund_id'    => ! empty( $args['refund_id'] ) ? (int) $args['refund_id'] : null,
			'rule_id'      => ! empty( $args['rule_id'] ) ? substr( (string) $args['rule_id'], 0, 36 ) : null,
			'note'         => isset( $args['note'] ) && '' !== $args['note'] ? (string) $args['note'] : null,
			'meta'         => isset( $args['meta'] ) && is_array( $args['meta'] ) && ! empty( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : null,
			'expires_at'   => ( is_string( $expires_at ) && '' !== $expires_at ) ? $expires_at : null,
			'idem_key'     => substr( (string) $args['idem_key'], 0, 191 ),
			'created_at'   => $this->now(),
		);
	}

	/**
	 * Uniform result shape.
	 *
	 * @param object $row      Ledger row.
	 * @param array  $affected Affected rows [{id, remaining}].
	 * @param bool   $created  Whether this call created the row.
	 * @return array
	 */
	protected function result( $row, array $affected, bool $created ): array {
		return array(
			'row'      => $row,
			'affected' => $affected,
			'created'  => $created,
		);
	}

	// ── Storage primitives ($wpdb) — overridden by the unit-test double ─────

	/**
	 * Find by idempotency key.
	 *
	 * @param string $idem_key Key.
	 * @return object|null
	 */
	protected function db_find_by_idem( string $idem_key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE idem_key = %s", $idem_key ) );
		return $row ? $row : null;
	}

	/**
	 * Fetch by id, optionally locking the row.
	 *
	 * @param int  $id   Row id.
	 * @param bool $lock SELECT … FOR UPDATE (inside a transaction).
	 * @return object|null
	 */
	protected function db_get( int $id, bool $lock = false ) {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal; suffix is a literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d{$suffix}", $id ) );
		return $row ? $row : null;
	}

	/**
	 * Insert a row.
	 *
	 * @param array $row Column => value.
	 * @return int Inserted id, 0 on failure.
	 */
	protected function db_insert( array $row ): int {
		global $wpdb;
		$formats = array();
		foreach ( $row as $key => $value ) {
			if ( null === $value ) {
				$formats[] = null; // wpdb::insert writes NULL for null values.
			} elseif ( in_array( $key, array( 'user_id', 'order_id', 'refund_id' ), true ) ) {
				$formats[] = '%d';
			} elseif ( in_array( $key, array( 'amount', 'remaining' ), true ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}
		$ok = $wpdb->insert( $this->table, $row, $formats );
		if ( false === $ok ) {
			\StoreDash_Helpers::log_message( 'Rewards credit ledger insert failed: ' . $wpdb->last_error, 'error', array( 'idem_key' => $row['idem_key'] ?? '' ) );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a row's remaining.
	 *
	 * @param int   $id        Row id.
	 * @param float $remaining New remaining.
	 */
	protected function db_update_remaining( int $id, float $remaining ): void {
		global $wpdb;
		$wpdb->update( $this->table, array( 'remaining' => round( $remaining, 4 ) ), array( 'id' => $id ), array( '%f' ), array( '%d' ) );
	}

	/**
	 * Replace a row's JSON meta.
	 *
	 * @param int   $id   Row id.
	 * @param array $meta Meta.
	 */
	protected function db_update_meta( int $id, array $meta ): void {
		global $wpdb;
		$wpdb->update( $this->table, array( 'meta' => wp_json_encode( $meta ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Open credit-holding rows in FIFO order.
	 *
	 * @param string $customer_key Customer key.
	 * @param string $now          UTC MySQL datetime.
	 * @param bool   $lock         SELECT … FOR UPDATE.
	 * @return object[]
	 */
	protected function db_open_rows( string $customer_key, string $now, bool $lock ): array {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal; suffix is a literal.
				"SELECT * FROM {$this->table}
				WHERE customer_key = %s AND remaining IS NOT NULL AND remaining > 0
				AND (expires_at IS NULL OR expires_at > %s)
				ORDER BY (expires_at IS NULL) ASC, expires_at ASC, created_at ASC, id ASC{$suffix}",
				$customer_key,
				$now
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sum of open credit for a customer.
	 *
	 * @param string $customer_key Customer key.
	 * @param string $now          UTC MySQL datetime.
	 * @return float
	 */
	protected function db_sum_open( string $customer_key, string $now ): float {
		global $wpdb;
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
				"SELECT COALESCE(SUM(remaining), 0) FROM {$this->table}
				WHERE customer_key = %s AND remaining IS NOT NULL AND remaining > 0
				AND (expires_at IS NULL OR expires_at > %s)",
				$customer_key,
				$now
			)
		);
		return (float) $sum;
	}

	/**
	 * Credit-holding rows whose expiry has passed but still hold credit.
	 *
	 * @param string $now UTC MySQL datetime.
	 * @return object[]
	 */
	protected function db_due_rows( string $now ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
				"SELECT * FROM {$this->table}
				WHERE remaining IS NOT NULL AND remaining > 0 AND expires_at IS NOT NULL AND expires_at <= %s
				ORDER BY expires_at ASC, id ASC LIMIT 500",
				$now
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Latest rows for a customer.
	 *
	 * @param string $customer_key Customer key.
	 * @param int    $limit        Max rows.
	 * @return object[]
	 */
	protected function db_rows_for_customer( string $customer_key, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
				"SELECT * FROM {$this->table} WHERE customer_key = %s ORDER BY id DESC LIMIT %d",
				$customer_key,
				max( 1, $limit )
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows created since a moment (oldest first, paged).
	 *
	 * @param string $since_utc UTC MySQL datetime or ''.
	 * @param int    $offset    Offset.
	 * @param int    $limit     Limit.
	 * @return object[]
	 */
	protected function db_rows_since( string $since_utc, int $offset, int $limit ): array {
		global $wpdb;
		if ( '' === $since_utc ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ) );
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
					"SELECT * FROM {$this->table} WHERE created_at >= %s ORDER BY id ASC LIMIT %d OFFSET %d",
					$since_utc,
					$limit,
					$offset
				)
			);
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows created since a moment.
	 *
	 * @param string $since_utc UTC MySQL datetime or ''.
	 * @return int
	 */
	protected function db_count_since( string $since_utc ): int {
		global $wpdb;
		if ( '' === $since_utc ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE created_at >= %s", $since_utc ) );
	}

	/**
	 * Spend rows for an order (oldest first).
	 *
	 * @param int $order_id Order id.
	 * @return object[]
	 */
	protected function db_spend_rows_for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
				"SELECT * FROM {$this->table} WHERE order_id = %d AND type = %s ORDER BY id ASC",
				$order_id,
				self::TYPE_SPEND
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sum of positive `reverse` rows (refund returned as credit) for an order.
	 *
	 * @param int $order_id Order id.
	 * @return float
	 */
	public function refunded_as_credit_total( int $order_id ): float {
		return $this->db_sum_refund_credit( $order_id );
	}

	/**
	 * Storage for refunded_as_credit_total().
	 *
	 * @param int $order_id Order id.
	 * @return float
	 */
	protected function db_sum_refund_credit( int $order_id ): float {
		global $wpdb;
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
				"SELECT COALESCE(SUM(amount), 0) FROM {$this->table}
				WHERE order_id = %d AND type = %s AND remaining IS NOT NULL AND idem_key LIKE %s",
				$order_id,
				self::TYPE_REVERSE,
				'refund_credit:%'
			)
		);
		return (float) $sum;
	}

	/**
	 * Begin a transaction.
	 */
	protected function db_begin(): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Commit.
	 */
	protected function db_commit(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Roll back.
	 */
	protected function db_rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}
}
