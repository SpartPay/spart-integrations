<?php
/**
 * Integration tests for the order.created event branch.
 *
 * order.created performs no WC status transition; it persists the redacted
 * payees (payment parts) snapshot to order meta so the Spart payees meta box
 * can render it. PII (payee name/email) is masked upstream and must never
 * appear in the stored snapshot.
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use Spart\WooCommerce\Webhooks\OrderSync;

final class WebhookOrderCreatedTest extends WC_Spart_IntegrationTestCase {

	public function test_order_created_persists_redacted_payees_snapshot(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order = $this->make_order( '361.00' );

		$payload                          = $this->order_envelope_payload( $order, 'placed' );
		$payload['order']['paymentParts'] = array(
			array(
				'id'          => 'pp-int-1',
				'amount'      => 50.0,
				'amountType'  => 'Percent',
				'status'      => 'captured',
				'isSparter'   => true,
				'payee'       => array(
					'fullName' => 'Beppe Brescia',
					'email'    => 'obiuan+spartwp@gmail.com',
				),
				'payeeCharge' => array(
					'net'   => array(
						'amount'   => 195.0,
						'currency' => 'EUR',
					),
					'total' => array(
						'amount'   => 200.0,
						'currency' => 'EUR',
					),
					'fees'  => array( 'platform' => 5.0 ),
				),
			),
		);

		$response = $this->deliver_webhook(
			'order.created',
			$this->compose_session_id( $order->get_id() ),
			$payload
		);

		$this->assertSame( 204, $response['status'], 'Body was: ' . $response['body'] );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );

		$snapshot = (string) $reloaded->get_meta( OrderSync::META_PAYMENT_PARTS );
		$this->assertNotSame( '', $snapshot );

		$decoded = json_decode( $snapshot, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 1, $decoded['v'] );
		$this->assertCount( 1, $decoded['parts'] );
		$this->assertSame( 'pp-int-1', $decoded['parts'][0]['id'] );
		$this->assertSame( 'captured', $decoded['parts'][0]['status'] );
		// Name was an email-looking value and the email itself carried PII:
		// both must be reduced to the redaction placeholder / dropped entirely.
		$this->assertSame( '•••', $decoded['parts'][0]['payeeName'] );
		$this->assertArrayNotHasKey( 'payeeEmail', $decoded['parts'][0] );

		// No PII may leak into the stored snapshot: the raw email and full name
		// from the payload, and any "@", must be absent.
		$this->assertStringNotContainsString( '@', $snapshot );
		$this->assertStringNotContainsString( 'obiuan', $snapshot );
		$this->assertStringNotContainsString( 'Beppe', $snapshot );

		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}

	public function test_order_created_does_not_erase_existing_snapshot_when_parts_empty(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order = $this->make_order( '361.00' );
		$order->update_meta_data( OrderSync::META_PAYMENT_PARTS, '[{"id":"pre-existing"}]' );
		$order->save();

		$response = $this->deliver_webhook(
			'order.created',
			$this->compose_session_id( $order->get_id() ),
			$this->order_envelope_payload( $order, 'placed' )
		);

		$this->assertSame( 204, $response['status'], 'Body was: ' . $response['body'] );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertStringContainsString(
			'pre-existing',
			(string) $reloaded->get_meta( OrderSync::META_PAYMENT_PARTS )
		);
		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}
}
