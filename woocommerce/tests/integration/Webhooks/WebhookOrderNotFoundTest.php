<?php
/**
 * Integration test for the order-not-found reject branch (PR6 contract).
 *
 * When the sessionId is well-formed and carries our site_token but
 * `wc_get_order(...)` returns false (order was hard-deleted),
 * WpOrderResolver returns REASON_ORDER_NOT_FOUND → receiver responds
 * 404 {error: 'order_not_found'} and writes no dedupe row. The
 * dispatcher's bounded retry budget caps retries; suppressing the row
 * keeps the dedupe table free of dead-end deliveries. This test picks
 * an order id well outside any plausible auto-increment range so
 * `wc_get_order()` reliably returns false.
 *
 * Implements PR3 task t7-skip-tests (WebhookOrderNotFoundTest row of
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

final class WebhookOrderNotFoundTest extends WC_Spart_IntegrationTestCase {

	public function test_session_id_for_nonexistent_order_returns_404_and_writes_no_dedupe_row(): void {
		$this->set_signing_secret( 'whsec_test' );
		$ghost_order_id = 999999999;
		$this->assertFalse(
			wc_get_order( $ghost_order_id ),
			'Sanity: chosen order id must not exist before the test runs.'
		);

		$session_id = $this->compose_session_id( $ghost_order_id );

		// Build an envelope shape consistent with the SDK contract; the
		// receiver looks at sessionId in the envelope (not data.order)
		// when resolving, so the order_id inside the order body is
		// irrelevant for this reject path.
		$payload = array(
			'order' => array(
				'shortId'       => 'spart_short_ghost',
				'originalTotal' => array(
					'currency' => 'USD',
					'amount'   => 1.00,
				),
				'finalTotal'    => array(
					'currency' => 'USD',
					'amount'   => 1.00,
				),
				'lineItems'     => array(
					array(
						'name'     => 'Ghost item',
						'quantity' => 1,
					),
				),
				'sparter'       => array(
					'fullName' => 'Ghost Buyer',
					'email'    => 'ghost@example.com',
				),
				'sessionId'     => $session_id,
				'status'        => 'completed',
				'countryCode'   => 'US',
				'createdAt'     => gmdate( 'c' ),
			),
		);

		$response = $this->deliver_webhook( 'order.completed', $session_id, $payload );

		$this->assertSame( 404, $response['status'], 'Body was: ' . $response['body'] );
		$decoded = json_decode( $response['body'], true );
		$this->assertSame(
			array( 'error' => ResolverResult::REASON_ORDER_NOT_FOUND ),
			$decoded
		);

		$this->assertNull(
			$this->find_dedupe_row( $response['delivery_id'] ),
			'PR6 contract: 4xx branches must not write a dedupe row.'
		);
	}
}
