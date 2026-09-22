<?php
/**
 * Rewards credit rule matcher.
 *
 * Picks the single winning earn rule for an order: enabled, every condition
 * passes, highest `priority` wins, ties broken by lowest `supabase_id`. No
 * stacking (contract A).
 *
 * Pure function — unit tested. The order context is a plain array so callers
 * (and tests) decide how to resolve roles, first-order status, etc.
 *
 * @package StoreDash\Credit\Engine
 * @since   1.17.0
 */

namespace StoreDash\Credit\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule selection.
 *
 * @since 1.17.0
 */
class Rule_Matcher {

	/**
	 * Pick the winning rule.
	 *
	 * @param array[] $rules   Rules (Rules_Repository::normalize() shape).
	 * @param array   $context {
	 *     @type float         $order_total    Order total (what the customer paid).
	 *     @type string[]      $roles          Customer role slugs ([] for guests).
	 *     @type int[]         $category_ids   Category term ids present in the order (with ancestors resolved by the caller if desired).
	 *     @type int[]         $brand_ids      Brand term ids present in the order.
	 *     @type int           $now            Unix timestamp (UTC).
	 *     @type bool|callable $is_first_order Whether this is the customer's first order; a callable is invoked lazily.
	 * }
	 * @return array|null Winning rule or null.
	 */
	public static function pick( array $rules, array $context ) {
		$candidates = array();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( ! self::matches( $rule, $context ) ) {
				continue;
			}
			$candidates[] = $rule;
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				$pa = (int) ( $a['priority'] ?? 0 );
				$pb = (int) ( $b['priority'] ?? 0 );
				if ( $pa !== $pb ) {
					return $pb <=> $pa; // Highest priority first.
				}
				return strcmp( (string) ( $a['supabase_id'] ?? '' ), (string) ( $b['supabase_id'] ?? '' ) );
			}
		);

		return $candidates[0];
	}

	/**
	 * Whether a rule's conditions pass for the context.
	 *
	 * @param array $rule    Rule.
	 * @param array $context Context (see pick()).
	 * @return bool
	 */
	public static function matches( array $rule, array $context ): bool {
		$c   = isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ? $rule['conditions'] : array();
		$now = isset( $context['now'] ) ? (int) $context['now'] : time();

		$total = (float) ( $context['order_total'] ?? 0 );
		if ( isset( $c['min_order_total'] ) && null !== $c['min_order_total'] && $total + 0.00001 < (float) $c['min_order_total'] ) {
			return false;
		}
		if ( isset( $c['max_order_total'] ) && null !== $c['max_order_total'] && $total - 0.00001 > (float) $c['max_order_total'] ) {
			return false;
		}

		if ( ! empty( $c['start_date'] ) ) {
			$start = strtotime( (string) $c['start_date'] . ' UTC' );
			if ( false !== $start && $now < $start ) {
				return false;
			}
		}
		if ( ! empty( $c['end_date'] ) ) {
			$end = strtotime( (string) $c['end_date'] . ' UTC' );
			if ( false !== $end && $now > $end ) {
				return false;
			}
		}

		if ( ! empty( $c['customer_roles'] ) && is_array( $c['customer_roles'] ) ) {
			$roles = isset( $context['roles'] ) ? (array) $context['roles'] : array();
			if ( empty( array_intersect( array_map( 'strval', $c['customer_roles'] ), array_map( 'strval', $roles ) ) ) ) {
				return false;
			}
		}

		if ( ! empty( $c['category_ids'] ) && is_array( $c['category_ids'] ) ) {
			$present = isset( $context['category_ids'] ) ? array_map( 'intval', (array) $context['category_ids'] ) : array();
			if ( empty( array_intersect( array_map( 'intval', $c['category_ids'] ), $present ) ) ) {
				return false;
			}
		}

		if ( ! empty( $c['brand_ids'] ) && is_array( $c['brand_ids'] ) ) {
			$present = isset( $context['brand_ids'] ) ? array_map( 'intval', (array) $context['brand_ids'] ) : array();
			if ( empty( array_intersect( array_map( 'intval', $c['brand_ids'] ), $present ) ) ) {
				return false;
			}
		}

		if ( ! empty( $c['first_order_only'] ) ) {
			$first = $context['is_first_order'] ?? false;
			if ( is_callable( $first ) ) {
				$first = (bool) call_user_func( $first );
			}
			if ( ! $first ) {
				return false;
			}
		}

		return true;
	}
}
