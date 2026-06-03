<?php
/**
 * Handler for `POST /__stub/deliver-webhook`.
 *
 * Expands a small input envelope into a full Spart webhook event,
 * HMAC-signs it with the per-test signing_secret, POSTs it to the WC
 * test instance, records the delivery for assertions, and returns the
 * target's response.
 *
 * Invoked from inside the PHP built-in server — NOT loaded by composer.
 */

declare(strict_types=1);

require_once __DIR__ . '/State.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/SignatureSigner.php';

/**
 * Build, sign and POST a webhook delivery; record + return the result.
 *
 * Input shape (all optional except event_type and payload):
 *   {
 *     "event_type":     "order.completed",
 *     "session_id":     "spart-wc-abc12345-42",   // recorded only
 *     "payload":        { "order": {...} },        // becomes data.{order|payment|intent|test}
 *     "signing_secret": "...",                     // overrides scenario-set secret
 *     "attempt":        1,
 *     "delivery_id":    "uuid-...",                // auto-UUID v4 if omitted
 *     "event_id":       "evt_...",                 // auto-UUID v4 if omitted
 *     "api_version":    "2025-01-01",
 *     "merchant_app_id":"merchant_test",
 *     "timestamp":      1700000000                 // override signing timestamp; defaults to time()
 *   }
 *
 * @param array<string, mixed>|null $body Decoded request body.
 */
function stub_dispatch_deliver_webhook( ?array $body ): void {
	if ( ! is_array( $body ) ) {
		stub_send_json( 400, array( 'error' => 'Body must be a JSON object' ) );
	}

	$event_type = (string) ( $body['event_type'] ?? '' );
	if ( '' === $event_type ) {
		stub_send_json( 400, array( 'error' => 'event_type is required' ) );
	}

	$state          = StubSpartState::load();
	$signing_secret = (string) ( $body['signing_secret'] ?? $state['signing_secret'] ?? '' );
	if ( '' === $signing_secret ) {
		stub_send_json(
			400,
			array(
				'error' => 'No signing_secret available; set it via /__stub/scenario or pass in body.',
			)
		);
	}

	$attempt         = (int) ( $body['attempt'] ?? 1 );
	$delivery_id     = (string) ( $body['delivery_id'] ?? stub_uuid_v4() );
	$event_id        = (string) ( $body['event_id'] ?? stub_uuid_v4() );
	$api_version     = (string) ( $body['api_version'] ?? '2025-01-01' );
	$merchant_app_id = (string) ( $body['merchant_app_id'] ?? 'merchant_test' );
	$payload         = is_array( $body['payload'] ?? null ) ? $body['payload'] : array();
	$timestamp       = isset( $body['timestamp'] ) ? (int) $body['timestamp'] : null;

	$envelope = array(
		'id'            => $event_id,
		'type'          => $event_type,
		'createdAt'     => gmdate( 'c' ),
		'apiVersion'    => $api_version,
		'merchantAppId' => $merchant_app_id,
		'data'          => $payload,
	);

	$raw_body = (string) json_encode( $envelope, JSON_UNESCAPED_SLASHES );
	$signed   = StubSpartSignatureSigner::sign( $raw_body, $signing_secret, $timestamp );

	$wc_target_url = (string) ( getenv( 'WC_TARGET_URL' ) ?: '' );
	if ( '' === $wc_target_url ) {
		stub_send_json(
			500,
			array(
				'error' => 'WC_TARGET_URL env var is not set in the stub-spart sidecar.',
			)
		);
	}
	// Use the index.php?rest_route= URL form: works regardless of the
	// merchant's permalink_structure setting and bypasses Apache mod_rewrite,
	// which is critical inside the wp-env tests-cli container where the
	// default Plain permalinks would 404 the canonical /wp-json/ path at the
	// Apache layer before WP could route the REST request.
	$webhook_url = rtrim( $wc_target_url, '/' ) . '/index.php?rest_route=/spart/v1/webhook';

	$headers_sent = array(
		'Content-Type'            => 'application/json',
		'X-Spart-Signature'       => $signed['header'],
		'X-Spart-Delivery-Id'     => $delivery_id,
		'X-Spart-Webhook-Attempt' => (string) $attempt,
		'X-Spart-Event'           => $event_type,
	);

	$response = stub_post_webhook( $webhook_url, $raw_body, $headers_sent );

	StubSpartState::record(
		array(
			'kind'         => 'webhook_delivery',
			'event_type'   => $event_type,
			'session_id'   => $body['session_id'] ?? null,
			'delivery_id'  => $delivery_id,
			'event_id'     => $event_id,
			'attempt'      => $attempt,
			'envelope'     => $envelope,
			'headers_sent' => $headers_sent,
			'response'     => $response,
			'time'         => microtime( true ),
		)
	);

	stub_send_json(
		200,
		array(
			'status'       => $response['status'],
			'body'         => $response['body'],
			'headers_sent' => $headers_sent,
			'delivery_id'  => $delivery_id,
		)
	);
}

/**
 * POST $raw_body to $url with $headers via stream context (no cURL dep).
 *
 * @param array<string, string> $headers
 * @return array{status:int, body:string}
 */
function stub_post_webhook( string $url, string $raw_body, array $headers ): array {
	$header_lines = array();
	foreach ( $headers as $name => $value ) {
		$header_lines[] = $name . ': ' . $value;
	}

	$context = stream_context_create(
		array(
			'http' => array(
				'method'        => 'POST',
				'header'        => implode( "\r\n", $header_lines ),
				'content'       => $raw_body,
				'ignore_errors' => true,
				'timeout'       => 10.0,
			),
		)
	);

	$body = @file_get_contents( $url, false, $context );
	if ( false === $body ) {
		return array(
			'status' => 0,
			'body'   => 'stub-spart could not reach ' . $url,
		);
	}

	$status = 0;
	if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
		foreach ( $http_response_header as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', (string) $line, $m ) ) {
				$status = (int) $m[1];
			}
		}
	}

	return array(
		'status' => $status,
		'body'   => (string) $body,
	);
}

/**
 * Generate a RFC 4122 v4 UUID.
 */
function stub_uuid_v4(): string {
	$bytes    = random_bytes( 16 );
	$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
	$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
	$hex      = bin2hex( $bytes );
	return sprintf(
		'%s-%s-%s-%s-%s',
		substr( $hex, 0, 8 ),
		substr( $hex, 8, 4 ),
		substr( $hex, 12, 4 ),
		substr( $hex, 16, 4 ),
		substr( $hex, 20, 12 )
	);
}
