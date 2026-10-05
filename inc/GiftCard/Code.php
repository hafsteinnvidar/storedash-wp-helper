<?php
/**
 * Gift card codes (contract "Code format").
 *
 * `XXXX-XXXX-XXXX-XXXX` from the Crockford base32 alphabet minus 0 and 1, so
 * no 0/O/1/I/L confusion. The raw code is never stored: the cards table keeps
 * `code_hash` (HMAC-SHA256 keyed by wp_salt('auth'), UNIQUE) for lookups and
 * `code_enc` (secretbox / AES-256-GCM keyed by wp_salt('secure_auth')) so the
 * merchant can resend the email. Never log a raw code.
 *
 * Generation / normalisation / hashing / encryption are pure given a key —
 * unit tested.
 *
 * @package StoreDash\GiftCard
 * @since   1.24.0
 */

namespace StoreDash\GiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Code helpers.
 *
 * @since 1.24.0
 */
class Code {

	/**
	 * Allowed characters (30): Crockford base32 without 0 and 1.
	 */
	const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Characters per code.
	 */
	const LENGTH = 16;

	/**
	 * Option holding a fingerprint of the hashing key, so a salt rotation (which
	 * silently invalidates every code) is detected and logged.
	 */
	const KEY_FP_OPTION = 'storedash_gift_card_key_fp';

	/**
	 * Generate a new normalized code (16 chars, no dashes).
	 *
	 * Rejection sampling over random_bytes keeps the distribution uniform.
	 *
	 * @return string
	 */
	public static function generate(): string {
		$alphabet = self::ALPHABET;
		$size     = strlen( $alphabet );
		$limit    = 256 - ( 256 % $size ); // 240: largest multiple of 30 below 256.
		$out      = '';
		$count    = 0;

		while ( $count < self::LENGTH ) {
			foreach ( str_split( random_bytes( 32 ) ) as $char ) {
				$byte = ord( $char );
				if ( $byte < $limit ) {
					$out .= $alphabet[ $byte % $size ];
					++$count;
				}
				if ( $count >= self::LENGTH ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Normalise shopper input: uppercase, keep only [A-Z0-9].
	 *
	 * @param mixed $input Raw input.
	 * @return string
	 */
	public static function normalize( $input ): string {
		if ( ! is_scalar( $input ) ) {
			return '';
		}
		return (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $input ) );
	}

	/**
	 * Whether a normalized string can be a code at all (cheap pre-check).
	 *
	 * @param string $normalized Normalized code.
	 * @return bool
	 */
	public static function is_well_formed( string $normalized ): bool {
		return self::LENGTH === strlen( $normalized ) && strspn( $normalized, self::ALPHABET ) === self::LENGTH;
	}

	/**
	 * Display format XXXX-XXXX-XXXX-XXXX.
	 *
	 * @param string $normalized Normalized code.
	 * @return string
	 */
	public static function format( string $normalized ): string {
		return implode( '-', str_split( $normalized, 4 ) );
	}

	/**
	 * Last four characters (display only).
	 *
	 * @param string $normalized Normalized code.
	 * @return string
	 */
	public static function last4( string $normalized ): string {
		return substr( $normalized, -4 );
	}

	/**
	 * Lookup hash.
	 *
	 * @param string      $normalized Normalized code.
	 * @param string|null $key        HMAC key (defaults to wp_salt('auth')).
	 * @return string 64 hex chars.
	 */
	public static function hash( string $normalized, $key = null ): string {
		$key = null === $key ? self::hash_key() : (string) $key;
		return hash_hmac( 'sha256', $normalized, $key );
	}

	/**
	 * Encrypt a code for resend.
	 *
	 * Format: "s1:" + base64(nonce . secretbox) with sodium, or
	 * "g1:" + base64(iv . tag . ciphertext) with openssl AES-256-GCM.
	 *
	 * @param string      $normalized Normalized code.
	 * @param string|null $secret     Key material (defaults to wp_salt('secure_auth')).
	 * @return string '' when no cipher is available.
	 */
	public static function encrypt( string $normalized, $secret = null ): string {
		$key = self::enc_key( $secret );

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( 24 );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext transport, not obfuscation.
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $normalized, $nonce, $key ) );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $normalized, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $cipher ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext transport, not obfuscation.
				return 'g1:' . base64_encode( $iv . $tag . $cipher );
			}
		}

		return '';
	}

	/**
	 * Decrypt a stored code.
	 *
	 * @param string      $enc    Stored value.
	 * @param string|null $secret Key material (defaults to wp_salt('secure_auth')).
	 * @return string Normalized code, '' on failure.
	 */
	public static function decrypt( string $enc, $secret = null ): string {
		if ( strlen( $enc ) < 4 ) {
			return '';
		}
		$key = self::enc_key( $secret );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext transport, not obfuscation.
		$raw = base64_decode( substr( $enc, 3 ), true );
		if ( false === $raw ) {
			return '';
		}

		$prefix = substr( $enc, 0, 3 );
		if ( 's1:' === $prefix && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > 24 ) {
			$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), $key );
			return false === $plain ? '' : (string) $plain;
		}
		if ( 'g1:' === $prefix && function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 28 ) {
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? '' : (string) $plain;
		}

		return '';
	}

	/**
	 * 32-byte encryption key derived from secret material.
	 *
	 * @param string|null $secret Secret (defaults to wp_salt('secure_auth')).
	 * @return string
	 */
	protected static function enc_key( $secret ): string {
		$secret = null === $secret ? wp_salt( 'secure_auth' ) : (string) $secret;
		return hash( 'sha256', 'storedash_gift_card|' . $secret, true );
	}

	/**
	 * HMAC key for lookups.
	 *
	 * @return string
	 */
	protected static function hash_key(): string {
		return wp_salt( 'auth' );
	}

	/**
	 * Record / compare the hashing-key fingerprint.
	 *
	 * Rotating WordPress salts changes every code hash, so every existing card
	 * stops matching. Stamp a fingerprint on first use and log loudly when it
	 * changes so the merchant can restore the old salts.
	 *
	 * @return bool False when the key changed since cards were issued.
	 */
	public static function check_key_fingerprint(): bool {
		$fp     = substr( hash( 'sha256', 'fp|' . self::hash_key() ), 0, 16 );
		$stored = (string) get_option( self::KEY_FP_OPTION, '' );
		if ( '' === $stored ) {
			update_option( self::KEY_FP_OPTION, $fp, false );
			return true;
		}
		if ( hash_equals( $stored, $fp ) ) {
			return true;
		}
		\StoreDash_Helpers::log_message( 'Gift cards: WordPress AUTH_SALT changed since cards were issued — existing gift card codes no longer match. Restore the previous salts in wp-config.php.', 'error' );
		return false;
	}
}
