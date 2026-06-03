<?php
/**
 * Integration test for the payment.authorized event branch.
 *
 * OrderSync::on_payment_authorized() adds an order note containing
 * `Spart authorized payment <partId> for <formatted amount>` and does
 * NOT change order status — payment.authorized is informational only;
 * status transitions happen on order.completed.
 *
 * Implements PR3 task t7-event-tests (WebhookPaymentAuthorizedTest row
 * of the integration matrix in
 * the webhook receiver design).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class WebhookPaymentAuthorizedTest extends WC_Spart_IntegrationTestCase {

	public function test_payment_authorized_adds_note_and_leaves_status_unchanged(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order           = $this->make_order( '129.99' );
		$status_before   = $order->get_status();
		$payment_part_id = '11111111-2222-3333-4444-555555555555';

		$response = $this->deliver_webhook(
			'payment.authorized',
			$this->compose_session_id( $order->get_id() ),
			$this->payment_envelope_payload( $order, $payment_part_id, 64.50 )
		);

		$this->assertSame( 204, $response['status'], 'Body was: ' . $response['body'] );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertSame(
			$status_before,
			$reloaded->get_status(),
			'payment.authorized must NOT change order status.'
		);

		$notes        = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$note_strings = array_map( static fn( $n ) => (string) $n->content, $notes );
		$matched      = array_filter(
			$note_strings,
			static fn( string $s ) =>
				str_contains( $s, 'Spart authorized payment' ) && str_contains( $s, $payment_part_id )
		);
		$this->assertNotEmpty(
			$matched,
			"Expected an order note mentioning 'Spart authorized payment' and the payment part id; got: "
				. implode( ' | ', $note_strings )
		);

		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}
}
