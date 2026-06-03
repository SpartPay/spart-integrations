<?php
/**
 * Integration test for the intent.created event branch.
 *
 * OrderSync::on_intent_created() is a logging-only no-op — intent
 * creation is observable in logs but does not mutate the WC order
 * (status, meta, notes). The dedupe row still moves to `applied`
 * because the receiver always calls mark_applied() after a clean
 * apply() return.
 *
 * Implements PR3 task t7-event-tests (WebhookIntentCreatedTest row of
 * the integration matrix in
 * the webhook receiver design).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class WebhookIntentCreatedTest extends WC_Spart_IntegrationTestCase {

	public function test_intent_created_is_a_noop_against_the_order_and_marks_applied(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order             = $this->make_order( '129.99' );
		$status_before     = $order->get_status();
		$note_count_before = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$response = $this->deliver_webhook(
			'intent.created',
			$this->compose_session_id( $order->get_id() ),
			$this->intent_envelope_payload( $order )
		);

		$this->assertSame( 204, $response['status'], 'Body was: ' . $response['body'] );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertSame(
			$status_before,
			$reloaded->get_status(),
			'intent.created must not change order status.'
		);
		$this->assertSame(
			$note_count_before,
			count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
			'intent.created must not add order notes.'
		);

		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}
}
