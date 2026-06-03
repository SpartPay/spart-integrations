<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class TimeoutTest extends WC_Spart_IntegrationTestCase {

	public function test_timeout_returns_friendly_failure(): void {
		$this->set_stub_scenario( 'timeout' );
		$order   = $this->make_order();
		$gateway = new WC_Gateway_Spart();

		$start   = microtime( true );
		$result  = $gateway->process_payment( $order->get_id() );
		$elapsed = microtime( true ) - $start;

		$this->assertSame( 'fail', $result['result'] );
		$this->assertSame( '', $result['redirect'] );
		$this->assertLessThan( 35.0, $elapsed, 'WpHttpClient must time out before stub returns at 35s.' );
		$this->assertGreaterThanOrEqual( 28.0, $elapsed, 'Should respect ~30s SDK timeout.' );

		wc_clear_notices();
	}
}
