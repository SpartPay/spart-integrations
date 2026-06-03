<?php
/**
 * Integration test: failed Spart checkout must destroy the pending order.
 *
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class OrderDisposalOnFailureTest extends WC_Spart_IntegrationTestCase {

	/**
	 * @dataProvider failure_scenarios
	 */
	public function test_failure_deletes_order_and_restores_stock( string $scenario ): void {
		$this->set_stub_scenario( $scenario );

		$ctx           = $this->make_order_with_reduced_managed_stock( 2 );
		$order         = $ctx['order'];
		$product       = $ctx['product'];
		$initial_stock = $ctx['initial_stock'];

		$reduced_product = \wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $reduced_product );
		$this->assertLessThan(
			$initial_stock,
			(int) $reduced_product->get_stock_quantity(),
			'sanity: stock was reduced at order creation'
		);

		$order_id = $order->get_id();

		$gateway = new WC_Gateway_Spart();
		$result  = $gateway->process_payment( $order_id );
		wc_clear_notices();

		$this->assertSame( 'fail', $result['result'] );

		\wp_cache_flush();
		$fetched = \wc_get_order( $order_id );
		$this->assertFalse( $fetched, 'failed-checkout order must be deleted' );

		$refetched_product = \wc_get_product( $product->get_id() );
		$this->assertNotFalse( $refetched_product, 'product must still exist' );
		$this->assertSame(
			$initial_stock,
			(int) $refetched_product->get_stock_quantity(),
			'managed stock must be fully restored after disposal'
		);
	}

	public function test_success_path_does_not_delete_order(): void {
		$this->set_stub_scenario( 'happy' );

		$ctx      = $this->make_order_with_reduced_managed_stock( 2 );
		$order_id = $ctx['order']->get_id();

		$gateway = new WC_Gateway_Spart();
		$result  = $gateway->process_payment( $order_id );

		$this->assertSame( 'success', $result['result'] );

		\wp_cache_flush();
		$fetched = \wc_get_order( $order_id );
		$this->assertNotFalse( $fetched, 'successful checkout must leave the order in place' );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function failure_scenarios(): array {
		return array(
			'server error (500)' => array( 'error_500' ),
			'auth error (401)'   => array( 'error_401' ),
			'validation (400)'   => array( 'error_400' ),
		);
	}
}
