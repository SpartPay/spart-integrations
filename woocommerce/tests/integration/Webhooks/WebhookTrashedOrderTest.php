<?php
/**
 * Integration test for the trashed-order skip branch.
 *
 * Per the PR6 spec amendment, an order in `trash` status is treated as
 * a distinct, idempotent skip outcome (REASON_ORDER_TRASHED, 200) —
 * NOT as a genuine 404. The merchant intentionally trashed a known
 * order; the dispatcher should record a dedupe row and stop retrying.
 * Restoring a trashed order from the bin should not silently re-apply
 * old Spart webhooks against it; this test pins that policy.
 *
 * Implements PR3 task t7-skip-tests (WebhookTrashedOrderTest row of
 * the integration matrix in
 * the webhook receiver design),
 * amended in PR6 — see the "Amendments" section of that doc.
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use Spart\WooCommerce\Webhooks\ResolverResult;

final class WebhookTrashedOrderTest extends WC_Spart_IntegrationTestCase {

	public function test_trashed_order_returns_200_skipped_and_stays_trashed(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order = $this->make_order( '129.99' );
		$order->update_status( 'trash' );

		$reloaded_before = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded_before );
		$this->assertSame( 'trash', $reloaded_before->get_status(), 'Sanity: order must be trashed before delivery.' );

		$response = $this->deliver_webhook(
			'order.completed',
			$this->compose_session_id( $order->get_id() ),
			$this->order_envelope_payload( $order, 'completed' )
		);

		$this->assertSame( 200, $response['status'], 'Body was: ' . $response['body'] );
		$decoded = json_decode( $response['body'], true );
		$this->assertSame(
			array( 'skipped' => ResolverResult::REASON_ORDER_TRASHED ),
			$decoded
		);

		$row = $this->find_dedupe_row( $response['delivery_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( 'skipped', (string) $row['state'] );
		$this->assertSame( ResolverResult::REASON_ORDER_TRASHED, (string) $row['error_message'] );

		$reloaded_after = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded_after );
		$this->assertSame(
			'trash',
			$reloaded_after->get_status(),
			'Trashed order must stay trashed after a no-op webhook delivery.'
		);
	}
}
