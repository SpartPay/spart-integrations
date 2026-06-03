<?php
/**
 * Integration test for the order.canceled event branch.
 *
 * OrderSync::on_order_canceled() calls WC's update_status('cancelled', ...).
 * WC's wc_maybe_increase_stock_levels() (hooked to
 * woocommerce_order_status_cancelled) restores stock on the way out
 * provided the order's _order_stock_reduced meta flag is yes — which it
 * is, because make_order_with_reduced_managed_stock() called
 * wc_reduce_stock_levels() during setup.
 *
 * Implements PR3 task t7-event-tests (WebhookOrderCanceledTest row of
 * the integration matrix in
 * the webhook receiver design).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class WebhookOrderCanceledTest extends WC_Spart_IntegrationTestCase {

	public function test_order_canceled_transitions_to_cancelled_and_restores_stock(): void {
		$this->set_signing_secret( 'whsec_test' );
		$fixture = $this->make_order_with_reduced_managed_stock( 10 );
		$order   = $fixture['order'];
		$product = $fixture['product'];

		$reduced = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $reduced );
		$this->assertSame(
			9,
			(int) $reduced->get_stock_quantity(),
			'Sanity: wc_reduce_stock_levels() must drop the level by 1 before the test starts.'
		);

		$response = $this->deliver_webhook(
			'order.canceled',
			$this->compose_session_id( $order->get_id() ),
			$this->order_envelope_payload( $order, 'canceled' )
		);

		$this->assertSame( 204, $response['status'], 'Body was: ' . $response['body'] );

		$reloaded_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded_order );
		$this->assertSame( 'cancelled', $reloaded_order->get_status() );

		$reloaded_product = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product::class, $reloaded_product );
		$this->assertSame(
			10,
			(int) $reloaded_product->get_stock_quantity(),
			'Stock must return to its pre-order level after cancellation.'
		);

		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}
}
