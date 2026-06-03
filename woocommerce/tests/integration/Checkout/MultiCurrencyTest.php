<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class MultiCurrencyTest extends WC_Spart_IntegrationTestCase {

	/**
	 * @dataProvider currencies
	 */
	public function test_each_currency_round_trips( string $currency, string $amount ): void {
		$this->set_stub_scenario( 'happy' );
		$order = $this->make_order( $amount, $currency );

		$result = ( new WC_Gateway_Spart() )->process_payment( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );

		$recorded = $this->stub_recorded_requests();
		$this->assertSame( strtoupper( $currency ), $recorded[0]['body']['total']['currency'] );
		// SDK serialises Money.value as a raw JSON number, so json_decode in the
		// stub returns a float. Use assertEquals for loose numeric comparison.
		$this->assertEquals( (float) $amount, $recorded[0]['body']['total']['value'] );
	}

	/**
	 * WC's `woocommerce_price_num_decimals` defaults to 2 and applies to every currency,
	 * so JPY's amount is sent as "1500.00" rather than the canonical "1500". Zero-decimal
	 * lexeme handling is exercised at the SDK level (see SDK Money tests).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function currencies(): array {
		return array(
			'USD' => array( 'USD', '99.99' ),
			'EUR' => array( 'EUR', '49.50' ),
			'GBP' => array( 'GBP', '12.34' ),
			'JPY' => array( 'JPY', '1500.00' ),
		);
	}
}
