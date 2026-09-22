<?php
/**
 * Unit tests for StoreDash_Auth_Handler::method_allowed_for_permission().
 *
 * This is the sole gate enforcing a WooCommerce API key's read/write scope
 * against the HTTP method — it stops a read-only key (whose user has
 * manage_woocommerce) from driving writes on the storedash/v1 routes. It is
 * pure decision logic with no WordPress or instance state, so this table-driven
 * test pins every permission x method cell to guard against a silent
 * privilege-escalation regression from a future mis-mapping.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

// The handler file self-instantiates at file scope (StoreDash_Auth_Handler::instance()),
// whose constructor registers WordPress hooks. Stub those registrars so the file
// loads in the standalone (non-WordPress) harness; we then call the pure static
// gate directly.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

require_once __DIR__ . '/../../inc/core/class-storedash-auth-handler.php';

/**
 * @covers StoreDash_Auth_Handler::method_allowed_for_permission
 */
class Method_Allowed_For_PermissionTest extends TestCase {

	/**
	 * Every method the gate reasons about.
	 *
	 * @return string[]
	 */
	private function all_methods(): array {
		return array( 'GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE' );
	}

	/**
	 * permission => list of methods that MUST be allowed. Every other method in
	 * all_methods() must be denied.
	 *
	 * @return array<string, string[]>
	 */
	public function permissionMatrixProvider(): array {
		$reads  = array( 'GET', 'HEAD', 'OPTIONS' );
		$writes = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

		return array(
			'read scope allows only reads'          => array( 'read', $reads ),
			'write scope allows only writes'        => array( 'write', $writes ),
			'read_write scope allows everything'    => array( 'read_write', array_merge( $reads, $writes ) ),
			// Fail-closed: unknown/empty/garbage scopes permit reads only.
			'unknown scope falls back to reads'     => array( 'bogus', $reads ),
			'empty scope falls back to reads'       => array( '', $reads ),
		);
	}

	/**
	 * @dataProvider permissionMatrixProvider
	 *
	 * @param string   $permission Key permission scope.
	 * @param string[] $allowed    Methods that must be allowed for that scope.
	 */
	public function test_permission_method_cells( string $permission, array $allowed ): void {
		foreach ( $this->all_methods() as $method ) {
			$expected = in_array( $method, $allowed, true );
			$actual   = StoreDash_Auth_Handler::method_allowed_for_permission( $method, $permission );

			$this->assertSame(
				$expected,
				$actual,
				sprintf(
					'permission "%s" x method "%s" expected %s',
					$permission,
					$method,
					$expected ? 'allowed' : 'denied'
				)
			);
		}
	}

	public function test_read_write_allows_all_known_methods(): void {
		foreach ( $this->all_methods() as $method ) {
			$this->assertTrue(
				StoreDash_Auth_Handler::method_allowed_for_permission( $method, 'read_write' ),
				sprintf( 'read_write must allow %s', $method )
			);
		}
	}

	public function test_read_scope_denies_every_write_method(): void {
		foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
			$this->assertFalse(
				StoreDash_Auth_Handler::method_allowed_for_permission( $method, 'read' ),
				sprintf( 'read scope must deny write method %s', $method )
			);
		}
	}
}
