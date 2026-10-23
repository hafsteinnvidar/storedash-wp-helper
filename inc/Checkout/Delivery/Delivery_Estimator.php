<?php
/**
 * Delivery date estimator (pure PHP, unit-tested).
 *
 * No WordPress or WooCommerce calls: only `DateTimeImmutable` and
 * `DateTimeZone`. `Delivery_Estimate` (the WP glue) feeds it the store's
 * "now", the resolved rule and the merchant's cutoff / ship days / holidays
 * from `Config`, then formats the result with `wp_date()`.
 *
 * Algorithm:
 *
 *   1. Ship date — start on `$now`'s calendar day; at/after the cutoff time
 *      move to the next day; then roll forward while the day is not a ship
 *      day or is a holiday.
 *   2. Delivery — add `min_days` / `max_days` *business days* to the ship
 *      date (a business day = weekday in `ship_days` and not a holiday).
 *      0 days = the ship date itself.
 *
 * Empty `ship_days` means Mon–Fri. Every loop is bounded (MAX_ITERATIONS);
 * when a bound is hit the estimator degrades to the start date instead of
 * throwing, so a nonsensical config (every day a holiday) renders a date
 * rather than breaking checkout.
 *
 * @package StoreDash\Checkout
 * @since   1.29.0
 */

namespace StoreDash\Checkout\Delivery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Estimator.
 *
 * @since 1.29.0
 */
class Delivery_Estimator {

	/**
	 * Hard cap on min/max days (mirrors Config::MAX_DAYS).
	 */
	const MAX_DAYS = 60;

	/**
	 * Loop guard for every day-walking loop.
	 */
	const MAX_ITERATIONS = 120;

	/**
	 * Fallback ship days (ISO weekdays, Mon–Fri).
	 *
	 * @var int[]
	 */
	const DEFAULT_SHIP_DAYS = array( 1, 2, 3, 4, 5 );

	/**
	 * Rule kinds.
	 */
	const KIND_DELIVERY = 'delivery';
	const KIND_PICKUP   = 'pickup';
	const KIND_HIDE     = 'hide';

	/**
	 * Estimate ship + delivery window.
	 *
	 * @param \DateTimeImmutable $now       Store-local "now".
	 * @param array              $rule      `{ min_days, max_days }` (kind ignored here).
	 * @param string             $cutoff    `HH:MM` (24h). Invalid → no cutoff.
	 * @param array              $ship_days ISO weekdays 1..7; empty → Mon–Fri.
	 * @param array              $holidays  `YYYY-MM-DD` strings.
	 * @return array{ship_date: \DateTimeImmutable, min_date: \DateTimeImmutable, max_date: \DateTimeImmutable}
	 */
	public static function estimate( \DateTimeImmutable $now, array $rule, string $cutoff, array $ship_days, array $holidays ): array {
		$ship_days = self::normalize_ship_days( $ship_days );
		$holidays  = self::normalize_holidays( $holidays );

		$min = self::clamp_days( $rule['min_days'] ?? 0 );
		$max = self::clamp_days( $rule['max_days'] ?? $min );
		if ( $max < $min ) {
			$max = $min;
		}

		$start = $now->setTime( 0, 0, 0 );
		if ( self::is_at_or_after_cutoff( $now, $cutoff ) ) {
			$start = $start->modify( '+1 day' );
		}

		$ship = self::next_ship_day( $start, $ship_days, $holidays );
		if ( null === $ship ) {
			// Every reachable day blocked: degrade to the start date.
			return array(
				'ship_date' => $start,
				'min_date'  => $start,
				'max_date'  => $start,
			);
		}

		return array(
			'ship_date' => $ship,
			'min_date'  => self::add_business_days( $ship, $min, $ship_days, $holidays ),
			'max_date'  => self::add_business_days( $ship, $max, $ship_days, $holidays ),
		);
	}

	/**
	 * Pick the rule for a WC rate id.
	 *
	 * Precedence: exact `rate_id` match (`flat_rate:3`) → method-prefix rule
	 * (`flat_rate`) → `$default` as a delivery rule. A `hide` rule yields null.
	 *
	 * @param string $rate_id WC rate id (`method_id:instance_id`).
	 * @param array  $rules   `[{rate_id, min_days, max_days, kind}]`.
	 * @param array  $default `{min_days, max_days}`.
	 * @return array|null `{min_days, max_days, kind}` or null when hidden.
	 */
	public static function resolve_rule( string $rate_id, array $rules, array $default ) {
		$rate_id = trim( $rate_id );
		$method  = $rate_id;
		$colon   = strpos( $rate_id, ':' );
		if ( false !== $colon ) {
			$method = substr( $rate_id, 0, $colon );
		}

		$matched = null;
		$prefix  = null;
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['rate_id'] ) || ! is_string( $rule['rate_id'] ) ) {
				continue;
			}
			if ( '' !== $rate_id && $rule['rate_id'] === $rate_id ) {
				$matched = $rule;
				break;
			}
			if ( null === $prefix && '' !== $method && $rule['rate_id'] === $method ) {
				$prefix = $rule;
			}
		}
		if ( null === $matched ) {
			$matched = $prefix;
		}
		if ( null === $matched ) {
			$matched = array(
				'min_days' => $default['min_days'] ?? 0,
				'max_days' => $default['max_days'] ?? 0,
				'kind'     => self::KIND_DELIVERY,
			);
		}

		$kind = isset( $matched['kind'] ) && is_string( $matched['kind'] ) ? $matched['kind'] : self::KIND_DELIVERY;
		if ( self::KIND_HIDE === $kind ) {
			return null;
		}
		if ( self::KIND_PICKUP !== $kind ) {
			$kind = self::KIND_DELIVERY;
		}

		$min = self::clamp_days( $matched['min_days'] ?? 0 );
		$max = self::clamp_days( $matched['max_days'] ?? $min );

		return array(
			'min_days' => $min,
			'max_days' => max( $min, $max ),
			'kind'     => $kind,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * `$now` is at or past `HH:MM`. An unparsable cutoff never rolls.
	 *
	 * @param \DateTimeImmutable $now    Now.
	 * @param string             $cutoff `HH:MM`.
	 * @return bool
	 */
	private static function is_at_or_after_cutoff( \DateTimeImmutable $now, string $cutoff ): bool {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $cutoff ), $m ) ) {
			return false;
		}
		$cutoff_minutes = ( (int) $m[1] * 60 ) + (int) $m[2];
		$now_minutes    = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );

		return $now_minutes >= $cutoff_minutes;
	}

	/**
	 * First ship day on/after `$date`, or null when the loop guard trips.
	 *
	 * @param \DateTimeImmutable $date      Start (midnight).
	 * @param int[]              $ship_days Normalized ship days.
	 * @param array              $holidays  Holiday set (`Y-m-d` => true).
	 * @return \DateTimeImmutable|null
	 */
	private static function next_ship_day( \DateTimeImmutable $date, array $ship_days, array $holidays ) {
		for ( $i = 0; $i < self::MAX_ITERATIONS; $i++ ) {
			if ( self::is_business_day( $date, $ship_days, $holidays ) ) {
				return $date;
			}
			$date = $date->modify( '+1 day' );
		}

		return null;
	}

	/**
	 * Walk `$count` business days forward; on guard trip return the day reached.
	 *
	 * @param \DateTimeImmutable $from      Ship date (a business day).
	 * @param int                $count     Business days to add.
	 * @param int[]              $ship_days Normalized ship days.
	 * @param array              $holidays  Holiday set.
	 * @return \DateTimeImmutable
	 */
	private static function add_business_days( \DateTimeImmutable $from, int $count, array $ship_days, array $holidays ): \DateTimeImmutable {
		$date  = $from;
		$added = 0;
		for ( $i = 0; $i < self::MAX_ITERATIONS && $added < $count; $i++ ) {
			$date = $date->modify( '+1 day' );
			if ( self::is_business_day( $date, $ship_days, $holidays ) ) {
				++$added;
			}
		}

		return $date;
	}

	/**
	 * Weekday in ship days and not a holiday.
	 *
	 * @param \DateTimeImmutable $date      Day.
	 * @param int[]              $ship_days Normalized ship days.
	 * @param array              $holidays  Holiday set.
	 * @return bool
	 */
	private static function is_business_day( \DateTimeImmutable $date, array $ship_days, array $holidays ): bool {
		if ( ! in_array( (int) $date->format( 'N' ), $ship_days, true ) ) {
			return false;
		}

		return ! isset( $holidays[ $date->format( 'Y-m-d' ) ] );
	}

	/**
	 * Ints 1..7, unique; empty → Mon–Fri.
	 *
	 * @param array $ship_days Raw.
	 * @return int[]
	 */
	private static function normalize_ship_days( array $ship_days ): array {
		$days = array();
		foreach ( $ship_days as $day ) {
			if ( is_numeric( $day ) ) {
				$day = (int) $day;
				if ( $day >= 1 && $day <= 7 ) {
					$days[ $day ] = $day;
				}
			}
		}

		return $days ? array_values( $days ) : self::DEFAULT_SHIP_DAYS;
	}

	/**
	 * `Y-m-d` lookup set.
	 *
	 * @param array $holidays Raw.
	 * @return array
	 */
	private static function normalize_holidays( array $holidays ): array {
		$set = array();
		foreach ( $holidays as $holiday ) {
			if ( is_string( $holiday ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $holiday ) ) {
				$set[ $holiday ] = true;
			}
		}

		return $set;
	}

	/**
	 * 0..MAX_DAYS.
	 *
	 * @param mixed $days Raw.
	 * @return int
	 */
	private static function clamp_days( $days ): int {
		$days = is_numeric( $days ) ? (int) $days : 0;

		return max( 0, min( self::MAX_DAYS, $days ) );
	}
}
