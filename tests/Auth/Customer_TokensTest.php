<?php
/**
 * Unit tests for the pure decision logic in StoreDash_Customer_Tokens.
 *
 * These three functions are the security-critical, WordPress-free parts of
 * headless customer auth:
 *
 * - roles_are_eligible() is the sole gate stopping an administrator or
 *   shop_manager account from obtaining (or using) a storefront customer token.
 *   A mis-mapping here is a privilege-escalation bug, so every combination is
 *   pinned.
 * - should_refresh_expiry() decides when the sliding session writes to the DB;
 *   getting it wrong either logs active customers out or writes on every request.
 * - hash_token() must stay a stable, deterministic digest — changing it silently
 *   invalidates every issued token.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

// The class is pure static logic guarded by ABSPATH; define the guard and the
// WordPress functions its file-scope needs so it loads in the standalone harness.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

require_once __DIR__ . '/../../inc/core/class-storedash-customer-tokens.php';

/**
 * @covers StoreDash_Customer_Tokens::roles_are_eligible
 * @covers StoreDash_Customer_Tokens::should_refresh_expiry
 * @covers StoreDash_Customer_Tokens::hash_token
 */
class Customer_TokensTest extends TestCase {

	/**
	 * The default allowlist used by the plugin.
	 *
	 * @return string[]
	 */
	private function allowed(): array {
		return array( 'customer', 'subscriber' );
	}

	/**
	 * Role sets that must be accepted.
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public static function eligible_role_sets(): array {
		return array(
			'customer only'          => array( array( 'customer' ) ),
			'subscriber only'        => array( array( 'subscriber' ) ),
			'customer + subscriber'  => array( array( 'customer', 'subscriber' ) ),
		);
	}

	/**
	 * Role sets that must be rejected.
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public static function ineligible_role_sets(): array {
		return array(
			'no roles at all'            => array( array() ),
			'administrator'              => array( array( 'administrator' ) ),
			'shop_manager'               => array( array( 'shop_manager' ) ),
			'editor'                     => array( array( 'editor' ) ),
			'author'                     => array( array( 'author' ) ),
			'contributor'                => array( array( 'contributor' ) ),
			'unknown custom role'        => array( array( 'wholesale_buyer' ) ),
			// The critical case: a privileged role alongside an allowed one must
			// NOT slip through. Any "at least one allowed role" logic fails here.
			'customer AND administrator' => array( array( 'customer', 'administrator' ) ),
			'subscriber AND shop_manager' => array( array( 'subscriber', 'shop_manager' ) ),
		);
	}

	/**
	 * @dataProvider eligible_role_sets
	 *
	 * @param string[] $roles Roles under test.
	 */
	public function test_eligible_roles_are_accepted( array $roles ): void {
		$this->assertTrue(
			StoreDash_Customer_Tokens::roles_are_eligible( $roles, $this->allowed() ),
			'Expected roles ' . wp_json_encode_fallback( $roles ) . ' to be eligible.'
		);
	}

	/**
	 * @dataProvider ineligible_role_sets
	 *
	 * @param string[] $roles Roles under test.
	 */
	public function test_ineligible_roles_are_rejected( array $roles ): void {
		$this->assertFalse(
			StoreDash_Customer_Tokens::roles_are_eligible( $roles, $this->allowed() ),
			'Expected roles ' . wp_json_encode_fallback( $roles ) . ' to be rejected.'
		);
	}

	public function test_custom_allowlist_is_honoured(): void {
		$allowed = array( 'customer', 'wholesale_buyer' );

		$this->assertTrue( StoreDash_Customer_Tokens::roles_are_eligible( array( 'wholesale_buyer' ), $allowed ) );
		// Still fails closed for anything outside the (now different) allowlist.
		$this->assertFalse( StoreDash_Customer_Tokens::roles_are_eligible( array( 'subscriber' ), $allowed ) );
	}

	public function test_empty_allowlist_rejects_everything(): void {
		$this->assertFalse( StoreDash_Customer_Tokens::roles_are_eligible( array( 'customer' ), array() ) );
	}

	public function test_fresh_token_is_not_refreshed(): void {
		$ttl      = 2592000; // 30 days.
		$interval = 86400;   // 1 day.
		$now      = 1000000;

		// Just issued — the full window remains.
		$this->assertFalse(
			StoreDash_Customer_Tokens::should_refresh_expiry( $now + $ttl, $now, $ttl, $interval )
		);
	}

	public function test_token_refreshes_only_after_the_interval(): void {
		$ttl      = 2592000;
		$interval = 86400;
		$now      = 1000000;

		// Used 1 hour after issue: still inside the interval, no write.
		$this->assertFalse(
			StoreDash_Customer_Tokens::should_refresh_expiry( $now + $ttl - 3600, $now, $ttl, $interval )
		);

		// Used just under a day after issue: still no write.
		$this->assertFalse(
			StoreDash_Customer_Tokens::should_refresh_expiry( $now + $ttl - ( $interval - 1 ), $now, $ttl, $interval )
		);

		// Used just over a day after issue: refresh.
		$this->assertTrue(
			StoreDash_Customer_Tokens::should_refresh_expiry( $now + $ttl - ( $interval + 1 ), $now, $ttl, $interval )
		);

		// Long-lived session near the end of its window: refresh.
		$this->assertTrue(
			StoreDash_Customer_Tokens::should_refresh_expiry( $now + 60, $now, $ttl, $interval )
		);
	}

	public function test_expired_token_is_never_refreshed(): void {
		$ttl      = 2592000;
		$interval = 86400;
		$now      = 1000000;

		// Exactly at expiry, and past it: resolve() rejects these; refreshing would
		// resurrect a dead session.
		$this->assertFalse( StoreDash_Customer_Tokens::should_refresh_expiry( $now, $now, $ttl, $interval ) );
		$this->assertFalse( StoreDash_Customer_Tokens::should_refresh_expiry( $now - 1, $now, $ttl, $interval ) );
		$this->assertFalse( StoreDash_Customer_Tokens::should_refresh_expiry( $now - 999999, $now, $ttl, $interval ) );
	}

	public function test_hash_is_deterministic_sha256(): void {
		$token = 'abcdefghijklmnopqrstuvwxyz0123456789';

		$hash = StoreDash_Customer_Tokens::hash_token( $token );

		$this->assertSame( hash( 'sha256', $token ), $hash );
		$this->assertSame( 64, strlen( $hash ) );
		// Stable across calls — the lookup depends on it.
		$this->assertSame( $hash, StoreDash_Customer_Tokens::hash_token( $token ) );
	}

	public function test_hash_differs_per_token(): void {
		$this->assertNotSame(
			StoreDash_Customer_Tokens::hash_token( 'token-a' ),
			StoreDash_Customer_Tokens::hash_token( 'token-b' )
		);
	}

	public function test_hash_never_contains_the_token(): void {
		$token = 'super-secret-token-value';

		$this->assertStringNotContainsString( $token, StoreDash_Customer_Tokens::hash_token( $token ) );
	}
}

/**
 * Minimal json encoder for assertion messages in the standalone harness.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_fallback( $value ): string {
	return (string) wp_json_encode_safe( $value );
}

/**
 * @param mixed $value Value to encode.
 * @return string
 */
function wp_json_encode_safe( $value ): string {
	$encoded = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone test harness, no WordPress loaded.
	return false === $encoded ? '[unencodable]' : $encoded;
}
