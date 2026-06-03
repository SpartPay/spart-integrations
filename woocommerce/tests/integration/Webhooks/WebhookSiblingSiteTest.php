<?php
/**
 * Integration test for the sibling-site reject branch (PR6 contract).
 *
 * If a sessionId is well-formed but carries a different site_token
 * than ours, WpOrderResolver returns REASON_SIBLING_SITE → receiver
 * responds 400 {error: 'sibling_site'} and writes no dedupe row.
 * The dispatcher's bounded retry budget caps retries, and skipping
 * the row keeps the dedupe table clean for "not for us" deliveries.
 *
 * Implements PR3 task t7-skip-tests (WebhookSiblingSiteTest row of
 * the integration matrix in
 * the webhook receiver design),
 * amended in PR6 — see the "Amendments" section of that doc.
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Checkout\SessionIdComposer;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use Spart\WooCommerce\Webhooks\ResolverResult;

final class WebhookSiblingSiteTest extends WC_Spart_IntegrationTestCase {

	public function test_sibling_site_session_id_returns_400_and_writes_no_dedupe_row(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order              = $this->make_order( '129.99' );
		$status_before      = $order->get_status();
		$sibling_session_id = SessionIdComposer::PREFIX . '-deadbeef-' . $order->get_id();

		// The receiver reads sessionId from the envelope payload at
		// data.order.sessionId — not from the deliver_webhook helper's
		// `session_id` arg, which is recorded-only. Override the payload's
		// sessionId so the resolver actually sees a sibling-site token.
		$payload                       = $this->order_envelope_payload( $order, 'completed' );
		$payload['order']['sessionId'] = $sibling_session_id;

		$response = $this->deliver_webhook(
			'order.completed',
			$sibling_session_id,
			$payload
		);

		$this->assertSame( 400, $response['status'], 'Body was: ' . $response['body'] );
		$decoded = json_decode( $response['body'], true );
		$this->assertSame(
			array( 'error' => ResolverResult::REASON_SIBLING_SITE ),
			$decoded
		);

		$this->assertNull(
			$this->find_dedupe_row( $response['delivery_id'] ),
			'PR6 contract: 4xx branches must not write a dedupe row.'
		);

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertSame(
			$status_before,
			$reloaded->get_status(),
			'Sibling-site webhook must not mutate the local order.'
		);
	}
}
