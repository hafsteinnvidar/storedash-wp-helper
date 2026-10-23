<?php
/**
 * Checkout funnel flusher.
 *
 * Clone of the SEO `Bot_Crawl_Logger` flush: the hourly wp_cron event
 * CRON_HOOK (scheduled while the funnel is on, unscheduled when off) reads
 * and clears `storedash_funnel_counts`, then POSTs `cart.funnel_batch`
 * payloads (≤ CHUNK rows and ≤ MAX_BODY_BYTES each) to the existing cart
 * webhook URL (`woodash_cart_webhook_url`, Hookdeck `woo-cart-sync`), signed
 * exactly like Cart_Tracking (`X-WooDash-Signature` = hex HMAC-SHA256 of
 * the exact body with `woodash_webhook_secret`). POSTs are blocking; only a
 * 2xx counts and failed chunks are merged back (summed) for the next run.
 * A 5-minute lock keeps overlapping flushes apart. When the URL resolves
 * empty (store not connected or merchant cleared the option) nothing is sent
 * and the transient is kept.
 *
 * A shopper request never waits on the webhook: the flush only ever runs
 * inside WP-Cron (or WP-CLI). Catch-up for stores whose scheduled cron is
 * late: after a counted hit, when no flush started for OPPORTUNISTIC_AFTER,
 * `shutdown` schedules a single CRON_HOOK event due now and calls
 * `spawn_cron()` — a non-blocking loopback that runs the blocking flush in
 * its own request. With `DISABLE_WP_CRON` and no system cron that spawn is
 * a no-op and the counts simply wait in the transient (48 h TTL).
 *
 * Payload:
 *   { action: 'cart.funnel_batch', store_id: int|null, store_url, timestamp,
 *     rows: [ { date, step, device, variant, sessions } ] }
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Checkout\Config;

/**
 * Funnel flusher.
 *
 * @since 1.29.0
 */
class Funnel_Flusher {

	/**
	 * Cron event (hourly).
	 */
	const CRON_HOOK = 'storedash_checkout_hourly_flush';

	/**
	 * Lock transient (cron + opportunistic flush never overlap).
	 */
	const LOCK_TRANSIENT = 'storedash_funnel_flush_lock';

	/**
	 * Lock TTL (seconds).
	 */
	const LOCK_TTL = 300;

	/**
	 * Transient set whenever a flush starts; while present no opportunistic
	 * flush is scheduled.
	 */
	const LAST_FLUSH_TRANSIENT = 'storedash_funnel_last_flush';

	/**
	 * Opportunistic flush only when no flush started for this long.
	 */
	const OPPORTUNISTIC_AFTER = 7200;

	/**
	 * Option (not autoloaded): `{ last_flush_at: iso|null, consecutive_failures: int }`.
	 */
	const STATS_OPTION = 'storedash_funnel_flush_stats';

	/**
	 * Failed rows are merged back until this many consecutive flushes
	 * failed, then dropped.
	 */
	const MAX_FAILURES = 3;

	/**
	 * Webhook action name.
	 */
	const ACTION = 'cart.funnel_batch';

	/**
	 * Same option + default as Cart_Tracking::get_cart_webhook_url().
	 */
	const URL_OPTION  = 'woodash_cart_webhook_url';
	const DEFAULT_URL = 'https://webhooks.storedash.io/yvrrywi5uld4jq';

	/**
	 * Rows per POST.
	 */
	const CHUNK = 2000;

	/**
	 * Max JSON body per POST.
	 */
	const MAX_BODY_BYTES = 262144;

	/**
	 * Transient store (Funnel_Counter contract).
	 *
	 * @var callable
	 */
	private $store;

	/**
	 * Sender: fn( string $url, string $body, array $headers ): bool.
	 *
	 * @var callable
	 */
	private $sender;

	/**
	 * URL resolver: fn(): string.
	 *
	 * @var callable
	 */
	private $url_resolver;

	/**
	 * Constructor. Dependencies injectable for tests; defaults are WP.
	 *
	 * @param callable|null $store        Transient store.
	 * @param callable|null $sender       HTTP sender.
	 * @param callable|null $url_resolver Webhook URL resolver.
	 */
	public function __construct( $store = null, $sender = null, $url_resolver = null ) {
		$this->store        = $store ? $store : array( Funnel_Counter::class, 'wp_store' );
		$this->sender       = $sender ? $sender : array( __CLASS__, 'wp_send' );
		$this->url_resolver = $url_resolver ? $url_resolver : array( __CLASS__, 'wp_url' );
	}

	/* ------------------------------------------------------------------ */
	/* Hooks                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Cron handler + schedule self-heal. Registered on every request (cheap:
	 * one autoloaded option) so the hourly event follows the funnel switch.
	 * The handler is a no-op when the transient is empty, so a stale event
	 * on a disabled store never errors.
	 */
	public function register_cron(): void {
		add_action( self::CRON_HOOK, array( $this, 'flush' ) );
		try {
			if ( Config::is_feature_active( Config::FEATURE_FUNNEL ) ) {
				self::schedule();
			} else {
				self::unschedule();
			}
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::debug_log( 'Checkout funnel cron sync failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Schedule the hourly flush (idempotent).
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the hourly flush (idempotent).
	 */
	public static function unschedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Catch-up for stores whose scheduled cron is late: after a counted hit,
	 * when no flush started for OPPORTUNISTIC_AFTER, arm a `shutdown` cron
	 * spawn. Never flushes inline on a shopper request.
	 *
	 * @return bool Armed.
	 */
	public function maybe_flush_on_shutdown(): bool {
		if ( false !== get_transient( self::LAST_FLUSH_TRANSIENT ) || false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LAST_FLUSH_TRANSIENT, time(), self::OPPORTUNISTIC_AFTER );
		add_action( 'shutdown', array( $this, 'spawn_catch_up' ) );

		return true;
	}

	/**
	 * `shutdown`: schedule one CRON_HOOK event due now and spawn WP-Cron
	 * (non-blocking loopback). The flush itself then runs blocking inside
	 * the cron request with the usual merge-back. Never throws.
	 */
	public function spawn_catch_up(): void {
		try {
			if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'spawn_cron' ) ) {
				return;
			}
			// Rejected (fine) when an identical event already sits within ±10 min.
			wp_schedule_single_event( time() - 1, self::CRON_HOOK );
			spawn_cron();
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::debug_log( 'Checkout funnel cron spawn failed: ' . $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Flush                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Cron / shutdown: read + clear the transient, POST in chunks. Locked so
	 * two flushes never overlap. Never throws.
	 *
	 * @return int Number of requests delivered.
	 */
	public function flush(): int {
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return 0;
		}
		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );
		set_transient( self::LAST_FLUSH_TRANSIENT, time(), self::OPPORTUNISTIC_AFTER );
		try {
			return $this->flush_unlocked();
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'Checkout funnel flush failed: ' . $e->getMessage(), 'error', array( 'action' => self::ACTION ) );

			return 0;
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Flush body (caller holds the lock).
	 *
	 * @return int Number of requests delivered.
	 */
	private function flush_unlocked(): int {
		$counts = call_user_func( $this->store, 'get' );
		$counts = is_array( $counts ) ? $counts : array();
		if ( ! $counts ) {
			return 0;
		}

		$url = (string) call_user_func( $this->url_resolver );
		if ( '' === $url ) {
			\StoreDash_Helpers::debug_log( 'Checkout funnel flush skipped: no webhook URL (option ' . self::URL_OPTION . ' / store not connected). Transient kept.' );

			return 0;
		}

		call_user_func( $this->store, 'delete' );

		$payloads = self::build_payloads(
			$counts,
			self::store_id_value( get_option( 'woodash_store_id', '' ) ),
			(string) home_url(),
			gmdate( 'c' )
		);
		if ( ! $payloads ) {
			return 0;
		}

		$secret = (string) get_option( 'woodash_webhook_secret', '' );
		$sent   = 0;
		$failed = array();
		foreach ( $payloads as $payload ) {
			$body    = (string) wp_json_encode( $payload );
			$headers = array( 'Content-Type' => 'application/json' );
			if ( '' !== $secret ) {
				$headers['X-WooDash-Signature'] = self::sign( $body, $secret );
			}
			if ( call_user_func( $this->sender, $url, $body, $headers ) ) {
				++$sent;
			} else {
				$failed = array_merge( $failed, $payload['rows'] );
			}
		}

		$stats                         = self::stats_option();
		$stats['last_flush_at']        = gmdate( 'c' );
		$stats['consecutive_failures'] = $sent < count( $payloads ) ? $stats['consecutive_failures'] + 1 : 0;
		update_option( self::STATS_OPTION, $stats, false );

		if ( $failed && $stats['consecutive_failures'] < self::MAX_FAILURES ) {
			// Never lose counts on one failed dispatch: merge them back for the next flush.
			$current = call_user_func( $this->store, 'get' );
			call_user_func( $this->store, 'set', Funnel_Counter::merge_rows( is_array( $current ) ? $current : array(), $failed ) );
		}

		return $sent;
	}

	/**
	 * Pure: payloads. Rows are split so every body stays within CHUNK rows
	 * and MAX_BODY_BYTES of JSON. Empty when there are no rows.
	 *
	 * @param array    $counts    Transient map.
	 * @param int|null $store_id  Store id.
	 * @param string   $store_url home_url().
	 * @param string   $timestamp ISO 8601.
	 * @return array[]
	 */
	public static function build_payloads( array $counts, $store_id, string $store_url, string $timestamp ): array {
		$rows = Funnel_Counter::rows( $counts );
		if ( ! $rows ) {
			return array();
		}

		$envelope = array(
			'action'    => self::ACTION,
			'store_id'  => $store_id,
			'store_url' => $store_url,
			'timestamp' => $timestamp,
			'rows'      => array(),
		);
		$base     = strlen( (string) self::encode( $envelope ) );
		$out      = array();
		$chunk    = array();
		$size     = $base;
		foreach ( $rows as $row ) {
			$row_bytes = strlen( (string) self::encode( $row ) ) + 1;
			if ( $chunk && ( count( $chunk ) >= self::CHUNK || $size + $row_bytes > self::MAX_BODY_BYTES ) ) {
				$out[] = array_merge( $envelope, array( 'rows' => $chunk ) );
				$chunk = array();
				$size  = $base;
			}
			$chunk[] = $row;
			$size   += $row_bytes;
		}
		if ( $chunk ) {
			$out[] = array_merge( $envelope, array( 'rows' => $chunk ) );
		}

		return $out;
	}

	/**
	 * `woodash_store_id` option → int, or null when unset / non-numeric.
	 *
	 * @param mixed $raw Option value.
	 * @return int|null
	 */
	public static function store_id_value( $raw ) {
		if ( is_numeric( $raw ) && (int) $raw > 0 ) {
			return (int) $raw;
		}

		return null;
	}

	/**
	 * Hex HMAC-SHA256 over the exact body bytes (Cart_Tracking contract).
	 *
	 * @param string $body   JSON body.
	 * @param string $secret woodash_webhook_secret.
	 * @return string
	 */
	public static function sign( string $body, string $secret ): string {
		return hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Stored flush stats, normalized.
	 *
	 * @return array { last_flush_at: string|null, consecutive_failures: int }
	 */
	public static function stats_option(): array {
		$raw = get_option( self::STATS_OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'last_flush_at'        => isset( $raw['last_flush_at'] ) && is_string( $raw['last_flush_at'] ) ? $raw['last_flush_at'] : null,
			'consecutive_failures' => isset( $raw['consecutive_failures'] ) ? max( 0, (int) $raw['consecutive_failures'] ) : 0,
		);
	}

	/**
	 * JSON exactly as flush() encodes it (for size accounting).
	 *
	 * @param mixed $value Value.
	 * @return string|false
	 */
	private static function encode( $value ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback outside WP.
	}

	/* ------------------------------------------------------------------ */
	/* WP defaults                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Default sender: blocking POST, timeout 5, only a 2xx counts as
	 * delivered (a lost POST is merged back, never silently dropped). Only
	 * ever runs inside WP-Cron / WP-CLI (see class docblock), so blocking
	 * never delays a shopper.
	 *
	 * @param string $url     URL.
	 * @param string $body    JSON.
	 * @param array  $headers Headers.
	 * @return bool
	 */
	public static function wp_send( string $url, string $body, array $headers ): bool {
		$response = wp_remote_post(
			$url,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 5,
				'blocking'  => true,
				'sslverify' => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			\StoreDash_Helpers::log_message( 'Checkout funnel webhook not delivered: ' . $response->get_error_message(), 'error', array( 'action' => self::ACTION ) );

			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		\StoreDash_Helpers::log_message( 'Checkout funnel webhook not delivered: HTTP ' . $code, 'error', array( 'action' => self::ACTION ) );

		return false;
	}

	/**
	 * Default URL resolver (connection gate + option, Cart_Tracking contract).
	 *
	 * @return string
	 */
	public static function wp_url(): string {
		return \StoreDash_Helpers::resolve_webhook_url( self::URL_OPTION, self::DEFAULT_URL );
	}
}
