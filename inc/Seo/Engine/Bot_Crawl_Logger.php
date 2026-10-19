<?php
/**
 * AI / search bot crawl logger (plan §4.7, §5 B5).
 *
 * `template_redirect` priority 0: one `preg_match` of the User-Agent against
 * the bot token alternation (compiled once per request). Non-bot traffic
 * returns there; nothing is read or written. A match increments
 * `transient storedash_bot_hits[ "YYYY-MM-DD|bot|path" ] = [ hits, product_id ]`
 * (UTC date, path without query, ≤ 5,000 keys — oldest date keys dropped
 * first, 48 h TTL).
 *
 * Hourly `wp_cron` event `storedash_seo_hourly_flush` reads + clears the
 * transient, drains B2's redirect hits when `Redirect_Manager::drain_hits()`
 * exists, and POSTs `seo.crawl_batch` payloads (≤ 2,000 crawl rows each) to
 * option `storedash_seo_webhook_url`, signed exactly like Credit_Webhook
 * (`X-WooDash-Signature` = hex HMAC-SHA256 of the exact body with
 * `woodash_webhook_secret`). No default URL exists yet: when it resolves
 * empty nothing is sent and the transient is left alone.
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
	const MAX_PATH = 2000;

	/**
	 * Cron event (hourly). Unscheduled on module disable and in the deactivator.
	 */
	const CRON_HOOK = 'storedash_seo_hourly_flush';

	/**
	 * Option overriding the webhook URL.
	 */
	const URL_OPTION = 'storedash_seo_webhook_url';

	/**
	 * No default Hookdeck source exists yet → '' = do not send.
	 */
	const DEFAULT_URL = '';

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
		add_action( 'template_redirect', array( $this, 'handle_request' ), 0 );
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
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against a fixed regex only; never stored or echoed.
		if ( '' === $ua ) {
			return;
		}
		$bot = self::match( $ua );
		if ( null === $bot ) {
			return;
		}
		$uri        = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to a path by path_from_uri().
		$product_id = ( function_exists( 'is_product' ) && is_product() ) ? (int) get_queried_object_id() : 0;
		$this->record( $bot, $uri, $product_id );
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
	 * Cron: read + clear the transient, drain redirect hits, POST in chunks.
	 *
	 * @return int Number of requests dispatched.
	 */
	public function flush(): int {
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
			(string) current_time( 'mysql', true )
		);
		if ( ! $payloads ) {
			return 0;
		}

		$secret = (string) get_option( 'woodash_webhook_secret', '' );
		$sent   = 0;
		foreach ( $payloads as $payload ) {
			$body    = wp_json_encode( $payload );
			$headers = array( 'Content-Type' => 'application/json' );
			if ( '' !== $secret ) {
				$headers['X-WooDash-Signature'] = self::sign( (string) $body, $secret );
			}
			if ( call_user_func( $this->sender, $url, (string) $body, $headers ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Pure: payloads (crawl rows chunked at CHUNK; redirect hits ride on the
	 * first payload). Empty when both lists are empty.
	 *
	 * @param array  $hits          Transient map.
	 * @param array  $redirect_hits [ { source_path, hits } ] from B2.
	 * @param string $store_id      woodash_store_id.
	 * @param string $store_url     get_site_url().
	 * @param string $timestamp     UTC mysql.
	 * @return array[]
	 */
	public static function build_payloads( array $hits, array $redirect_hits, string $store_id, string $store_url, string $timestamp ): array {
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

		$chunks = $rows ? array_chunk( $rows, self::CHUNK ) : array( array() );
		$out    = array();
		foreach ( $chunks as $i => $chunk ) {
			$out[] = array(
				'action'        => self::ACTION,
				'store_id'      => $store_id,
				'store_url'     => $store_url,
				'timestamp'     => $timestamp,
				'crawls'        => $chunk,
				'redirect_hits' => 0 === $i ? $redirects : array(),
			);
		}
		return $out;
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
	 * Default sender: non-blocking wp_remote_post, timeout 10.
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
				'timeout'   => 10,
				'blocking'  => false,
				'sslverify' => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			\StoreDash_Helpers::log_message( 'SEO crawl webhook dispatch failed: ' . $response->get_error_message(), 'error', array( 'action' => self::ACTION ) );
			return false;
		}
		return true;
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
