<?php
/**
 * Failed gift card apply attempts limiter (contract D).
 *
 * Failed applies are counted per WC session AND per IP. A key that reaches its
 * limit within WINDOW seconds blocks further applies until the window passes.
 * Session: 5 / 10 min (contract D). IP: 30 / 10 min — headless storefronts
 * proxy the Store API server-side, so many shoppers can share one IP (the
 * storefront should forward the shopper IP in X-Forwarded-For, which
 * WC_Geolocation reads). Codes carry ~78 bits, so this is defense in depth.
 * Limits are filterable via `storedash_gift_card_rate_limits`.
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
	 * Failures allowed per window, per session.
	 */
	const MAX_SESSION = 5;

	/**
	 * Failures allowed per window, per IP.
	 */
	const MAX_IP = 30;

	/**
	 * Window in seconds.
	 */
	const WINDOW = 600;

	/**
	 * Effective limits.
	 *
	 * @return array { session: int, ip: int, window: int }
	 */
	public function limits(): array {
		$limits = array(
			'session' => self::MAX_SESSION,
			'ip'      => self::MAX_IP,
			'window'  => self::WINDOW,
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'storedash_gift_card_rate_limits', $limits );
			if ( is_array( $filtered ) ) {
				foreach ( $limits as $key => $value ) {
					if ( isset( $filtered[ $key ] ) && (int) $filtered[ $key ] > 0 ) {
						$limits[ $key ] = (int) $filtered[ $key ];
					}
				}
			}
		}
		return $limits;
	}

	/**
	 * Counter keys for the current shopper.
	 *
	 * @return array { session?: string, ip?: string }
	 */
	public function current_keys(): array {
		$keys = array();
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_id = (string) WC()->session->get_customer_id();
			if ( '' !== $session_id ) {
				$keys['session'] = 'storedash_gc_rl_s_' . md5( $session_id );
			}
		}
		$ip = class_exists( '\WC_Geolocation' ) ? (string) \WC_Geolocation::get_ip_address() : '';
		if ( '' !== $ip ) {
			$keys['ip'] = 'storedash_gc_rl_i_' . md5( $ip );
		}
		return $keys;
	}

	/**
	 * Whether any key has used up its failures.
	 *
	 * @param array $keys type (session|ip) => counter key.
	 * @return bool
	 */
	public function is_limited( array $keys ): bool {
		$limits = $this->limits();
		$now    = $this->now();
		foreach ( $keys as $type => $key ) {
			$max   = 'ip' === $type ? $limits['ip'] : $limits['session'];
			$entry = $this->read( $key );
			if ( $entry && $now - $entry['first'] < $limits['window'] && $entry['count'] >= $max ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record one failed attempt on every key.
	 *
	 * @param array $keys type (session|ip) => counter key.
	 */
	public function record_failure( array $keys ): void {
		$window = $this->limits()['window'];
		$now    = $this->now();
		foreach ( $keys as $key ) {
			$entry = $this->read( $key );
			if ( ! $entry || $now - $entry['first'] >= $window ) {
				$entry = array(
					'count' => 0,
					'first' => $now,
				);
			}
			++$entry['count'];
			$this->write( $key, $entry, max( 1, $window - ( $now - $entry['first'] ) ) );
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
