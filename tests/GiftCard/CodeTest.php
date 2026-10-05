<?php
/**
 * Gift card code format, normalisation, hashing and encryption.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\GiftCard\Code;

require_once __DIR__ . '/gift-card-stubs.php';

/**
 * @covers \StoreDash\GiftCard\Code
 */
class Gift_Card_CodeTest extends TestCase {

	public function test_generate_is_16_chars_from_the_alphabet_without_0_1_o_i_l() {
		for ( $i = 0; $i < 200; $i++ ) {
			$code = Code::generate();
			$this->assertSame( 16, strlen( $code ) );
			$this->assertSame( 16, strspn( $code, '23456789ABCDEFGHJKMNPQRSTVWXYZ' ) );
			$this->assertDoesNotMatchRegularExpression( '/[01OIL]/', $code );
			$this->assertTrue( Code::is_well_formed( $code ) );
		}
	}

	public function test_generate_does_not_repeat() {
		$codes = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$codes[ Code::generate() ] = true;
		}
		$this->assertCount( 500, $codes );
	}

	public function test_normalize_uppercases_and_strips_everything_else() {
		$this->assertSame( 'ABCDEFGHJKMNPQRS', Code::normalize( ' abcd-efgh jkmn_pqrs ' ) );
		$this->assertSame( '', Code::normalize( array( 'x' ) ) );
		$this->assertSame( '', Code::normalize( null ) );
	}

	public function test_format_and_last4() {
		$this->assertSame( 'ABCD-EFGH-JKMN-K9QZ', Code::format( 'ABCDEFGHJKMNK9QZ' ) );
		$this->assertSame( 'K9QZ', Code::last4( 'ABCDEFGHJKMNK9QZ' ) );
	}

	public function test_well_formed_rejects_wrong_length_and_ambiguous_chars() {
		$this->assertFalse( Code::is_well_formed( 'ABCD' ) );
		$this->assertFalse( Code::is_well_formed( 'ABCDEFGHJKMNK9Q0' ) );
		$this->assertFalse( Code::is_well_formed( 'ABCDEFGHJKMNK9QO' ) );
	}

	public function test_hash_is_keyed_hmac_sha256() {
		$hash = Code::hash( 'ABCDEFGHJKMNK9QZ', 'key-1' );
		$this->assertSame( hash_hmac( 'sha256', 'ABCDEFGHJKMNK9QZ', 'key-1' ), $hash );
		$this->assertSame( 64, strlen( $hash ) );
		$this->assertNotSame( $hash, Code::hash( 'ABCDEFGHJKMNK9QZ', 'key-2' ) );
		// Default key = wp_salt('auth').
		$this->assertSame( hash_hmac( 'sha256', 'X', wp_salt( 'auth' ) ), Code::hash( 'X' ) );
	}

	public function test_encrypt_round_trips_and_never_contains_the_code() {
		$enc = Code::encrypt( 'ABCDEFGHJKMNK9QZ', 'secret' );
		$this->assertNotSame( '', $enc );
		$this->assertStringNotContainsString( 'ABCDEFGHJKMNK9QZ', $enc );
		$this->assertSame( 'ABCDEFGHJKMNK9QZ', Code::decrypt( $enc, 'secret' ) );
		$this->assertSame( '', Code::decrypt( $enc, 'other-secret' ) );
		$this->assertSame( '', Code::decrypt( 'garbage' ) );
	}

	public function test_encrypt_uses_a_fresh_nonce() {
		$this->assertNotSame( Code::encrypt( 'ABCDEFGHJKMNK9QZ', 's' ), Code::encrypt( 'ABCDEFGHJKMNK9QZ', 's' ) );
	}
}
