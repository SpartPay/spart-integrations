<?php
/**
 * Integration test for the order.expired event branch.
 *
 * OrderSync::on_order_expired() calls update_status('failed', ...). The
 * spec deliberately treats expired-intent the same as a failed payment
 * — WC's `failed` status is the canonical "payment did not succeed"
 * sink and is recoverable (the merchant can re-create an intent and
 * the customer can retry).
 *
 * Implements PR3 task t7-event-tests (WebhookOrderExpiredTest row of
 * the integration matrix in
 * the webhook receiver design).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class WebhookOrderExpiredTest extends WC_Spart_IntegrationTestCase {

	public function test_order_expired_transitions_to_failed(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order = $this->make_order( '129.99' );

		$response = $this->deliver_webhook(
			'order.expired',
			$this->compose_session_id( $order->get_id() ),
			$this->order_envelope_payload( $order, 'expired' )
		);

		$this->assertSame( 204, $response['status'], 'Body was: ' . $response['body'] );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertSame( 'failed', $reloaded->get_status() );

		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}
}
