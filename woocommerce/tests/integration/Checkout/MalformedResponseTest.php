<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class MalformedResponseTest extends WC_Spart_IntegrationTestCase {

	public function test_malformed_response_returns_generic_failure(): void {
		$this->set_stub_scenario( 'malformed' );
		$order   = $this->make_order();
		$gateway = new WC_Gateway_Spart();

		$result = $gateway->process_payment( $order->get_id() );
		$this->assertSame( 'fail', $result['result'] );

		$notices = wc_get_notices( 'error' );
		wc_clear_notices();
		$this->assertNotEmpty( $notices );
	}
}
