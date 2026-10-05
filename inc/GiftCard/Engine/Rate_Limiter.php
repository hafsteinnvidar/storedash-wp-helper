<?php
/**
 * Failed gift card apply attempts limiter (contract D).
 *
 * More than MAX failed applies within WINDOW seconds per WC session OR per IP
 * blocks further applies until the window passes. Counters live in
 * transients; the window starts at the first failure.
 *
 * @package StoreDash\GiftCard\Engine
 * @since   1.24.0
 */

namespace StoreDash\GiftCard\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brute-force guard.
 *
 * @since 1.24.0
 */
class Rate_Limiter {

	/**
	 * Failures allowed per window.
	 */
	const MAX = 5;

	/**
	 * Window in seconds.
	 */
	const WINDOW = 600;

	/**
	 * Counter keys for the current shopper (session + IP).
	 *
	 * @return string[]
	 */
	public function current_keys(): array {
		$keys = array();
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_id = (string) WC()->session->get_customer_id();
			if ( '' !== $session_id ) {
				$keys[] = 'storedash_gc_rl_s_' . md5( $session_id );
			}
		}
		$ip = class_exists( '\WC_Geolocation' ) ? (string) \WC_Geolocation::get_ip_address() : '';
		if ( '' !== $ip ) {
			$keys[] = 'storedash_gc_rl_i_' . md5( $ip );
		}
		return $keys;
	}

	/**
	 * Whether any key has used up its failures.
	 *
	 * @param string[] $keys Counter keys.
	 * @return bool
	 */
	public function is_limited( array $keys ): bool {
		$now = $this->now();
		foreach ( $keys as $key ) {
			$entry = $this->read( $key );
			if ( $entry && $now - $entry['first'] < self::WINDOW && $entry['count'] >= self::MAX ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record one failed attempt on every key.
	 *
	 * @param string[] $keys Counter keys.
	 */
	public function record_failure( array $keys ): void {
		$now = $this->now();
		foreach ( $keys as $key ) {
			$entry = $this->read( $key );
			if ( ! $entry || $now - $entry['first'] >= self::WINDOW ) {
				$entry = array(
					'count' => 0,
					'first' => $now,
				);
			}
			++$entry['count'];
			$this->write( $key, $entry, max( 1, self::WINDOW - ( $now - $entry['first'] ) ) );
		}
	}

	/**
	 * Read a counter.
	 *
	 * @param string $key Key.
	 * @return array|null { count, first }
	 */
	protected function read( string $key ) {
		$entry = get_transient( $key );
		return ( is_array( $entry ) && isset( $entry['count'], $entry['first'] ) ) ? $entry : null;
	}

	/**
	 * Write a counter.
	 *
	 * @param string $key   Key.
	 * @param array  $entry { count, first }.
	 * @param int    $ttl   Seconds.
	 */
	protected function write( string $key, array $entry, int $ttl ): void {
		set_transient( $key, $entry, $ttl );
	}

	/**
	 * Current unix time.
	 *
	 * @return int
	 */
	protected function now(): int {
		return time();
	}
}
