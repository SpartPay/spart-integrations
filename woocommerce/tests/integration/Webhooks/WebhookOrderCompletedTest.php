<?php
/**
 * Integration tests for the order.completed event branch.
 *
 * Covers both halves of WC's payment_complete() routing:
 *   - shippable line items → 'processing'
 *   - virtual+downloadable only → 'completed'
 *
 * Implements PR3 task t7-event-tests (WebhookOrderCompletedTest row of
 * the integration matrix in
 * the webhook receiver design).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use Spart\WooCommerce\Webhooks\WebhookReceiver;

final class WebhookOrderCompletedTest extends WC_Spart_IntegrationTestCase {

	public function test_shippable_order_transitions_to_processing(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order = $this->make_order( '129.99' );

		$response = $this->deliver_webhook(
			'order.completed',
			$this->compose_session_id( $order->get_id() ),
			$this->order_envelope_payload( $order, 'completed' )
		);

		$this->assertSame( 204, $response['status'], 'Body was: ' . $response['body'] );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertSame( 'processing', $reloaded->get_status() );
		$this->assertSame(
			$response['delivery_id'],
			(string) $reloaded->get_meta( WebhookReceiver::ORDER_DEDUPE_META_KEY )
		);
		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}

	public function test_digital_order_transitions_to_completed(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order = $this->make_digital_order( '49.99' );

		$response = $this->deliver_webhook(
			'order.completed',
			$this->compose_session_id( $order->get_id() ),
			$this->order_envelope_payload( $order, 'completed' )
		);

		$this->assertSame( 204, $response['status'], 'Body was: ' . $response['body'] );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertSame( 'completed', $reloaded->get_status() );
		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}
}
