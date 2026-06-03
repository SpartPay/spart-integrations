<?php
/**
 * Integration test for idempotent webhook replay.
 *
 * Spart retries deliveries when the receiver returns >=500 or fails to
 * respond. The receiver MUST treat the second arrival of an
 * already-applied delivery_id as a benign no-op:
 *
 *   - Response: 200 + body {deduped: true}
 *   - Side-effects: none (no order mutation, no extra notes)
 *   - State: dedupe row stays 'applied'
 *   - attempt_count: NOT incremented — the receiver short-circuits
 *     BEFORE increment_attempt when the existing state is in
 *     {applied, skipped, errored} (see WebhookReceiver.php:117-119).
 *
 * Implements PR3 task t7-dedupe-tests (the "applied → replay" branch
 * of the integration matrix in
 * the webhook receiver design
 * — line 78 of the design's lifecycle pseudocode).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class WebhookDedupeTest extends WC_Spart_IntegrationTestCase {

	public function test_replay_of_applied_delivery_returns_deduped_and_does_not_remutate(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order       = $this->make_order( '129.99' );
		$delivery_id = 'spart_delivery_test_' . bin2hex( random_bytes( 8 ) );
		$session_id  = $this->compose_session_id( $order->get_id() );
		$payload     = $this->order_envelope_payload( $order, 'completed' );

		$first = $this->deliver_webhook(
			'order.completed',
			$session_id,
			$payload,
			1,
			$delivery_id
		);

		$this->assertSame( 204, $first['status'], 'First delivery body was: ' . $first['body'] );
		$this->assertSame( $delivery_id, $first['delivery_id'] );
		$this->assert_dedupe_state( $delivery_id, 'applied' );

		$first_row = $this->find_dedupe_row( $delivery_id );
		$this->assertNotNull( $first_row );
		$this->assertSame( 1, (int) $first_row['attempt_count'] );

		$reloaded_after_first = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded_after_first );
		$this->assertSame( 'processing', $reloaded_after_first->get_status() );
		$notes_after_first = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$second = $this->deliver_webhook(
			'order.completed',
			$session_id,
			$payload,
			2,
			$delivery_id
		);

		$this->assertSame( 200, $second['status'], 'Replay body was: ' . $second['body'] );
		$decoded = json_decode( $second['body'], true );
		$this->assertSame( array( 'deduped' => true ), $decoded );

		$second_row = $this->find_dedupe_row( $delivery_id );
		$this->assertNotNull( $second_row );
		$this->assertSame(
			'applied',
			(string) $second_row['state'],
			'Replay must not transition the dedupe state out of applied.'
		);
		$this->assertSame(
			1,
			(int) $second_row['attempt_count'],
			'Replay of an already-applied delivery must NOT bump attempt_count — the receiver short-circuits before increment_attempt.'
		);

		$reloaded_after_second = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded_after_second );
		$this->assertSame(
			'processing',
			$reloaded_after_second->get_status(),
			'Replay must not re-mutate the order.'
		);
		$this->assertSame(
			count( $notes_after_first ),
			count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
			'Replay must not add new order notes.'
		);
	}
}
