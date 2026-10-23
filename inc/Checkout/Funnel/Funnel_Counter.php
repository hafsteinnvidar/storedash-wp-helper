<?php
/**
 * Checkout funnel counter (pure logic).
 *
 * Counts unique shopper sessions per day per funnel step in one transient
 * map: `storedash_funnel_counts[ "Y-m-d|step|device|variant" ] = int`.
 * Dates are in the store timezone (injected `now` callable), the map holds
 * at most MAX_KEYS entries (oldest date keys dropped first) and lives 48 h.
 *
 * Every WordPress / WooCommerce touch point is an injected callable so the
 * class runs under plain PHPUnit (`tests/Checkout/Funnel_CounterTest.php`):
 *
 *   - store:       fn( 'get' ) : mixed, fn( 'set', array ), fn( 'delete' ).
 *   - now:         fn(): string  — today's 'Y-m-d' in the store timezone.
 *   - session_get: fn( string $key ): mixed — WC session read.
 *   - session_set: fn( string $key, mixed $value ): void — WC session write.
 *   - user_agent:  fn(): string — request UA ('' when unknown).
 *
 * Dedupe: a session counts once per step per day. The seen map lives in the
 * WC session under SESSION_KEY as `[ step => 'Y-m-d' ]`; a repeat on the
 * same day returns false and does not count.
 *
 * Bots: when the UA matches the SEO bot token list
 * (`Bot_Crawl_Logger::match()`), nothing is counted.
 *
 * Known race: two concurrent requests read-modify-write the same transient;
 * one increment may be lost. Accepted — counts are approximate by design.
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StoreDash\Seo\Engine\Bot_Crawl_Logger;

/**
 * Funnel counter.
 *
 * @since 1.29.0
 */
class Funnel_Counter {

	/**
	 * Transient holding the pending counts.
	 */
	const TRANSIENT = 'storedash_funnel_counts';

	/**
	 * Transient TTL (seconds).
	 */
	const TTL = 48 * 3600;

	/**
	 * Max keys kept in the transient.
	 */
	const MAX_KEYS = 2000;

	/**
	 * WC session key holding the per-step "seen on date" map.
	 */
	const SESSION_KEY = 'storedash_funnel_seen';

	/**
	 * Funnel steps.
	 *
	 * @var string[]
	 */
	const STEPS = array( 'cart_view', 'checkout_view', 'order_placed', 'bump_view', 'bump_accept' );

	/**
	 * Device classes.
	 *
	 * @var string[]
	 */
	const DEVICES = array( 'mobile', 'desktop', 'unknown' );

	/**
	 * Max variant length (item 5 A/B slot).
	 */
	const MAX_VARIANT = 32;

	/**
	 * Transient store.
	 *
	 * @var callable
	 */
	private $store;

	/**
	 * Clock: today's 'Y-m-d' in the store timezone.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Session reader.
	 *
	 * @var callable
	 */
	private $session_get;

	/**
	 * Session writer.
	 *
	 * @var callable
	 */
	private $session_set;

	/**
	 * User-Agent provider.
	 *
	 * @var callable
	 */
	private $user_agent;

	/**
	 * Constructor. Every dependency is injectable; defaults are WP/WC.
	 *
	 * @param callable|null $store       Transient store.
	 * @param callable|null $now         Clock.
	 * @param callable|null $session_get Session reader.
	 * @param callable|null $session_set Session writer.
	 * @param callable|null $user_agent  UA provider.
	 */
	public function __construct( $store = null, $now = null, $session_get = null, $session_set = null, $user_agent = null ) {
		$this->store       = $store ? $store : array( __CLASS__, 'wp_store' );
		$this->now         = $now ? $now : array( __CLASS__, 'wp_today' );
		$this->session_get = $session_get ? $session_get : array( __CLASS__, 'wp_session_get' );
		$this->session_set = $session_set ? $session_set : array( __CLASS__, 'wp_session_set' );
		$this->user_agent  = $user_agent ? $user_agent : array( __CLASS__, 'wp_user_agent' );
	}

	/**
	 * Count one session for a step today. Returns false (and counts nothing)
	 * for an unknown step/device, a bot UA, or a repeat for this session today.
	 *
	 * @param string $step    One of STEPS.
	 * @param string $device  One of DEVICES.
	 * @param string $variant Optional A/B variant slug.
	 * @return bool Counted.
	 */
	public function record( string $step, string $device, string $variant = '' ): bool {
		if ( ! in_array( $step, self::STEPS, true ) || ! in_array( $device, self::DEVICES, true ) ) {
			return false;
		}
		if ( self::is_bot( (string) call_user_func( $this->user_agent ) ) ) {
			return false;
		}

		$today = (string) call_user_func( $this->now );
		$seen  = call_user_func( $this->session_get, self::SESSION_KEY );
		$seen  = is_array( $seen ) ? $seen : array();
		if ( isset( $seen[ $step ] ) && $seen[ $step ] === $today ) {
			return false;
		}

		// Mark the session first, so a transient failure cannot double count.
		$seen[ $step ] = $today;
		call_user_func( $this->session_set, self::SESSION_KEY, $seen );

		$counts = call_user_func( $this->store, 'get' );
		$counts = self::add_count( is_array( $counts ) ? $counts : array(), self::key( $today, $step, $device, self::sanitize_variant( $variant ) ) );
		call_user_func( $this->store, 'set', $counts );

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Pure helpers                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * UA matches the SEO bot token list. Soft dependency: when the SEO
	 * engine class is unavailable nothing is treated as a bot.
	 *
	 * @param string $user_agent UA.
	 * @return bool
	 */
	public static function is_bot( string $user_agent ): bool {
		if ( '' === $user_agent || ! class_exists( Bot_Crawl_Logger::class ) ) {
			return false;
		}

		return null !== Bot_Crawl_Logger::match( $user_agent );
	}

	/**
	 * Variant slug: lowercase `[a-z0-9_-]`, ≤ MAX_VARIANT, '' when none.
	 *
	 * @param string $variant Raw.
	 * @return string
	 */
	public static function sanitize_variant( string $variant ): string {
		$variant = strtolower( trim( $variant ) );
		$variant = (string) preg_replace( '/[^a-z0-9_\-]/', '', $variant );

		return substr( $variant, 0, self::MAX_VARIANT );
	}

	/**
	 * Transient key.
	 *
	 * @param string $date    'Y-m-d'.
	 * @param string $step    Step.
	 * @param string $device  Device.
	 * @param string $variant Variant.
	 * @return string
	 */
	public static function key( string $date, string $step, string $device, string $variant = '' ): string {
		return $date . '|' . $step . '|' . $device . '|' . $variant;
	}

	/**
	 * Pure: add one session, enforce the key cap (oldest date keys dropped
	 * first — keys sort by their `YYYY-MM-DD` prefix).
	 *
	 * @param array  $counts Current map.
	 * @param string $key    key().
	 * @param int    $by     Increment.
	 * @return array
	 */
	public static function add_count( array $counts, string $key, int $by = 1 ): array {
		if ( isset( $counts[ $key ] ) ) {
			$counts[ $key ] = (int) $counts[ $key ] + $by;

			return $counts;
		}
		if ( count( $counts ) >= self::MAX_KEYS ) {
			ksort( $counts, SORT_STRING );
			$counts = array_slice( $counts, count( $counts ) - self::MAX_KEYS + 1, null, true );
		}
		$counts[ $key ] = $by;

		return $counts;
	}

	/**
	 * Pure: transient map → sorted payload rows.
	 *
	 * @param array $counts Map.
	 * @return array [ { date, step, device, variant, sessions } ].
	 */
	public static function rows( array $counts ): array {
		ksort( $counts, SORT_STRING );
		$rows = array();
		foreach ( $counts as $key => $sessions ) {
			$parts = explode( '|', (string) $key, 4 );
			if ( 4 !== count( $parts ) || (int) $sessions <= 0 ) {
				continue;
			}
			$rows[] = array(
				'date'     => $parts[0],
				'step'     => $parts[1],
				'device'   => $parts[2],
				'variant'  => $parts[3],
				'sessions' => (int) $sessions,
			);
		}

		return $rows;
	}

	/**
	 * Pure: add payload rows back into a map (failed flush; cap enforced).
	 *
	 * @param array $counts Map.
	 * @param array $rows   rows() output.
	 * @return array
	 */
	public static function merge_rows( array $counts, array $rows ): array {
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['date'], $row['step'], $row['device'], $row['sessions'] ) ) {
				continue;
			}
			$sessions = (int) $row['sessions'];
			if ( $sessions <= 0 ) {
				continue;
			}
			$key    = self::key( (string) $row['date'], (string) $row['step'], (string) $row['device'], (string) ( $row['variant'] ?? '' ) );
			$counts = self::add_count( $counts, $key, $sessions );
		}

		return $counts;
	}

	/* ------------------------------------------------------------------ */
	/* WP / WC defaults                                                     */
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
	 * Today in the store timezone.
	 *
	 * @return string 'Y-m-d'
	 */
	public static function wp_today(): string {
		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * WC session read (null when there is no session object).
	 *
	 * @param string $key Session key.
	 * @return mixed
	 */
	public static function wp_session_get( string $key ) {
		$session = self::wc_session();

		return $session ? $session->get( $key ) : null;
	}

	/**
	 * WC session write (no-op without a session object).
	 *
	 * @param string $key   Session key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function wp_session_set( string $key, $value ): void {
		$session = self::wc_session();
		if ( $session ) {
			$session->set( $key, $value );
		}
	}

	/**
	 * Request UA.
	 *
	 * @return string
	 */
	public static function wp_user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against a fixed regex only; never stored or echoed.
	}

	/**
	 * The WC session object, or null.
	 *
	 * @return object|null
	 */
	private static function wc_session() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
			return null;
		}
		if ( ! method_exists( $wc->session, 'get' ) || ! method_exists( $wc->session, 'set' ) ) {
			return null;
		}

		return $wc->session;
	}
}
