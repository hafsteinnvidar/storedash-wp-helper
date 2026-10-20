<?php
/**
 * AI / search bot crawl logger (plan §4.7, §5 B5).
 *
 * `template_redirect` priority -1 (before B2's sitemap at 0 and redirects at
 * 1): one `preg_match` of the User-Agent against the bot token alternation
 * (compiled once per request). Non-bot traffic returns there; nothing is
 * read or written. A match increments
 * `transient storedash_bot_hits[ "YYYY-MM-DD|bot|path" ] = [ hits, product_id ]`
 * (UTC date, path without query, ≤ 500 chars, ≤ 5,000 keys — oldest date
 * keys dropped first, 48 h TTL). 404 requests are counted only when B2
 * redirects them or they are `/llms.txt` (the UA is spoofable; random 404
 * paths must not fill the map).
 *
 * Hourly `wp_cron` event `storedash_seo_hourly_flush` (plus an opportunistic
 * flush on `shutdown` after a bot hit when no flush ran for 2 h — stores
 * with broken cron) reads + clears the transient, drains B2's redirect hits
 * when `Redirect_Manager::drain_hits()` exists, and POSTs `seo.crawl_batch`
 * payloads (≤ 2,000 crawl rows and ≤ 256 KB each) to option
 * `storedash_seo_webhook_url`, signed exactly like Credit_Webhook
 * (`X-WooDash-Signature` = hex HMAC-SHA256 of the exact body with
 * `woodash_webhook_secret`). Cron / WP-CLI flushes POST blocking and only
 * a 2xx counts; failed chunks are merged back. A 5-minute lock keeps cron and the
 * opportunistic flush from overlapping. No default URL exists yet: when it
 * resolves empty nothing is sent and the transient is left alone.
 *
 * Known race: two concurrent bot hits read-modify-write the same transient;
 * one increment may be lost. Accepted — counts are approximate by design.
 *
 * @package StoreDash\Seo
 * @since   1.27.0
 */

namespace StoreDash\Seo\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Settings;

/**
 * Bot crawl logger.
 *
 * @since 1.27.0
 */
class Bot_Crawl_Logger {

	/**
	 * Transient holding the pending counts.
	 */
	const TRANSIENT = 'storedash_bot_hits';

	/**
	 * Transient TTL (seconds).
	 */
	const TTL = 48 * 3600;

	/**
	 * Max keys kept in the transient.
	 */
	const MAX_KEYS = 5000;

	/**
	 * Max stored path length.
	 */
	const MAX_PATH = 500;

	/**
	 * Max JSON body per POST (plan §6.6 receiver cap).
	 */
	const MAX_BODY_BYTES = 262144;

	/**
	 * Lock transient (cron + opportunistic flush never overlap).
	 */
	const LOCK_TRANSIENT = 'storedash_seo_flush_lock';

	/**
	 * Lock TTL (seconds).
	 */
	const LOCK_TTL = 300;

	/**
	 * Transient set whenever a flush starts; while present no opportunistic
	 * flush is scheduled.
	 */
	const LAST_FLUSH_TRANSIENT = 'storedash_seo_last_flush';

	/**
	 * Opportunistic flush only when no flush started for this long.
	 */
	const OPPORTUNISTIC_AFTER = 7200;

	/**
	 * Option (not autoloaded): `{ last_flush_at: iso|null, consecutive_failures: int }`.
	 */
	const STATS_OPTION = 'storedash_seo_crawl_stats';

	/**
	 * Failed rows are merged back into the transient until this many
	 * consecutive flushes failed, then dropped.
	 */
	const MAX_FAILURES = 3;

	/**
	 * Fired by Robots_Txt right before `/llms.txt` is served (a 404 to WP).
	 */
	const LLMS_ACTION = 'storedash_seo_llms_served';

	/**
	 * Cron event (hourly). Unscheduled on module disable and in the deactivator.
	 */
	const CRON_HOOK = 'storedash_seo_hourly_flush';

	/**
	 * Option overriding the webhook URL.
	 */
	const URL_OPTION = 'storedash_seo_webhook_url';

	/**
	 * Hookdeck source `woo-seo-crawl` → storedash-sync `/webhooks/seo-crawl`.
	 */
	const DEFAULT_URL = 'https://webhooks.storedash.io/3fzku2m9uahetd';

	/**
	 * Webhook action name.
	 */
	const ACTION = 'seo.crawl_batch';

	/**
	 * Crawl rows per POST.
	 */
	const CHUNK = 2000;

	/**
	 * Bot slug (plan §4.7) => User-Agent token (vendor documented, matched
	 * case-insensitively). `google-extended` has no UA string (robots.txt-only
	 * token) and never matches; kept for the slug list.
	 */
	const BOTS = array(
		'gptbot'             => 'GPTBot',
		'oai-searchbot'      => 'OAI-SearchBot',
		'chatgpt-user'       => 'ChatGPT-User',
		'claudebot'          => 'ClaudeBot',
		'claude-user'        => 'Claude-User',
		'perplexitybot'      => 'PerplexityBot',
		'perplexity-user'    => 'Perplexity-User',
		'google-extended'    => 'Google-Extended',
		'googlebot'          => 'Googlebot',
		'bingbot'            => 'bingbot',
		'applebot'           => 'Applebot',
		'amazonbot'          => 'Amazonbot',
		'bytespider'         => 'Bytespider',
		'ccbot'              => 'CCBot',
		'meta-externalagent' => 'meta-externalagent',
	);

	/**
	 * Compiled regex (per process).
	 *
	 * @var string|null
	 */
	private static $regex = null;

	/**
	 * Transient store: fn( 'get' ) : mixed, fn( 'set', array $value ), fn( 'delete' ).
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
	 * Redirect-hit drainer: fn(): array.
	 *
	 * @var callable
	 */
	private $redirect_drainer;

	/**
	 * Bot hit of a 404 request waiting for a redirect / llms.txt to claim it:
	 * `{ bot, uri }` or null.
	 *
	 * @var array|null
	 */
	private $pending = null;

	/**
	 * Constructor. Every dependency is injectable for tests; defaults are WP.
	 *
	 * @param callable|null $store            Transient store.
	 * @param callable|null $sender           HTTP sender.
	 * @param callable|null $url_resolver     Webhook URL resolver.
	 * @param callable|null $redirect_drainer B2 redirect-hit drainer.
	 */
	public function __construct( $store = null, $sender = null, $url_resolver = null, $redirect_drainer = null ) {
		$this->store            = $store ? $store : array( __CLASS__, 'wp_store' );
		$this->sender           = $sender ? $sender : array( __CLASS__, 'wp_send' );
		$this->url_resolver     = $url_resolver ? $url_resolver : array( __CLASS__, 'wp_url' );
		$this->redirect_drainer = $redirect_drainer ? $redirect_drainer : array( __CLASS__, 'drain_redirect_hits' );
	}

	/* ------------------------------------------------------------------ */
	/* Hooks                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Front-end hook (only when Seo_Manager::should_render()).
	 */
	public function register_front_hooks(): void {
		add_action( 'template_redirect', array( $this, 'handle_request' ), -1 );
		add_action( Redirect_Manager::MATCHED_ACTION, array( $this, 'record_pending' ) );
		add_action( self::LLMS_ACTION, array( $this, 'record_pending' ) );
	}

	/**
	 * Cron handler + schedule self-heal. Registered on every request (cheap:
	 * two autoloaded options) so the hourly event follows the module switch.
	 */
	public function register_cron(): void {
		add_action( self::CRON_HOOK, array( $this, 'flush' ) );
		if ( Settings::is_enabled() ) {
			self::schedule();
		} else {
			self::unschedule();
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
	 * `template_redirect` priority 0.
	 */
	public function handle_request(): void {
		try {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against a fixed regex only; never stored or echoed.
			if ( '' === $ua ) {
				return;
			}
			$bot = self::match( $ua );
			if ( null === $bot ) {
				return;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to a path by path_from_uri().
			if ( is_404() ) {
				// Count only if B2 redirects it or it is /llms.txt (record_pending()).
				$this->pending = array(
					'bot' => $bot,
					'uri' => $uri,
				);
				return;
			}
			$product_id = ( function_exists( 'is_product' ) && is_product() ) ? (int) get_queried_object_id() : 0;
			$this->record( $bot, $uri, $product_id );
			$this->maybe_flush_on_shutdown();
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::debug_log( 'SEO bot crawl log failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Redirect matched / llms.txt served on a 404 bot request: count it.
	 */
	public function record_pending(): void {
		if ( null === $this->pending ) {
			return;
		}
		$pending       = $this->pending;
		$this->pending = null;
		try {
			$this->record( $pending['bot'], $pending['uri'], 0 );
			$this->maybe_flush_on_shutdown();
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::debug_log( 'SEO bot crawl log failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Opportunistic flush for stores whose wp_cron never runs: after a bot
	 * hit, when no flush started for OPPORTUNISTIC_AFTER, flush on `shutdown`.
	 *
	 * @return bool Scheduled.
	 */
	public function maybe_flush_on_shutdown(): bool {
		if ( false !== get_transient( self::LAST_FLUSH_TRANSIENT ) || false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LAST_FLUSH_TRANSIENT, time(), self::OPPORTUNISTIC_AFTER );
		add_action( 'shutdown', array( $this, 'flush' ) );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Matching (pure)                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Alternation of all tokens, longest first (`google-extended` before
	 * `googlebot`, `perplexity-user` before `perplexitybot`), case-insensitive.
	 *
	 * @return string
	 */
	public static function regex(): string {
		if ( null === self::$regex ) {
			$tokens = array_values( self::BOTS );
			usort(
				$tokens,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			self::$regex = '/(' . implode( '|', array_map( 'preg_quote', $tokens ) ) . ')/i';
		}
		return self::$regex;
	}

	/**
	 * Bot slug for a User-Agent, or null. Exactly one preg_match.
	 *
	 * @param string $user_agent UA string.
	 * @return string|null
	 */
	public static function match( string $user_agent ) {
		if ( ! preg_match( self::regex(), $user_agent, $m ) ) {
			return null;
		}
		$token = strtolower( $m[1] );
		foreach ( self::BOTS as $slug => $bot_token ) {
			if ( strtolower( $bot_token ) === $token ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Request path: no query / fragment, leading slash, ≤ MAX_PATH.
	 *
	 * @param string $uri Raw REQUEST_URI.
	 * @return string
	 */
	public static function path_from_uri( string $uri ): string {
		$path = strtok( $uri, '?#' );
		$path = false === $path ? '/' : $path;
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		if ( strlen( $path ) > self::MAX_PATH ) {
			$path = substr( $path, 0, self::MAX_PATH );
		}
		return $path;
	}

	/**
	 * Transient key.
	 *
	 * @param string $date 'Y-m-d' UTC.
	 * @param string $bot  Slug.
	 * @param string $path Path.
	 * @return string
	 */
	public static function key( string $date, string $bot, string $path ): string {
		return $date . '|' . $bot . '|' . $path;
	}

	/**
	 * Pure: add one hit, enforce the cap (oldest date keys dropped first — keys
	 * sort by their `YYYY-MM-DD` prefix).
	 *
	 * @param array  $hits       Current map.
	 * @param string $key        key().
	 * @param int    $product_id Product ID (0 = none).
	 * @return array
	 */
	public static function add_hit( array $hits, string $key, int $product_id ): array {
		if ( isset( $hits[ $key ] ) ) {
			$entry        = is_array( $hits[ $key ] ) ? $hits[ $key ] : array( (int) $hits[ $key ], 0 );
			$entry[0]     = (int) $entry[0] + 1;
			$entry[1]     = $product_id > 0 ? $product_id : (int) ( isset( $entry[1] ) ? $entry[1] : 0 );
			$hits[ $key ] = array( $entry[0], $entry[1] );
			return $hits;
		}
		if ( count( $hits ) >= self::MAX_KEYS ) {
			ksort( $hits, SORT_STRING );
			$hits = array_slice( $hits, count( $hits ) - self::MAX_KEYS + 1, null, true );
		}
		$hits[ $key ] = array( 1, $product_id );
		return $hits;
	}

	/**
	 * Record a hit (transient read + write; only called on a match).
	 *
	 * @param string $bot        Slug.
	 * @param string $uri        REQUEST_URI.
	 * @param int    $product_id Product ID (0 = none).
	 * @param string $date       'Y-m-d' UTC (default today).
	 * @return array The stored map.
	 */
	public function record( string $bot, string $uri, int $product_id, string $date = '' ): array {
		$date = '' !== $date ? $date : gmdate( 'Y-m-d' );
		$hits = call_user_func( $this->store, 'get' );
		$hits = self::add_hit( is_array( $hits ) ? $hits : array(), self::key( $date, $bot, self::path_from_uri( $uri ) ), $product_id );
		call_user_func( $this->store, 'set', $hits );
		return $hits;
	}

	/* ------------------------------------------------------------------ */
	/* Flush                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Cron / shutdown: read + clear the transient, drain redirect hits, POST
	 * in chunks. Locked (LOCK_TRANSIENT) so two flushes never overlap.
	 *
	 * @return int Number of requests dispatched.
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
			\StoreDash_Helpers::log_message( 'SEO crawl flush failed: ' . $e->getMessage(), 'error', array( 'action' => self::ACTION ) );
			return 0;
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Flush body (caller holds the lock).
	 *
	 * @return int Number of requests dispatched.
	 */
	private function flush_unlocked(): int {
		$url = (string) call_user_func( $this->url_resolver );
		if ( '' === $url ) {
			\StoreDash_Helpers::debug_log( 'SEO crawl flush skipped: no webhook URL (option ' . self::URL_OPTION . ' / store not connected). Transient kept.' );
			return 0;
		}

		$hits = call_user_func( $this->store, 'get' );
		$hits = is_array( $hits ) ? $hits : array();
		if ( $hits ) {
			call_user_func( $this->store, 'delete' );
		}
		$redirect_hits = self::normalize_redirect_hits( call_user_func( $this->redirect_drainer ) );

		$payloads = self::build_payloads(
			$hits,
			$redirect_hits,
			(string) get_option( 'woodash_store_id', '' ),
			(string) get_site_url(),
			(string) current_time( 'mysql', true ),
			defined( 'STOREDASH_VERSION' ) ? (string) STOREDASH_VERSION : ''
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
				$failed = array_merge( $failed, $payload['crawls'] );
			}
		}

		$stats                         = self::stats_option();
		$stats['last_flush_at']        = gmdate( 'c' );
		$stats['consecutive_failures'] = $sent < count( $payloads ) ? $stats['consecutive_failures'] + 1 : 0;
		update_option( self::STATS_OPTION, $stats, false );

		if ( $failed && $stats['consecutive_failures'] < self::MAX_FAILURES ) {
			// Never lose counts on one failed dispatch: merge them back for the next flush.
			$current = call_user_func( $this->store, 'get' );
			call_user_func( $this->store, 'set', self::merge_rows( is_array( $current ) ? $current : array(), $failed ) );
		}
		return $sent;
	}

	/**
	 * Pure: add crawl rows back into a transient map (cap enforced by add_hit()).
	 *
	 * @param array $hits Map.
	 * @param array $rows [ { date, bot, path, product_id, hits } ].
	 * @return array
	 */
	public static function merge_rows( array $hits, array $rows ): array {
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['date'], $row['bot'], $row['path'], $row['hits'] ) ) {
				continue;
			}
			$key        = self::key( (string) $row['date'], (string) $row['bot'], (string) $row['path'] );
			$product_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
			if ( isset( $hits[ $key ] ) ) {
				$entry = is_array( $hits[ $key ] ) ? $hits[ $key ] : array( (int) $hits[ $key ], 0 );
			} else {
				$hits  = self::add_hit( $hits, $key, $product_id ); // Enforces the key cap.
				$entry = array( 0, $product_id );
			}
			$hits[ $key ] = array(
				(int) $entry[0] + (int) $row['hits'],
				$product_id > 0 ? $product_id : (int) ( isset( $entry[1] ) ? $entry[1] : 0 ),
			);
		}
		return $hits;
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
	 * `GET /seo/crawl-stats` payload (debug without SSH).
	 *
	 * @param string|null $webhook_url Resolved URL (null = resolve).
	 * @return array
	 */
	public static function stats( $webhook_url = null ): array {
		$hits  = get_transient( self::TRANSIENT );
		$stats = self::stats_option();
		$next  = wp_next_scheduled( self::CRON_HOOK );
		$url   = null === $webhook_url ? self::wp_url() : (string) $webhook_url;
		return array(
			'pending_keys'           => is_array( $hits ) ? count( $hits ) : 0,
			'last_flush_at'          => $stats['last_flush_at'],
			'consecutive_failures'   => $stats['consecutive_failures'],
			'webhook_url_configured' => '' !== $url,
			'next_scheduled_at'      => $next ? gmdate( 'c', (int) $next ) : null,
		);
	}

	/**
	 * Pure: payloads. Crawl rows are split so that every body stays within
	 * CHUNK rows and MAX_BODY_BYTES of JSON; redirect hits ride on the first
	 * payload. Empty when both lists are empty.
	 *
	 * @param array  $hits           Transient map.
	 * @param array  $redirect_hits  [ { source_path, hits } ] from B2.
	 * @param string $store_id       woodash_store_id.
	 * @param string $store_url      get_site_url().
	 * @param string $timestamp      UTC mysql.
	 * @param string $plugin_version STOREDASH_VERSION.
	 * @return array[]
	 */
	public static function build_payloads( array $hits, array $redirect_hits, string $store_id, string $store_url, string $timestamp, string $plugin_version = '' ): array {
		$rows      = self::rows( $hits );
		$redirects = array();
		foreach ( $redirect_hits as $r ) {
			if ( is_array( $r ) && isset( $r['source_path'] ) && isset( $r['hits'] ) && (int) $r['hits'] > 0 ) {
				$redirects[] = array(
					'source_path' => (string) $r['source_path'],
					'hits'        => (int) $r['hits'],
				);
			}
		}
		if ( ! $rows && ! $redirects ) {
			return array();
		}

		$envelope = array(
			'action'         => self::ACTION,
			'store_id'       => $store_id,
			'store_url'      => $store_url,
			'timestamp'      => $timestamp,
			'plugin_version' => $plugin_version,
			'crawls'         => array(),
			'redirect_hits'  => array(),
		);
		$base     = strlen( (string) self::encode( $envelope ) );
		$out      = array();
		$chunk    = array();
		$size     = $base + self::list_bytes( $redirects );
		$first    = true;
		foreach ( $rows as $row ) {
			$row_bytes = strlen( (string) self::encode( $row ) ) + 1;
			if ( $chunk && ( count( $chunk ) >= self::CHUNK || $size + $row_bytes > self::MAX_BODY_BYTES ) ) {
				$out[] = array_merge(
					$envelope,
					array(
						'crawls'        => $chunk,
						'redirect_hits' => $first ? $redirects : array(),
					)
				);
				$first = false;
				$chunk = array();
				$size  = $base;
			}
			$chunk[] = $row;
			$size   += $row_bytes;
		}
		if ( $chunk || $first ) {
			$out[] = array_merge(
				$envelope,
				array(
					'crawls'        => $chunk,
					'redirect_hits' => $first ? $redirects : array(),
				)
			);
		}
		return $out;
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

	/**
	 * Bytes a list adds inside a JSON array (items + commas).
	 *
	 * @param array $items Items.
	 * @return int
	 */
	private static function list_bytes( array $items ): int {
		$bytes = 0;
		foreach ( $items as $item ) {
			$bytes += strlen( (string) self::encode( $item ) ) + 1;
		}
		return $bytes;
	}

	/**
	 * Pure: transient map → sorted crawl rows.
	 *
	 * @param array $hits Map.
	 * @return array [ { date, bot, path, product_id, hits } ].
	 */
	public static function rows( array $hits ): array {
		ksort( $hits, SORT_STRING );
		$rows = array();
		foreach ( $hits as $key => $entry ) {
			$parts = explode( '|', (string) $key, 3 );
			if ( 3 !== count( $parts ) ) {
				continue;
			}
			$count      = is_array( $entry ) ? (int) $entry[0] : (int) $entry;
			$product_id = is_array( $entry ) && isset( $entry[1] ) ? (int) $entry[1] : 0;
			if ( $count <= 0 ) {
				continue;
			}
			$rows[] = array(
				'date'       => $parts[0],
				'bot'        => $parts[1],
				'path'       => $parts[2],
				'product_id' => $product_id > 0 ? $product_id : null,
				'hits'       => $count,
			);
		}
		return $rows;
	}

	/**
	 * Hex HMAC-SHA256 over the exact body bytes (Credit_Webhook contract).
	 *
	 * @param string $body   JSON body.
	 * @param string $secret woodash_webhook_secret.
	 * @return string
	 */
	public static function sign( string $body, string $secret ): string {
		return hash_hmac( 'sha256', $body, $secret );
	}

	/* ------------------------------------------------------------------ */
	/* WP defaults                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Default transient store.
	 *
	 * @param string $op    get | set | delete.
	 * @param mixed  $value Value for set.
	 * @return mixed
	 */
	public static function wp_store( string $op, $value = null ) {
		switch ( $op ) {
			case 'get':
				return get_transient( self::TRANSIENT );
			case 'set':
				return set_transient( self::TRANSIENT, $value, self::TTL );
			case 'delete':
				return delete_transient( self::TRANSIENT );
		}
		return null;
	}

	/**
	 * Default sender. In cron / WP-CLI (the hourly event) the POST is
	 * **blocking** (timeout 5) and only a 2xx counts as delivered, so a lost
	 * POST is merged back instead of silently dropped — the non-blocking
	 * variant was seen on wp-env to return "dispatched" while the receiver got
	 * nothing. The opportunistic flush runs on a visitor's `shutdown`, so
	 * there it stays non-blocking (timeout 10; only connection errors count).
	 *
	 * @param string $url     URL.
	 * @param string $body    JSON.
	 * @param array  $headers Headers.
	 * @return bool
	 */
	public static function wp_send( string $url, string $body, array $headers ): bool {
		$blocking = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		$response = wp_remote_post(
			$url,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => $blocking ? 5 : 10,
				'blocking'  => $blocking,
				'sslverify' => true,
			)
		);
		$code     = ( $blocking && ! is_wp_error( $response ) ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		if ( self::delivered( $response, $blocking, $code ) ) {
			return true;
		}
		$reason = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
		\StoreDash_Helpers::log_message( 'SEO crawl webhook not delivered: ' . $reason, 'error', array( 'action' => self::ACTION ) );
		return false;
	}

	/**
	 * Pure: did a POST deliver? WP_Error never; non-blocking = dispatched;
	 * blocking = 2xx only.
	 *
	 * @param mixed $response wp_remote_post() result.
	 * @param bool  $blocking Was the request blocking.
	 * @param int   $code     HTTP status (blocking only).
	 * @return bool
	 */
	public static function delivered( $response, bool $blocking, int $code ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}
		return ! $blocking || ( $code >= 200 && $code < 300 );
	}

	/**
	 * Default URL resolver (connection gate + option, Credit_Webhook contract).
	 *
	 * @return string
	 */
	public static function wp_url(): string {
		return \StoreDash_Helpers::resolve_webhook_url( self::URL_OPTION, self::DEFAULT_URL );
	}

	/**
	 * B2 soft seam: `Redirect_Manager::drain_hits(): array` when it exists.
	 * B2 returns `array( 'hits' => array( '/old' => 2 ), 'since' => iso|null )`
	 * (source paths without trailing slash); normalize_redirect_hits() turns
	 * that — or a plain list of `{ source_path, hits }` — into payload rows.
	 *
	 * @return array [ { source_path, hits } ]
	 */
	public static function drain_redirect_hits(): array {
		$class = __NAMESPACE__ . '\\Redirect_Manager';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'drain_hits' ) ) {
			return array();
		}
		try {
			$hits = call_user_func( array( $class, 'drain_hits' ) );
		} catch ( \Throwable $e ) {
			\StoreDash_Helpers::log_message( 'SEO redirect hit drain failed: ' . $e->getMessage(), 'error' );
			return array();
		}
		return self::normalize_redirect_hits( $hits );
	}

	/**
	 * Pure: B2's `{ hits: { path: n }, since }` map or a `[ { source_path, hits } ]`
	 * list → payload rows (hits > 0 only).
	 *
	 * @param mixed $drained drain_hits() result.
	 * @return array [ { source_path, hits } ]
	 */
	public static function normalize_redirect_hits( $drained ): array {
		if ( ! is_array( $drained ) ) {
			return array();
		}
		$rows = array();
		if ( isset( $drained['hits'] ) && is_array( $drained['hits'] ) ) {
			foreach ( $drained['hits'] as $path => $n ) {
				if ( (int) $n > 0 && '' !== (string) $path ) {
					$rows[] = array(
						'source_path' => (string) $path,
						'hits'        => (int) $n,
					);
				}
			}
			return $rows;
		}
		foreach ( $drained as $r ) {
			if ( is_array( $r ) && isset( $r['source_path'], $r['hits'] ) && (int) $r['hits'] > 0 ) {
				$rows[] = array(
					'source_path' => (string) $r['source_path'],
					'hits'        => (int) $r['hits'],
				);
			}
		}
		return $rows;
	}
}
