<?php
/**
 * Scenario dispatcher for `POST /api/intents`.
 *
 * Reads the active scenario from {@see StubSpartState}, records the request,
 * and emits the matching wire response. Each branch terminates via
 * stub_send_json() (which calls exit) so missing `break` statements are
 * intentional.
 */

declare(strict_types=1);

require_once __DIR__ . '/State.php';
require_once __DIR__ . '/helpers.php';

/**
 * @param array<string, mixed> $body Decoded request body.
 */
function stub_dispatch_intent_create( array $body ): void {
	$state    = StubSpartState::load();
	$scenario = (string) ( $state['scenario'] ?? 'happy' );

	StubSpartState::record(
		array(
			'scenario' => $scenario,
			'method'   => 'POST',
			'path'     => '/api/intents',
			'headers'  => function_exists( 'apache_request_headers' ) ? apache_request_headers() : array(),
			'body'     => $body,
			'time'     => microtime( true ),
		)
	);

	switch ( $scenario ) {
		case 'happy':
			stub_send_json( 201, stub_success_envelope( 'stubabc', 'http://stub-spart:8080/checkout/stubabc' ) );
			// stub_send_json exits; fallthrough impossible.
		case 'replay':
			stub_send_json( 200, stub_success_envelope( 'stubabc', 'http://stub-spart:8080/checkout/stubabc' ) );
		case 'error_400':
			stub_send_json( 400, stub_failure_envelope( 'validation.failed', 'Validation failed', array( 'lineItems is required' ) ) );
		case 'error_401':
			stub_send_json( 401, stub_failure_envelope( 'auth.unauthorized', 'Invalid API key' ) );
		case 'error_500':
			stub_send_json( 500, stub_failure_envelope( 'server.error', 'Internal server error' ) );
		case 'timeout':
			sleep( 35 );
			stub_send_json( 200, stub_success_envelope( 'toolate', 'http://stub-spart:8080/checkout/toolate' ) );
		case 'malformed':
			http_response_code( 200 );
			header( 'Content-Type: application/json' );
			echo '{"isSuccessful":true,"value":';
			exit;
		default:
			stub_send_json( 500, stub_failure_envelope( 'server.error', 'Unknown stub scenario: ' . $scenario ) );
	}
}
