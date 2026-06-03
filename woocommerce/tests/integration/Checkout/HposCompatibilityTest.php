<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class HposCompatibilityTest extends WC_Spart_IntegrationTestCase {

	public function test_process_payment_works_under_current_storage_mode(): void {
		$this->set_stub_scenario( 'happy' );
		$order    = $this->make_order();
		$order_id = $order->get_id();

		$result = ( new WC_Gateway_Spart() )->process_payment( $order_id );
		$this->assertSame( 'success', $result['result'] );

		$reloaded = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertSame( $order_id, $reloaded->get_id() );
		$this->assertSame( 'USD', $reloaded->get_currency() );
	}

	public function test_hpos_mode_is_what_ci_expects(): void {
		$expected_mode = getenv( 'WP_SPART_HPOS_MODE' );
		if ( ! is_string( $expected_mode ) || '' === $expected_mode ) {
			$expected_mode = 'on';
		}

		$hpos_enabled = function_exists( 'wc_get_container' )
			&& wc_get_container()
				->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
				->custom_orders_table_usage_is_enabled();

		if ( 'on' === $expected_mode ) {
			$this->assertTrue( $hpos_enabled, 'HPOS expected ON for this CI matrix slot.' );
		} else {
			$this->assertFalse( $hpos_enabled, 'HPOS expected OFF for this CI matrix slot.' );
		}
	}
}
