<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class HappyPathTest extends WC_Spart_IntegrationTestCase {

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
}
