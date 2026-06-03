<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class ReplayTest extends WC_Spart_IntegrationTestCase {

	public function test_idempotent_replay_returns_same_redirect(): void {
		$this->set_stub_scenario( 'replay' );
		$order   = $this->make_order();
		$gateway = new WC_Gateway_Spart();

		$first  = $gateway->process_payment( $order->get_id() );
		$second = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $first['result'] );
		$this->assertSame( 'success', $second['result'] );
		$this->assertSame( $first['redirect'], $second['redirect'] );
	}
}
