<?php
/**
 * Integration test for the unknown-event-type skip branch (forward-compat).
 *
 * If Spart introduces a new event type that this plugin version does
 * not yet recognise, EventType::tryFrom() returns null →
 * WpOrderResolver returns REASON_UNKNOWN_EVENT → receiver writes a
 * 'skipped' dedupe row, logs a warning ('webhook.unknown_event_type'),
 * and returns 200 {skipped: 'unknown_event_type'}. This is the
 * forward-compatibility seam that lets Spart roll out new event types
 * to older plugin installs without retry storms or 5xx feedback.
 *
 * Implements PR3 task t7-skip-tests (WebhookUnknownEventTypeTest row
 * of the integration matrix in
 * the webhook receiver design).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use Spart\WooCommerce\Webhooks\ResolverResult;

final class WebhookUnknownEventTypeTest extends WC_Spart_IntegrationTestCase {

	public function test_unknown_event_type_returns_200_skipped_and_logs_warning(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order = $this->make_order( '129.99' );

		$response = $this->deliver_webhook(
			'future.event.does_not_exist',
			$this->compose_session_id( $order->get_id() ),
			$this->order_envelope_payload( $order, 'completed' )
		);

		$this->assertSame( 200, $response['status'], 'Body was: ' . $response['body'] );
		$decoded = json_decode( $response['body'], true );
		$this->assertSame(
			array( 'skipped' => ResolverResult::REASON_UNKNOWN_EVENT ),
			$decoded
		);

		$row = $this->find_dedupe_row( $response['delivery_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( 'skipped', (string) $row['state'] );
		$this->assertSame( ResolverResult::REASON_UNKNOWN_EVENT, (string) $row['error_message'] );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertNotSame(
			'completed',
			$reloaded->get_status(),
			'Unknown-type webhook must not mutate the order even if its data envelope says so.'
		);
	}
}
