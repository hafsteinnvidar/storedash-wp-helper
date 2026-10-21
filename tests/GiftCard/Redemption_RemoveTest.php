<?php
/**
 * Redemption::remove — by card id or by last4, incl. an all-digit last4.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;
use StoreDash\GiftCard\Card_Ledger;
use StoreDash\GiftCard\Engine\Redemption;

require_once __DIR__ . '/gift-card-stubs.php';

/**
 * Ledger whose cards are a fixed id => last4 map.
 */
class Remove_Stub_Ledger extends Card_Ledger {
	/** @var array id => last4 */
	public $last4 = array();

	public function get_card( int $id ) {
		return isset( $this->last4[ $id ] ) ? (object) array(
			'id'         => $id,
			'code_last4' => $this->last4[ $id ],
		) : null;
	}
}

/**
 * Redemption with the applied ids in memory instead of the WC session.
 */
class Memory_Redemption extends Redemption {
	/** @var int[] */
	public $ids = array();

	public function applied_ids(): array {
		return $this->ids;
	}

	protected function set_applied_ids( array $ids ): void {
		$this->ids = array_values( $ids );
	}
}

/**
 * @covers \StoreDash\GiftCard\Engine\Redemption::remove
 */
class Redemption_RemoveTest extends TestCase {

	private function redemption( array $last4 ): Memory_Redemption {
		$ledger          = new Remove_Stub_Ledger();
		$ledger->last4   = $last4;
		$redemption      = new Memory_Redemption( $ledger );
		$redemption->ids = array_keys( $last4 );
		return $redemption;
	}

	public function test_removes_by_card_id() {
		$r = $this->redemption( array( 7 => 'K9QZ', 9 => 'ABCD' ) );
		$this->assertTrue( $r->remove( 7 ) );
		$this->assertSame( array( 9 ), $r->ids );
		$this->assertTrue( $r->remove( '9' ) );
		$this->assertSame( array(), $r->ids );
	}

	public function test_removes_by_letter_last4_in_any_case() {
		$r = $this->redemption( array( 7 => 'K9QZ', 9 => 'ABCD' ) );
		$this->assertTrue( $r->remove( 'k9qz' ) );
		$this->assertSame( array( 9 ), $r->ids );
	}

	public function test_removes_by_all_digit_last4() {
		$r = $this->redemption( array( 7 => '2345', 9 => 'ABCD' ) );
		$this->assertTrue( $r->remove( '2345' ) );
		$this->assertSame( array( 9 ), $r->ids );
	}

	public function test_unknown_ref_removes_nothing() {
		$r = $this->redemption( array( 7 => '2345' ) );
		$this->assertFalse( $r->remove( '9999' ) );
		$this->assertFalse( $r->remove( 8 ) );
		$this->assertSame( array( 7 ), $r->ids );
	}
}
