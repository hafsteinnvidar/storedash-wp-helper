<?php
/**
 * Unit tests for StoreDash\Services\DK\DK_Kennitala.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;
use StoreDash\Services\DK\DK_Kennitala;

require_once __DIR__ . '/dk-stubs.php';

/**
 * @covers \StoreDash\Services\DK\DK_Kennitala
 */
class DK_KennitalaTest extends TestCase {

	public function test_normalize_keeps_only_ten_digit_values(): void {
		$this->assertSame( '0101302989', DK_Kennitala::normalize( '010130-2989' ) );
		$this->assertSame( '0101302989', DK_Kennitala::normalize( ' 0101302989 ' ) );
		$this->assertSame( '', DK_Kennitala::normalize( 101302989 ), 'int drops the leading zero → 9 digits' );
		$this->assertSame( '5302697609', DK_Kennitala::normalize( 5302697609 ) );
		$this->assertSame( '', DK_Kennitala::normalize( '010130298' ) );
		$this->assertSame( '', DK_Kennitala::normalize( '01013029891' ) );
		$this->assertSame( '', DK_Kennitala::normalize( null ) );
		$this->assertSame( '', DK_Kennitala::normalize( array( '0101302989' ) ) );
	}

	public function test_valid_individual_and_company_numbers(): void {
		$this->assertTrue( DK_Kennitala::is_valid( '0101302989' ) );
		$this->assertTrue( DK_Kennitala::is_valid( '010130-2989' ) );
		$this->assertTrue( DK_Kennitala::is_valid( '5302697609' ), 'company kennitala (day + 40)' );
	}

	public function test_bad_check_digit_is_invalid(): void {
		$this->assertFalse( DK_Kennitala::is_valid( '0101302999' ) );
		$this->assertFalse( DK_Kennitala::is_valid( '1234567890' ) );
	}

	public function test_impossible_date_parts_are_invalid(): void {
		$this->assertFalse( DK_Kennitala::is_valid( '3201302989' ), 'day 32' );
		$this->assertFalse( DK_Kennitala::is_valid( '0113302989' ), 'month 13' );
		$this->assertFalse( DK_Kennitala::is_valid( '0001302989' ), 'day 0' );
	}

	public function test_century_digit_must_be_8_9_or_0(): void {
		$this->assertFalse( DK_Kennitala::is_valid( '0101302985' ) );
	}

	public function test_too_short_or_empty_is_invalid(): void {
		$this->assertFalse( DK_Kennitala::is_valid( '' ) );
		$this->assertFalse( DK_Kennitala::is_valid( '010130298' ) );
	}

	public function test_format_adds_the_dash(): void {
		$this->assertSame( '010130-2989', DK_Kennitala::format( '0101302989' ) );
		$this->assertSame( '', DK_Kennitala::format( 'abc' ) );
	}
}
