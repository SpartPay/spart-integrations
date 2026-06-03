<?php
/**
 * Integration tests for the inbound REST endpoint at
 * /wp-json/spart/v1/webhook. Exercises the signature-validation gate and
 * the simplest happy-path event ('webhook.test', which short-circuits to
 * 204 via REASON_TEST_EVENT — no order setup required).
 *
 * The four cases below correspond to the WebhookEndpointTest row of the
 * design spec's integration matrix (PR3 design,
 * the webhook receiver design).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use Spart\WooCommerce\Webhooks\WebhookReceiver;

final class WebhookEndpointTest extends WC_Spart_IntegrationTestCase {

	/**
	 * Stub signs with a secret that does not match the plugin's
	 * `webhook_secret = 'whsec_test'`. The receiver's SignatureVerifier
	 * computes a different HMAC, the equality check fails, and the
	 * pipeline returns 401 with `error: invalid_signature` BEFORE
	 * inserting any dedupe row.
	 */
	public function test_bad_signature_returns_401_and_does_not_insert_dedupe_row(): void {
		$this->set_signing_secret( 'whsec_definitely_wrong' );

		$response = $this->deliver_webhook( 'webhook.test', null, array() );

		$this->assertSame( 401, $response['status'], 'Expected 401 for bad signature; body was: ' . $response['body'] );
		$this->assertNull(
			$this->find_dedupe_row( $response['delivery_id'] ),
			'Bad signature must NOT insert a dedupe row (signature gate runs before insert_received).'
		);
	}

	/**
	 * Bypasses the stub and POSTs directly to the WC REST endpoint with
	 * X-Spart-Signature deliberately omitted. SignatureVerifier rejects
	 * empty signatures via SpartValidationException → 401.
	 *
	 * Note: home_url() inside tests-cli returns http://localhost:8889 which
	 * is the host-port mapping (not reachable from inside the container);
	 * we hit the Apache service hostname directly via Docker DNS, and use
	 * the index.php?rest_route= form so the request works regardless of
	 * the wp-env permalink_structure setting.
	 */
	public function test_missing_signature_header_returns_401(): void {
		$body = (string) wp_json_encode(
			array(
				'id'            => 'evt_missing_sig_test',
				'type'          => 'webhook.test',
				'createdAt'     => gmdate( 'c' ),
				'apiVersion'    => '2025-01-01',
				'merchantAppId' => 'merchant_test',
				'data'          => array(),
			)
		);

		$response = wp_remote_post(
			'http://tests-wordpress/index.php?rest_route=/spart/v1/webhook',
			array(
				'body'    => $body,
				'headers' => array(
					'Content-Type'                      => 'application/json',
					WebhookReceiver::HEADER_DELIVERY_ID => 'd-missing-sig-' . bin2hex( random_bytes( 6 ) ),
					// X-Spart-Signature deliberately omitted.
				),
				'timeout' => 10,
			)
		);

		$this->assertIsArray( $response, 'WC endpoint unreachable from inside wp-env.' );
		$this->assertSame( 401, (int) wp_remote_retrieve_response_code( $response ) );
	}

	/**
	 * SDK SignatureVerifier enforces a 300-second window by default.
	 * Signing with t = now - 600 puts the timestamp 5 minutes outside
	 * tolerance, so verification fails → 401.
	 */
	public function test_expired_timestamp_returns_401(): void {
		$this->set_signing_secret( 'whsec_test' );

		$response = $this->deliver_webhook(
			'webhook.test',
			null,
			array(),
			1,
			null,
			time() - 600
		);

		$this->assertSame( 401, $response['status'], 'Expected 401 for expired timestamp; body was: ' . $response['body'] );
		$this->assertNull(
			$this->find_dedupe_row( $response['delivery_id'] ),
			'Expired timestamp must NOT insert a dedupe row.'
		);
	}

	/**
	 * webhook.test is the canonical no-op event: the resolver returns
	 * REASON_TEST_EVENT, the receiver calls mark_applied with a null
	 * wc_order_id, and the response is 204 No Content. The dedupe row
	 * is left in `applied` state so a retry returns {deduped:true}.
	 */
	public function test_happy_path_webhook_test_event_returns_204_and_marks_applied(): void {
		$this->set_signing_secret( 'whsec_test' );

		$response = $this->deliver_webhook( 'webhook.test', null, array() );

		$this->assertSame( 204, $response['status'], 'Expected 204 for webhook.test happy path; body was: ' . $response['body'] );
		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );

		$row = $this->find_dedupe_row( $response['delivery_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( 'webhook.test', (string) $row['event_type'] );
		$this->assertNull( $row['wc_order_id'], 'webhook.test resolves to REASON_TEST_EVENT and is not tied to any order.' );
	}
}
