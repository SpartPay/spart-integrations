<?php
/**
 * Router for the stub-spart sidecar.
 *
 * Run with: `php -S 0.0.0.0:8080 router.php`
 *
 * Routes:
 *   POST /api/intents             — dispatch via active scenario
 *   POST /__stub/scenario         — switch active scenario; optional signing_secret
 *   POST /__stub/reset            — clear state and recorded requests
 *   POST /__stub/deliver-webhook  — sign + POST a webhook to WC_TARGET_URL
 *   GET  /__stub/recorded         — dump recorded requests
 *   GET  /__stub/health           — liveness check
 */

declare(strict_types=1);

require_once __DIR__ . '/scenarios.php';
require_once __DIR__ . '/SignatureSigner.php';
require_once __DIR__ . '/deliver_webhook.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
if ( ! is_string( $path ) || '' === $path ) {
	$path = '/';
}

if ( 'GET' === $method && '/__stub/health' === $path ) {
	stub_send_json( 200, array( 'ok' => true ) );
}

if ( 'GET' === $method && '/__stub/recorded' === $path ) {
	$state = StubSpartState::load();
	stub_send_json( 200, array( 'recorded' => $state['recorded'] ?? array() ) );
}

if ( 'POST' === $method && '/__stub/reset' === $path ) {
	StubSpartState::reset();
	stub_send_json( 200, array( 'reset' => true ) );
}

if ( 'POST' === $method && '/__stub/scenario' === $path ) {
	$body                    = stub_read_json_body();
	$scenario                = (string) ( $body['scenario'] ?? 'happy' );
	$state                   = StubSpartState::load();
	$state['scenario']       = $scenario;
	$state['signing_secret'] = (string) ( $body['signing_secret'] ?? $state['signing_secret'] );
	StubSpartState::save( $state );
	stub_send_json(
		200,
		array(
			'scenario'       => $scenario,
			'signing_secret' => $state['signing_secret'],
		)
	);
}

if ( 'POST' === $method && '/__stub/deliver-webhook' === $path ) {
	stub_dispatch_deliver_webhook( stub_read_json_body() );
}

if ( 'POST' === $method && '/api/intents' === $path ) {
	stub_dispatch_intent_create( stub_read_json_body() );
}

stub_send_json(
	404,
	array(
		'error'  => 'Not found',
		'method' => $method,
		'path'   => $path,
	)
);
