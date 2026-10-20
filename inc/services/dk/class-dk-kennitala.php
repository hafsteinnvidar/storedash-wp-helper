<?php
/**
 * Kennitala (Icelandic national ID) normalisation + checksum.
 *
 * Pure functions, no WordPress calls — unit-tested standalone.
 *
 * Format: DDMMYY-NNCK (10 digits, dash optional). Individuals start with a day
 * 01–31; companies add 40 (41–71). The check digit (9th) is mod-11 over the
 * first 8 digits with weights 3 2 7 6 5 4 3 2; a remainder of 1 is impossible
 * (no valid number), 0 → check digit 0. The 10th digit is the century (8, 9, 0).
 *
 * @package StoreDash\Services\DK
 * @since   1.27.0
 */

namespace StoreDash\Services\DK;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DK Kennitala Class
 */
class DK_Kennitala {

	/**
	 * Meta key on the order (and the customer) — one of the keys the
	 * storedash-worker invoice builder reads first (`KENNITALA_KEYS`).
	 */
	const META_KEY = 'billing_kennitala';

	/**
	 * Mod-11 weights for the first eight digits.
	 *
	 * @var int[]
	 */
	const WEIGHTS = array( 3, 2, 7, 6, 5, 4, 3, 2 );

	/**
	 * Strip everything but digits. Returns '' unless exactly 10 digits remain.
	 *
	 * @param mixed $value Raw input.
	 * @return string 10 digits or ''.
	 */
	public static function normalize( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$digits = preg_replace( '/\D/', '', (string) $value );
		return 10 === strlen( $digits ) ? $digits : '';
	}

	/**
	 * Checksum + date-part sanity check.
	 *
	 * @param mixed $value Raw or normalised input.
	 * @return bool
	 */
	public static function is_valid( $value ): bool {
		$digits = self::normalize( $value );
		if ( '' === $digits ) {
			return false;
		}

		$day = (int) substr( $digits, 0, 2 );
		if ( $day > 40 ) {
			$day -= 40; // Company kennitala.
		}
		$month = (int) substr( $digits, 2, 2 );
		if ( $day < 1 || $day > 31 || $month < 1 || $month > 12 ) {
			return false;
		}

		$sum = 0;
		foreach ( self::WEIGHTS as $i => $weight ) {
			$sum += (int) $digits[ $i ] * $weight;
		}
		$remainder = $sum % 11;
		$check     = 0 === $remainder ? 0 : 11 - $remainder;
		if ( 10 === $check ) {
			return false;
		}
		if ( (int) $digits[8] !== $check ) {
			return false;
		}

		return in_array( $digits[9], array( '8', '9', '0' ), true );
	}

	/**
	 * Display form `DDMMYY-NNCK`.
	 *
	 * @param string $digits 10 digits.
	 * @return string
	 */
	public static function format( string $digits ): string {
		$digits = self::normalize( $digits );
		return '' === $digits ? '' : substr( $digits, 0, 6 ) . '-' . substr( $digits, 6 );
	}
}
