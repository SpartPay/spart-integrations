<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class HappyPathTest extends WC_Spart_IntegrationTestCase {

	private const TEST_IMAGE_FILENAME  = 'spart-product-thumbnail.gif';
	private const TEST_IMAGE_MIME_TYPE = 'image/gif';
	private const TEST_IMAGE_BASE64    = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
	private const PRODUCT_IMAGE_SIZE   = 'medium';

	public function test_checkout_redirects_to_stub_spart_on_happy_path(): void {
		$this->set_stub_scenario( 'happy' );
		$order   = $this->make_order( '129.99' );
		$gateway = new WC_Gateway_Spart();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'http://stub-spart:8080/checkout/', $result['redirect'] );

		$recorded = $this->stub_recorded_requests();
		$this->assertCount( 1, $recorded );
		$this->assertSame( '/api/intents', $recorded[0]['path'] );
		$this->assertSame( 'USD', $recorded[0]['body']['total']['currency'] );
		// SDK serialises Money.value as a raw JSON number, so json_decode in the
		// stub returns a float. Use assertEquals for loose numeric comparison.
		$this->assertEquals( 129.99, $recorded[0]['body']['total']['value'] );
	}

	public function test_checkout_sends_product_image_uri(): void {
		$this->set_stub_scenario( 'happy' );
		$attachment_id = $this->create_test_image_attachment();

		try {
			$order = $this->make_order( '19.99' );
			$items = $order->get_items( 'line_item' );
			$item  = reset( $items );

			$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );
			$product = $item->get_product();
			$this->assertInstanceOf( \WC_Product::class, $product );

			$product->set_image_id( $attachment_id );
			$product->save();

			$expected_image_uri = wp_get_attachment_image_url(
				$attachment_id,
				self::PRODUCT_IMAGE_SIZE
			);
			$this->assertIsString( $expected_image_uri );

			$result = ( new WC_Gateway_Spart() )->process_payment( $order->get_id() );
			$this->assertSame( 'success', $result['result'] );

			$recorded = $this->stub_recorded_requests();
			$this->assertCount( 1, $recorded );
			$this->assertSame(
				$expected_image_uri,
				$recorded[0]['body']['lineItems'][0]['imageUri'] ?? null
			);
		} finally {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	private function create_test_image_attachment(): int {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a fixed one-pixel GIF test fixture.
		$image_bytes = base64_decode( self::TEST_IMAGE_BASE64, true );
		$this->assertIsString( $image_bytes );

		$upload = wp_upload_bits( self::TEST_IMAGE_FILENAME, null, $image_bytes );
		$this->assertFalse( $upload['error'] );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => self::TEST_IMAGE_MIME_TYPE,
				'post_title'     => 'Spart product thumbnail',
				'post_status'    => 'inherit',
				'guid'           => $upload['url'],
			),
			$upload['file']
		);
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		update_attached_file( $attachment_id, $upload['file'] );
		return $attachment_id;
	}
}
