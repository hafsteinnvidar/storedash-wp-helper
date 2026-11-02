<?php
/**
 * Unit tests for the variation enrichment in StoreDash_Webhook_Payload_Modifier:
 * variation webhooks gain the variations-REST image/gallery fields, strictly
 * additively, and only fields WooCommerce actually returns.
 *
 * @package StoreDash\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

require_once __DIR__ . '/../../inc/services/webhooks/webhook-payload-modifier.php';

/**
 * @covers StoreDash_Webhook_Payload_Modifier
 */
class Webhook_Payload_ModifierTest extends TestCase {

	public function test_copies_image_and_gallery_fields_from_the_variation_shape(): void {
		$payload = array(
			'id'     => 11,
			'type'   => 'variation',
			'images' => array( array( 'id' => 1 ) ),
		);
		$rest    = array(
			'id'                => 11,
			'image'             => array( 'id' => 1, 'src' => 'https://shop.test/1.jpg' ),
			'gallery_image_ids' => array( 5, 6 ),
			'gallery_images'    => array( array( 'id' => 5, 'src' => 'https://shop.test/5.jpg' ) ),
			'sku'               => 'NOT-COPIED',
		);

		$out = StoreDash_Webhook_Payload_Modifier::merge_variation_fields( $payload, $rest );

		$this->assertSame( array( 5, 6 ), $out['gallery_image_ids'] );
		$this->assertSame( $rest['image'], $out['image'] );
		$this->assertSame( $rest['gallery_images'], $out['gallery_images'] );
		// Additive: existing keys kept, unrelated REST keys not copied.
		$this->assertSame( $payload['images'], $out['images'] );
		$this->assertArrayNotHasKey( 'sku', $out );
	}

	public function test_keeps_an_empty_gallery_as_a_known_empty_list(): void {
		$out = StoreDash_Webhook_Payload_Modifier::merge_variation_fields(
			array( 'type' => 'variation' ),
			array( 'gallery_image_ids' => array() )
		);
		$this->assertSame( array(), $out['gallery_image_ids'] );
	}

	public function test_older_woocommerce_without_galleries_leaves_the_field_absent(): void {
		$out = StoreDash_Webhook_Payload_Modifier::merge_variation_fields(
			array( 'type' => 'variation' ),
			array( 'image' => null )
		);
		$this->assertArrayNotHasKey( 'gallery_image_ids', $out );
		$this->assertArrayHasKey( 'image', $out );
		$this->assertNull( $out['image'] );
	}

	public function test_non_variation_product_payloads_are_untouched(): void {
		$modifier = new StoreDash_Webhook_Payload_Modifier();
		$payload  = array( 'id' => 3, 'type' => 'variable', 'images' => array() );
		$this->assertSame( $payload, $modifier->modify_webhook_payload( $payload, 'product', 3, 99 ) );
	}

	public function test_variation_payload_unchanged_when_destination_cannot_be_checked(): void {
		// No wc_get_webhook() in this test runtime → not a proven StoreDash
		// destination → nothing added.
		$modifier = new StoreDash_Webhook_Payload_Modifier();
		$payload  = array( 'id' => 11, 'type' => 'variation' );
		$this->assertSame( $payload, $modifier->modify_webhook_payload( $payload, 'product', 11, 99 ) );
	}
}
