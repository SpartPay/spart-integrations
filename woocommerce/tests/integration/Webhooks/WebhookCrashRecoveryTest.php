<?php
/**
 * Integration test for crash-and-retry recovery.
 *
 * When the OrderSync handler throws, WebhookReceiver catches the
 * Throwable, logs `webhook.handler_exception`, and returns 500
 * `{error: 'handler_exception'}`. Crucially it does NOT call
 * mark_errored — the dedupe row stays in `received` state with its
 * attempt_count at 1. This is by design: `received` rows are not in
 * the dedupe short-circuit allow-list (applied|skipped|errored), so
 * a Spart retry will re-enter the dispatch path, call
 * increment_attempt → 2, and try again. If the second attempt
 * succeeds (e.g. the underlying transient cause has cleared),
 * mark_applied transitions the row to `applied` and the attempt_count
 * accurately reflects that two attempts were needed.
 *
 * Crash injection is wired via the spart-test-crash-injector mu-plugin
 * (mapped from tests/integration/mu-plugins/ in .wp-env.json), which
 * reads the `spart_test_crash_count` option and throws once per
 * positive count. This indirection is necessary because the test
 * process and the REST handler run in separate PHP workers — closures
 * registered with add_action in the test process would not be visible
 * to the request handler.
 *
 * Implements PR3 task t7-dedupe-tests (the "received → retry → applied"
 * branch of the integration matrix in
 * the webhook receiver design
 * — see lines 416 of the design for the spart_webhook_before_apply
 * action seam contract).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class WebhookCrashRecoveryTest extends WC_Spart_IntegrationTestCase {

	private const CRASH_COUNT_OPTION = 'spart_test_crash_count';

	protected function tearDown(): void {
		delete_option( self::CRASH_COUNT_OPTION );
		parent::tearDown();
	}

	public function test_first_attempt_crashes_then_second_attempt_succeeds_with_attempt_count_two(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order       = $this->make_order( '129.99' );
		$delivery_id = 'spart_delivery_crash_' . bin2hex( random_bytes( 8 ) );
		$session_id  = $this->compose_session_id( $order->get_id() );
		$payload     = $this->order_envelope_payload( $order, 'completed' );

		update_option( self::CRASH_COUNT_OPTION, 1 );

		$first = $this->deliver_webhook(
			'order.completed',
			$session_id,
			$payload,
			1,
			$delivery_id
		);

		$this->assertSame(
			500,
			$first['status'],
			'First delivery must surface the handler exception as 500. Body was: ' . $first['body']
		);
		$this->assertSame(
			array( 'error' => 'handler_exception' ),
			json_decode( $first['body'], true )
		);

		$first_row = $this->find_dedupe_row( $delivery_id );
		$this->assertNotNull(
			$first_row,
			'Receiver must insert_received BEFORE dispatching, so the row exists even when apply throws.'
		);
		$this->assertSame(
			'received',
			(string) $first_row['state'],
			'Receiver must NOT transition to errored on uncaught Throwable — the row must stay in `received` so retries re-enter dispatch.'
		);
		$this->assertSame( 1, (int) $first_row['attempt_count'] );

		$reloaded_after_first = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded_after_first );
		$this->assertNotSame(
			'processing',
			$reloaded_after_first->get_status(),
			'Crashed apply() must not have transitioned the order — before_apply fires before any mutation.'
		);

		$this->assertSame(
			0,
			(int) get_option( self::CRASH_COUNT_OPTION, 0 ),
			'Sanity: the mu-plugin must have decremented the counter back to zero so the retry succeeds.'
		);

		$second = $this->deliver_webhook(
			'order.completed',
			$session_id,
			$payload,
			2,
			$delivery_id
		);

		$this->assertSame( 204, $second['status'], 'Second-attempt body was: ' . $second['body'] );

		$second_row = $this->find_dedupe_row( $delivery_id );
		$this->assertNotNull( $second_row );
		$this->assertSame( 'applied', (string) $second_row['state'] );
		$this->assertSame(
			2,
			(int) $second_row['attempt_count'],
			'Successful retry must reflect that two attempts were needed.'
		);

		$reloaded_after_second = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded_after_second );
		$this->assertSame( 'processing', $reloaded_after_second->get_status() );
	}
}
