<?php
/**
 * Gift card store: cards + ledger.
 *
 * `{prefix}storedash_gift_cards` holds one row per card with its live
 * `balance`; `{prefix}storedash_gift_card_ledger` records every movement
 * (issue / spend / release / refund / adjust / disable / enable) with the
 * balance after it. Every balance change locks the card row
 * (SELECT … FOR UPDATE inside a transaction) and writes its ledger row in the
 * same transaction, so the two never drift and two checkouts cannot spend the
 * same money. Every write is idempotent via the UNIQUE ledger `idem_key`.
 *
 * Storage goes through protected `db_*` primitives so the logic is unit
 * tested against an in-memory double (same approach as Credit\Ledger).
 *
 * @package StoreDash\GiftCard
 * @since   1.24.0
 */

namespace StoreDash\GiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Credit\Money;
use WP_Error;

/**
 * Card + ledger operations.
 *
 * @since 1.24.0
 */
class Card_Ledger {

	/**
	 * Ledger row types (contract C).
	 */
	const TYPE_ISSUE   = 'issue';
	const TYPE_SPEND   = 'spend';
	const TYPE_RELEASE = 'release';
	const TYPE_REFUND  = 'refund';
	const TYPE_ADJUST  = 'adjust';
	const TYPE_DISABLE = 'disable';
	const TYPE_ENABLE  = 'enable';

	/**
	 * Card statuses.
	 */
	const STATUS_ACTIVE   = 'active';
	const STATUS_DISABLED = 'disabled';

	/**
	 * Card sources.
	 */
	const SOURCE_PURCHASE = 'purchase';
	const SOURCE_MANUAL   = 'manual';

	/**
	 * Cards table.
	 *
	 * @var string
	 */
	protected $cards_table;

	/**
	 * Ledger table.
	 *
	 * @var string
	 */
	protected $ledger_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$prefix             = isset( $wpdb->prefix ) ? $wpdb->prefix : '';
		$this->cards_table  = $prefix . 'storedash_gift_cards';
		$this->ledger_table = $prefix . 'storedash_gift_card_ledger';
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
	 * Card by id.
	 *
	 * @param int $id Card id.
	 * @return object|null
	 */
	public function get_card( int $id ) {
		return $id > 0 ? $this->db_get_card( $id, false ) : null;
	}

	/**
	 * Card by normalized code.
	 *
	 * @param string $normalized Normalized code.
	 * @return object|null
	 */
	public function find_card_by_code( string $normalized ) {
		if ( ! Code::is_well_formed( $normalized ) ) {
			return null;
		}
		return $this->db_find_card_by_hash( Code::hash( $normalized ) );
	}

	/**
	 * Ledger row by idempotency key.
	 *
	 * @param string $idem_key Key.
	 * @return object|null
	 */
	public function find_by_idem( string $idem_key ) {
		return $this->db_find_by_idem( $idem_key );
	}

	/**
	 * Ledger rows of a card, oldest first.
	 *
	 * @param int $card_id Card id.
	 * @return object[]
	 */
	public function rows_for_card( int $card_id ): array {
		return $this->db_rows_for_card( $card_id );
	}

	/**
	 * Ledger rows of an order (spend / release / refund), oldest first.
	 *
	 * @param int $order_id Order id.
	 * @return object[]
	 */
	public function rows_for_order( int $order_id ): array {
		return $this->db_rows_for_order( $order_id );
	}

	/**
	 * What each card has spent / got back on an order.
	 *
	 * @param int $order_id Order id.
	 * @return array card_id => { spent, released, refunded, outstanding, spends, releases }
	 */
	public function order_summary( int $order_id ): array {
		return self::summarize_order_rows( $this->db_rows_for_order( $order_id ) );
	}

	/**
	 * Cards feed (reconciliation, by updated_at).
	 *
	 * @param string $since_utc UTC MySQL datetime ('' = all).
	 * @param int    $page      1-based page.
	 * @param int    $per_page  Page size.
	 * @return array { rows: object[], total: int }
	 */
	public function cards_feed( string $since_utc, int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 500, $per_page ) );
		return array(
			'rows'  => $this->db_cards_since( $since_utc, ( $page - 1 ) * $per_page, $per_page ),
			'total' => $this->db_count_cards_since( $since_utc ),
		);
	}

	/**
	 * Ledger feed (reconciliation, by created_at).
	 *
	 * @param string $since_utc UTC MySQL datetime ('' = all).
	 * @param int    $page      1-based page.
	 * @param int    $per_page  Page size.
	 * @return array { rows: object[], total: int }
	 */
	public function ledger_feed( string $since_utc, int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 500, $per_page ) );
		return array(
			'rows'  => $this->db_rows_since( $since_utc, ( $page - 1 ) * $per_page, $per_page ),
			'total' => $this->db_count_rows_since( $since_utc ),
		);
	}

	// ── Writes ────────────────────────────────────────────────────────────

	/**
	 * Create a card and its `issue` row.
	 *
	 * @param array  $card     Card fields: initial_amount (required > 0), currency, source,
	 *                         order_id, order_item_id, unit_index, product_id, purchaser_email,
	 *                         recipient_email, recipient_name, sender_name, message, send_at, note.
	 * @param string $idem_key issue:{order_item_id}:{unit_index} or issue:manual:{key}.
	 * @param string $code     Normalized code (generated when '').
	 * @return array|WP_Error { card, row, created, code } — `code` is '' on idempotent replays.
	 */
	public function issue( array $card, string $idem_key, string $code = '' ) {
		$amount = round( (float) ( $card['initial_amount'] ?? 0 ), 4 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'storedash_gift_card_invalid_amount', 'Amount must be greater than zero' );
		}
		if ( '' === $idem_key ) {
			return new WP_Error( 'storedash_gift_card_invalid', 'idem_key is required' );
		}

		$existing = $this->existing_issue( $idem_key, $card );
		if ( $existing ) {
			return $existing;
		}

		$code = '' === $code ? Code::generate() : $code;
		$now  = $this->now();

		$this->db_begin();
		try {
			$card_id = $this->db_insert_card(
				array(
					'code_hash'       => Code::hash( $code ),
					'code_enc'        => Code::encrypt( $code ),
					'code_last4'      => Code::last4( $code ),
					'initial_amount'  => $amount,
					'balance'         => $amount,
					'currency'        => (string) ( $card['currency'] ?? '' ),
					'status'          => self::STATUS_ACTIVE,
					'source'          => self::SOURCE_MANUAL === ( $card['source'] ?? '' ) ? self::SOURCE_MANUAL : self::SOURCE_PURCHASE,
					'order_id'        => self::id_or_null( $card['order_id'] ?? null ),
					'order_item_id'   => self::id_or_null( $card['order_item_id'] ?? null ),
					'unit_index'      => isset( $card['unit_index'] ) ? (int) $card['unit_index'] : null,
					'product_id'      => self::id_or_null( $card['product_id'] ?? null ),
					'purchaser_email' => self::text_or_null( $card['purchaser_email'] ?? null, 191 ),
					'recipient_email' => self::text_or_null( $card['recipient_email'] ?? null, 191 ),
					'recipient_name'  => self::text_or_null( $card['recipient_name'] ?? null, 191 ),
					'sender_name'     => self::text_or_null( $card['sender_name'] ?? null, 191 ),
					'message'         => self::text_or_null( $card['message'] ?? null, 2000 ),
					'send_at'         => self::text_or_null( $card['send_at'] ?? null, 19 ),
					'note'            => self::text_or_null( $card['note'] ?? null, 2000 ),
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			if ( ! $card_id ) {
				$this->db_rollback();
				$existing = $this->existing_issue( $idem_key, $card );
				return $existing ? $existing : new WP_Error( 'storedash_gift_card_db', 'Failed to insert gift card' );
			}

			$row_id = $this->db_insert_row(
				$this->new_row(
					$card_id,
					self::TYPE_ISSUE,
					$amount,
					$amount,
					(string) ( $card['currency'] ?? '' ),
					$idem_key,
					array(
						'order_id' => $card['order_id'] ?? null,
						'note'     => $card['note'] ?? null,
					)
				)
			);
			if ( ! $row_id ) {
				$this->db_rollback();
				$existing = $this->existing_issue( $idem_key, $card );
				return $existing ? $existing : new WP_Error( 'storedash_gift_card_db', 'Failed to insert gift card ledger row' );
			}

			$this->db_commit();
			return array(
				'card'    => $this->db_get_card( $card_id, false ),
				'row'     => $this->db_get_row( $row_id ),
				'created' => true,
				'code'    => $code,
			);
		} catch ( \Throwable $e ) {
			$this->db_rollback();
			return new WP_Error( 'storedash_gift_card_db', $e->getMessage() );
		}
	}

	/**
	 * Reserve card money for an order in ONE transaction (all cards or none).
	 *
	 * Each card row is locked; if any card is missing, disabled, or its balance
	 * no longer covers its amount, nothing is written.
	 *
	 * @param int   $order_id    Order id.
	 * @param array $allocations card_id => amount (positive).
	 * @return array|WP_Error List of { card, row } (created rows only).
	 *                        WP_Error 'storedash_gift_card_insufficient' (data: card_id) or
	 *                        'storedash_gift_card_conflict' when a concurrent request won the idem key.
	 */
	public function spend_order( int $order_id, array $allocations ) {
		$allocations = array_filter(
			array_map(
				static function ( $amount ) {
					return round( (float) $amount, 4 );
				},
				$allocations
			),
			static function ( $amount ) {
				return $amount > 0;
			}
		);
		if ( empty( $allocations ) ) {
			return array();
		}

		$summary = $this->order_summary( $order_id );
		ksort( $allocations ); // Lock in id order — no deadlocks between two orders.

		$this->db_begin();
		try {
			$locked = array();
			foreach ( $allocations as $card_id => $amount ) {
				$card = $this->db_get_card( (int) $card_id, true );
				if ( ! $card || self::STATUS_ACTIVE !== $card->status || ! Money::gte( (float) $card->balance, $amount ) ) {
					$this->db_rollback();
					return new WP_Error(
						'storedash_gift_card_insufficient',
						'Gift card balance no longer covers the amount applied',
						array(
							'card_id'   => (int) $card_id,
							'available' => $card ? (float) $card->balance : 0.0,
							'requested' => $amount,
						)
					);
				}
				$locked[ (int) $card_id ] = $card;
			}

			$out = array();
			foreach ( $allocations as $card_id => $amount ) {
				$n    = ( $summary[ (int) $card_id ]['spends'] ?? 0 ) + 1;
				$move = $this->move_locked(
					$locked[ (int) $card_id ],
					-$amount,
					self::TYPE_SPEND,
					'spend:' . $order_id . ':' . (int) $card_id . ':' . $n,
					array( 'order_id' => $order_id )
				);
				if ( ! $move ) {
					$this->db_rollback();
					return new WP_Error( 'storedash_gift_card_conflict', 'Gift card reservation conflicted with a concurrent request' );
				}
				$out[] = $move;
			}

			$this->db_commit();
			return $out;
		} catch ( \Throwable $e ) {
			$this->db_rollback();
			return new WP_Error( 'storedash_gift_card_db', $e->getMessage() );
		}
	}

	/**
	 * Put money back on a card (release / refund) or take it off (adjust).
	 *
	 * @param int    $card_id  Card id.
	 * @param float  $delta    Signed amount. Negative amounts are capped at the balance.
	 * @param string $type     Row type.
	 * @param string $idem_key Idempotency key.
	 * @param array  $args     order_id, refund_id, note, meta.
	 * @return array|WP_Error|null { card, row, created }; null when there was nothing to take.
	 */
	public function move( int $card_id, float $delta, string $type, string $idem_key, array $args = array() ) {
		if ( '' === $idem_key ) {
			return new WP_Error( 'storedash_gift_card_invalid', 'idem_key is required' );
		}
		$existing = $this->db_find_by_idem( $idem_key );
		if ( $existing ) {
			return $this->replay( $existing );
		}

		$this->db_begin();
		try {
			$card = $this->db_get_card( $card_id, true );
			if ( ! $card ) {
				$this->db_rollback();
				return new WP_Error( 'storedash_gift_card_not_found', 'Gift card not found' );
			}

			$delta = round( $delta, 4 );
			if ( $delta < 0 ) {
				$delta = -min( abs( $delta ), max( 0.0, (float) $card->balance ) );
			}
			if ( abs( $delta ) <= Money::EPSILON ) {
				$this->db_rollback();
				return null;
			}

			$move = $this->move_locked( $card, $delta, $type, $idem_key, $args );
			if ( ! $move ) {
				$this->db_rollback();
				$existing = $this->db_find_by_idem( $idem_key );
				return $existing ? $this->replay( $existing ) : new WP_Error( 'storedash_gift_card_db', 'Failed to insert gift card ledger row' );
			}

			$this->db_commit();
			$move['created'] = true;
			return $move;
		} catch ( \Throwable $e ) {
			$this->db_rollback();
			return new WP_Error( 'storedash_gift_card_db', $e->getMessage() );
		}
	}

	/**
	 * Disable / enable a card (balance kept, row amount 0).
	 *
	 * @param int    $card_id Card id.
	 * @param string $status  active|disabled.
	 * @param string $note    Note.
	 * @return array|WP_Error { card, row, created } — when the card already has the
	 *                        status, the latest matching row is returned with created=false.
	 */
	public function set_status( int $card_id, string $status, string $note = '' ) {
		$status = self::STATUS_DISABLED === $status ? self::STATUS_DISABLED : self::STATUS_ACTIVE;
		$type   = self::STATUS_DISABLED === $status ? self::TYPE_DISABLE : self::TYPE_ENABLE;

		$this->db_begin();
		try {
			$card = $this->db_get_card( $card_id, true );
			if ( ! $card ) {
				$this->db_rollback();
				return new WP_Error( 'storedash_gift_card_not_found', 'Gift card not found' );
			}

			if ( $status === $card->status ) {
				$this->db_rollback();
				$last = null;
				foreach ( $this->db_rows_for_card( $card_id ) as $row ) {
					if ( $type === $row->type ) {
						$last = $row;
					}
				}
				return array(
					'card'    => $card,
					'row'     => $last,
					'created' => false,
				);
			}

			$n   = 1;
			$now = $this->now();
			foreach ( $this->db_rows_for_card( $card_id ) as $row ) {
				if ( $type === $row->type ) {
					++$n;
				}
			}

			$this->db_update_card(
				$card_id,
				array(
					'status'     => $status,
					'updated_at' => $now,
				)
			);
			$row_id = $this->db_insert_row(
				$this->new_row( $card_id, $type, 0.0, (float) $card->balance, (string) $card->currency, $type . ':' . $card_id . ':' . $n, array( 'note' => $note ) )
			);
			if ( ! $row_id ) {
				$this->db_rollback();
				return new WP_Error( 'storedash_gift_card_conflict', 'Gift card status changed concurrently' );
			}

			$this->db_commit();
			return array(
				'card'    => $this->db_get_card( $card_id, false ),
				'row'     => $this->db_get_row( $row_id ),
				'created' => true,
			);
		} catch ( \Throwable $e ) {
			$this->db_rollback();
			return new WP_Error( 'storedash_gift_card_db', $e->getMessage() );
		}
	}

	// ── Pure helpers ──────────────────────────────────────────────────────

	/**
	 * Per-card spend/release/refund totals for an order's ledger rows.
	 *
	 * Pure function — unit tested. `outstanding` = what the order still holds
	 * from the card (spent − released − refunded), never negative.
	 *
	 * @param object[] $rows Ledger rows of one order.
	 * @return array card_id => { spent, released, refunded, outstanding, spends, releases }
	 */
	public static function summarize_order_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$card_id = (int) $row->card_id;
			if ( ! isset( $out[ $card_id ] ) ) {
				$out[ $card_id ] = array(
					'spent'       => 0.0,
					'released'    => 0.0,
					'refunded'    => 0.0,
					'outstanding' => 0.0,
					'spends'      => 0,
					'releases'    => 0,
				);
			}
			$amount = (float) $row->amount;
			switch ( $row->type ) {
				case self::TYPE_SPEND:
					$out[ $card_id ]['spent'] += abs( $amount );
					++$out[ $card_id ]['spends'];
					break;
				case self::TYPE_RELEASE:
					$out[ $card_id ]['released'] += abs( $amount );
					++$out[ $card_id ]['releases'];
					break;
				case self::TYPE_REFUND:
					$out[ $card_id ]['refunded'] += abs( $amount );
					break;
			}
		}
		foreach ( $out as $card_id => $totals ) {
			$out[ $card_id ]['spent']       = round( $totals['spent'], 4 );
			$out[ $card_id ]['released']    = round( $totals['released'], 4 );
			$out[ $card_id ]['refunded']    = round( $totals['refunded'], 4 );
			$out[ $card_id ]['outstanding'] = max( 0.0, round( $totals['spent'] - $totals['released'] - $totals['refunded'], 4 ) );
		}
		return $out;
	}

	// ── Internals ─────────────────────────────────────────────────────────

	/**
	 * Apply a balance move to a card that is already locked inside a transaction.
	 *
	 * @param object $card     Locked card row.
	 * @param float  $delta    Signed amount.
	 * @param string $type     Row type.
	 * @param string $idem_key Key.
	 * @param array  $args     order_id, refund_id, note, meta.
	 * @return array|null { card, row } or null when the row insert failed.
	 */
	protected function move_locked( $card, float $delta, string $type, string $idem_key, array $args ) {
		$balance = max( 0.0, round( (float) $card->balance + $delta, 4 ) );
		$row_id  = $this->db_insert_row( $this->new_row( (int) $card->id, $type, $delta, $balance, (string) $card->currency, $idem_key, $args ) );
		if ( ! $row_id ) {
			return null;
		}
		$this->db_update_card(
			(int) $card->id,
			array(
				'balance'    => $balance,
				'updated_at' => $this->now(),
			)
		);
		return array(
			'card' => $this->db_get_card( (int) $card->id, false ),
			'row'  => $this->db_get_row( $row_id ),
		);
	}

	/**
	 * Result for an idempotent replay.
	 *
	 * @param object $row Existing ledger row.
	 * @return array
	 */
	protected function replay( $row ): array {
		return array(
			'card'    => $this->db_get_card( (int) $row->card_id, false ),
			'row'     => $row,
			'created' => false,
		);
	}

	/**
	 * Existing issue result (by idem key, or by order item + unit).
	 *
	 * @param string $idem_key Key.
	 * @param array  $card     Card args.
	 * @return array|null
	 */
	protected function existing_issue( string $idem_key, array $card ) {
		$row = $this->db_find_by_idem( $idem_key );
		if ( $row ) {
			$replay         = $this->replay( $row );
			$replay['code'] = '';
			return $replay;
		}
		if ( ! empty( $card['order_item_id'] ) && isset( $card['unit_index'] ) ) {
			$existing = $this->db_find_card_by_item( (int) $card['order_item_id'], (int) $card['unit_index'] );
			if ( $existing ) {
				return array(
					'card'    => $existing,
					'row'     => null,
					'created' => false,
					'code'    => '',
				);
			}
		}
		return null;
	}

	/**
	 * Build a ledger row for insertion.
	 *
	 * @param int    $card_id       Card id.
	 * @param string $type          Type.
	 * @param float  $amount        Signed amount.
	 * @param float  $balance_after Balance after.
	 * @param string $currency      Currency.
	 * @param string $idem_key      Key.
	 * @param array  $args          order_id, refund_id, note, meta.
	 * @return array
	 */
	protected function new_row( int $card_id, string $type, float $amount, float $balance_after, string $currency, string $idem_key, array $args ): array {
		return array(
			'card_id'       => $card_id,
			'type'          => $type,
			'amount'        => round( $amount, 4 ),
			'balance_after' => round( $balance_after, 4 ),
			'currency'      => $currency,
			'order_id'      => self::id_or_null( $args['order_id'] ?? null ),
			'refund_id'     => self::id_or_null( $args['refund_id'] ?? null ),
			'note'          => self::text_or_null( $args['note'] ?? null, 2000 ),
			'meta'          => ! empty( $args['meta'] ) && is_array( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : null,
			'idem_key'      => substr( $idem_key, 0, 191 ),
			'created_at'    => $this->now(),
		);
	}

	/**
	 * Positive int or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	protected static function id_or_null( $value ) {
		return ( null === $value || '' === $value || (int) $value <= 0 ) ? null : (int) $value;
	}

	/**
	 * Trimmed, length-capped string or null.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string|null
	 */
	protected static function text_or_null( $value, int $max ) {
		if ( null === $value || ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	// ── Storage primitives ($wpdb) — overridden by the unit-test double ─────

	/**
	 * Card by id.
	 *
	 * @param int  $id   Card id.
	 * @param bool $lock SELECT … FOR UPDATE.
	 * @return object|null
	 */
	protected function db_get_card( int $id, bool $lock ) {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal; suffix is a literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->cards_table} WHERE id = %d{$suffix}", $id ) );
		return $row ? $row : null;
	}

	/**
	 * Card by code hash.
	 *
	 * @param string $hash Hash.
	 * @return object|null
	 */
	protected function db_find_card_by_hash( string $hash ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->cards_table} WHERE code_hash = %s", $hash ) );
		return $row ? $row : null;
	}

	/**
	 * Card by purchase line + unit.
	 *
	 * @param int $order_item_id Order item id.
	 * @param int $unit_index    Unit index.
	 * @return object|null
	 */
	protected function db_find_card_by_item( int $order_item_id, int $unit_index ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->cards_table} WHERE order_item_id = %d AND unit_index = %d", $order_item_id, $unit_index ) );
		return $row ? $row : null;
	}

	/**
	 * Insert a card.
	 *
	 * @param array $card Column => value.
	 * @return int Id, 0 on failure (e.g. UNIQUE violation).
	 */
	protected function db_insert_card( array $card ): int {
		global $wpdb;
		$ok = $wpdb->insert( $this->cards_table, $card, $this->formats( $card ) );
		if ( false === $ok ) {
			\StoreDash_Helpers::log_message( 'Gift card insert failed: ' . $wpdb->last_error, 'error', array( 'order_item_id' => $card['order_item_id'] ?? null ) );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update card columns.
	 *
	 * @param int   $id     Card id.
	 * @param array $fields Column => value.
	 */
	protected function db_update_card( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( $this->cards_table, $fields, array( 'id' => $id ), $this->formats( $fields ), array( '%d' ) );
	}

	/**
	 * Ledger row by idem key.
	 *
	 * @param string $idem_key Key.
	 * @return object|null
	 */
	protected function db_find_by_idem( string $idem_key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->ledger_table} WHERE idem_key = %s", $idem_key ) );
		return $row ? $row : null;
	}

	/**
	 * Ledger row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	protected function db_get_row( int $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->ledger_table} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/**
	 * Insert a ledger row.
	 *
	 * @param array $row Column => value.
	 * @return int Id, 0 on failure (e.g. UNIQUE idem_key violation).
	 */
	protected function db_insert_row( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( $this->ledger_table, $row, $this->formats( $row ) );
		if ( false === $ok ) {
			\StoreDash_Helpers::log_message( 'Gift card ledger insert failed: ' . $wpdb->last_error, 'error', array( 'idem_key' => $row['idem_key'] ?? '' ) );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Ledger rows of a card.
	 *
	 * @param int $card_id Card id.
	 * @return object[]
	 */
	protected function db_rows_for_card( int $card_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->ledger_table} WHERE card_id = %d ORDER BY id ASC", $card_id ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Ledger rows of an order.
	 *
	 * @param int $order_id Order id.
	 * @return object[]
	 */
	protected function db_rows_for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
				"SELECT * FROM {$this->ledger_table} WHERE order_id = %d AND type IN (%s, %s, %s) ORDER BY id ASC",
				$order_id,
				self::TYPE_SPEND,
				self::TYPE_RELEASE,
				self::TYPE_REFUND
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Cards updated since a moment.
	 *
	 * @param string $since_utc UTC MySQL datetime or ''.
	 * @param int    $offset    Offset.
	 * @param int    $limit     Limit.
	 * @return object[]
	 */
	protected function db_cards_since( string $since_utc, int $offset, int $limit ): array {
		global $wpdb;
		if ( '' === $since_utc ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->cards_table} ORDER BY updated_at ASC, id ASC LIMIT %d OFFSET %d", $limit, $offset ) );
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
					"SELECT * FROM {$this->cards_table} WHERE updated_at >= %s ORDER BY updated_at ASC, id ASC LIMIT %d OFFSET %d",
					$since_utc,
					$limit,
					$offset
				)
			);
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count cards updated since a moment.
	 *
	 * @param string $since_utc UTC MySQL datetime or ''.
	 * @return int
	 */
	protected function db_count_cards_since( string $since_utc ): int {
		global $wpdb;
		if ( '' === $since_utc ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->cards_table}" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->cards_table} WHERE updated_at >= %s", $since_utc ) );
	}

	/**
	 * Ledger rows created since a moment.
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
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->ledger_table} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ) );
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
					"SELECT * FROM {$this->ledger_table} WHERE created_at >= %s ORDER BY id ASC LIMIT %d OFFSET %d",
					$since_utc,
					$limit,
					$offset
				)
			);
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count ledger rows created since a moment.
	 *
	 * @param string $since_utc UTC MySQL datetime or ''.
	 * @return int
	 */
	protected function db_count_rows_since( string $since_utc ): int {
		global $wpdb;
		if ( '' === $since_utc ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->ledger_table}" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + literal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->ledger_table} WHERE created_at >= %s", $since_utc ) );
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

	/**
	 * wpdb formats for a column map (null values are written as NULL).
	 *
	 * @param array $data Column => value.
	 * @return array
	 */
	protected function formats( array $data ): array {
		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( null === $value ) {
				$formats[] = null;
			} elseif ( in_array( $key, array( 'card_id', 'order_id', 'order_item_id', 'unit_index', 'product_id', 'refund_id' ), true ) ) {
				$formats[] = '%d';
			} elseif ( in_array( $key, array( 'amount', 'balance', 'balance_after', 'initial_amount' ), true ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}
}
