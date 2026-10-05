<?php
/**
 * Store API registrations must never reject a checkout request.
 *
 * On the checkout route the registered `extensions` schema doubles as the
 * REQUEST schema (CheckoutSchema exposes it as an input arg; WordPress
 * validates nested properties whatever `readonly` says). Live test: every
 * `POST /wc/store/v1/checkout` failed with "extensions > storedash_credit >
 * enabled is not of type boolean". Checkout schemas must therefore be empty.
 *
 * @package StoreDash\Tests\GiftCard
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/gift-card-stubs.php';
require_once __DIR__ . '/../../inc/Credit/Ledger.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Eligibility.php';
require_once __DIR__ . '/../../inc/Credit/Engine/Spend_Handler.php';
require_once __DIR__ . '/../../inc/Credit/Blocks/Store_API_Integration.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! isset( $GLOBALS['__test_store_api_endpoint_data'] ) ) {
	$GLOBALS['__test_store_api_endpoint_data'] = array();
}

if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
	function woocommerce_store_api_register_endpoint_data( $args ) {
		$GLOBALS['__test_store_api_endpoint_data'][] = $args;
	}
}

/**
 * @covers \StoreDash\Credit\Blocks\Store_API_Integration::register
 * @covers \StoreDash\GiftCard\Blocks\Store_API_Integration::register
 */
class Store_API_SchemaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__test_store_api_endpoint_data'] = array();
	}

	/**
	 * Registrations by endpoint for one integration.
	 *
	 * @param object $integration Integration with register().
	 * @return array endpoint => args
	 */
	private function registrations( $integration ): array {
		$integration->register();
		$out = array();
		foreach ( $GLOBALS['__test_store_api_endpoint_data'] as $args ) {
			$out[ $args['endpoint'] ] = $args;
		}
		return $out;
	}

	private function assert_checkout_accepts_any_input( array $registrations, string $namespace ): void {
		$this->assertArrayHasKey( 'checkout', $registrations, 'checkout responses still carry the data' );
		$this->assertSame( $namespace, $registrations['checkout']['namespace'] );
		$this->assertIsCallable( $registrations['checkout']['data_callback'] );
		$this->assertSame( array(), call_user_func( $registrations['checkout']['schema_callback'] ), 'checkout schema is also the request schema: must not constrain input' );

		$this->assertArrayHasKey( 'cart', $registrations );
		$cart_schema = call_user_func( $registrations['cart']['schema_callback'] );
		$this->assertNotEmpty( $cart_schema, 'cart schema stays documented' );
		foreach ( $cart_schema as $name => $prop ) {
			$this->assertTrue( ! empty( $prop['readonly'] ), "cart property {$name} must be readonly" );
		}
	}

	public function test_rewards_credit_checkout_schema_is_empty() {
		$integration = new \StoreDash\Credit\Blocks\Store_API_Integration( new \StoreDash\Credit\Engine\Spend_Handler() );
		$this->assert_checkout_accepts_any_input( $this->registrations( $integration ), 'storedash_credit' );
	}

	public function test_gift_card_checkout_schema_is_empty() {
		$integration = new \StoreDash\GiftCard\Blocks\Store_API_Integration( new \StoreDash\GiftCard\Engine\Redemption( new \StoreDash\GiftCard\Card_Ledger(), new \StoreDash\GiftCard\Engine\Rate_Limiter() ) );
		$registrations = $this->registrations( $integration );
		$this->assert_checkout_accepts_any_input( $registrations, 'storedash_gift_card' );
		$this->assertArrayHasKey( 'cart-item', $registrations );
		$this->assertArrayHasKey( 'product', $registrations );
	}
}
