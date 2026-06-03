<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class FreeOrderTest extends WC_Spart_IntegrationTestCase {

	public function test_free_order_returns_friendly_failure_without_calling_stub(): void {
		$this->set_stub_scenario( 'happy' );
		$order = $this->make_order( '0.00' );

		$result = ( new WC_Gateway_Spart() )->process_payment( $order->get_id() );

		$this->assertSame( 'fail', $result['result'] );
		$this->assertEmpty( $this->stub_recorded_requests(), 'Free orders must not hit the API.' );

		$notices  = wc_get_notices( 'error' );
		$messages = array_map( static fn ( $n ) => $n['notice'] ?? '', $notices );
		wc_clear_notices();

		$this->assertStringContainsString( 'zero total', implode( ' ', $messages ) );
	}
}
