<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class ApiErrorTest extends WC_Spart_IntegrationTestCase {

	/**
	 * @dataProvider error_scenarios
	 */
	public function test_error_status_returns_friendly_failure( string $scenario, string $expected_substring ): void {
		$this->set_stub_scenario( $scenario );
		$order   = $this->make_order();
		$gateway = new WC_Gateway_Spart();

		$result = $gateway->process_payment( $order->get_id() );
		$this->assertSame( 'fail', $result['result'] );

		$notices  = wc_get_notices( 'error' );
		$messages = array_map( static fn ( $n ) => $n['notice'] ?? '', $notices );
		wc_clear_notices();

		$this->assertNotEmpty( $notices, 'A failure notice must be queued for the customer.' );
		$this->assertStringContainsString(
			$expected_substring,
			implode( ' ', $messages ),
			'Customer-facing message must match the error mapping table.'
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function error_scenarios(): array {
		return array(
			'auth (401)'       => array( 'error_401', 'try another method' ),
			'validation (400)' => array( 'error_400', 'invalid' ),
			'server (500)'     => array( 'error_500', 'trouble right now' ),
		);
	}
}
