<?php
/**
 * Unit tests for Http\WpHttpClient.
 *
 * @package Spart\WooCommerce\Tests\Unit\Http
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Http;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Spart\Sdk\Exceptions\SpartTimeoutException;
use Spart\Sdk\Exceptions\SpartTransportException;
use Spart\Sdk\Http\HttpRequest;
use Spart\WooCommerce\Http\WpHttpClient;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Tests\Unit\Fixtures\RecordingSpartLogger;

/**
 * @covers \Spart\WooCommerce\Http\WpHttpClient
 */
final class WpHttpClientTest extends TestCase {

	protected function setUp(): void {
		Monkey\setUp();
		if ( ! class_exists( 'WP_Error' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only stub for WP_Error class.
			eval(
				'class WP_Error {
					public string $code;
					public string $msg;
					public function __construct( string $code = "", string $msg = "" ) { $this->code = $code; $this->msg = $msg; }
					public function get_error_code() { return $this->code; }
					public function get_error_message() { return $this->msg; }
				}'
			);
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_send_returns_http_response_on_2xx(): void {
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );
		Monkey\Functions\when( 'wp_safe_remote_request' )->justReturn(
			array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => '{"isSuccessful":true,"value":{"intentShortId":"abc","checkoutUrl":"https://pay.spart/abc"}}',
			)
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $r ) => $r['response']['code'] ?? 0
		);
		Monkey\Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static fn ( $r ) => $r['headers'] ?? array()
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $r ) => $r['body'] ?? ''
		);

		$client = new WpHttpClient();
		$resp   = $client->send(
			new HttpRequest(
				'POST',
				'https://api.spartpay.com/api/intents',
				array(
					'Content-Type'             => 'application/json',
					'x-spart-merchant-api-key' => 'sk_live_xyz',
				),
				'{}',
				30
			)
		);

		$this->assertSame( 200, $resp->statusCode );
		$this->assertStringContainsString( 'intentShortId', $resp->body );
	}

	public function test_send_logs_sanitized_round_trip_and_api_trace_id(): void {
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn ( $value ) => $value instanceof \WP_Error );
		Monkey\Functions\when( 'wp_safe_remote_request' )->justReturn(
			array(
				'response' => array(
					'code'    => 201,
					'message' => 'Created',
				),
				'headers'  => array(
					'x-trace-id' => 'api-trace-123',
				),
				'body'     => '{"isSuccessful":true}',
			)
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $response ) => $response['response']['code'] );
		Monkey\Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn ( $response ) => $response['headers'] );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $response ) => $response['body'] );

		$logger = new RecordingSpartLogger();
		$client = new WpHttpClient(
			$logger,
			array(
				'correlation_id' => 'corr-http-1',
				'order_id'       => 42,
			)
		);

		$client->send(
			new HttpRequest(
				'POST',
				'https://api.spartpay.com/api/intents?ignored=yes',
				array( 'x-spart-merchant-api-key' => 'sk_secret' ),
				'{"email":"private@example.com"}',
				30
			)
		);

		$calls   = $logger->calls_for_event( LogEvents::API_REQUEST_COMPLETED );
		$context = $calls[0]['context'];

		$this->assertCount( 1, $calls );
		$this->assertSame( 'info', $calls[0]['level'] );
		$this->assertSame( 'corr-http-1', $context['correlation_id'] );
		$this->assertSame( 42, $context['order_id'] );
		$this->assertSame( 'POST', $context['http_method'] );
		$this->assertSame( '/api/intents', $context['endpoint_path'] );
		$this->assertSame( 'response', $context['outcome'] );
		$this->assertSame( 201, $context['status_code'] );
		$this->assertSame( 'api-trace-123', $context['api_trace_id'] );
		$this->assertIsFloat( $context['http_round_trip_ms'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only serialization for leak assertions.
		$encoded = (string) \json_encode( $context, JSON_THROW_ON_ERROR );
		$this->assertStringNotContainsString( 'sk_secret', $encoded );
		$this->assertStringNotContainsString( 'private@example.com', $encoded );
		$this->assertStringNotContainsString( 'api.spartpay.com', $encoded );
		$this->assertStringNotContainsString( 'ignored=yes', $encoded );
	}

	public function test_send_translates_curl_28_to_spart_timeout_exception(): void {
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );
		Monkey\Functions\when( 'wp_safe_remote_request' )->justReturn(
			new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds' )
		);

		$logger = new RecordingSpartLogger();
		$client = new WpHttpClient( $logger, array( 'correlation_id' => 'corr-timeout' ) );

		try {
			$client->send( new HttpRequest( 'POST', 'https://api.spartpay.com/api/intents' ) );
			$this->fail( 'Expected SpartTimeoutException.' );
		} catch ( SpartTimeoutException $e ) {
			$this->assertStringContainsString( 'timed out', $e->getMessage() );
			$calls = $logger->calls_for_event( LogEvents::API_REQUEST_COMPLETED );
			$this->assertCount( 1, $calls );
			$this->assertSame( 'timeout', $calls[0]['context']['outcome'] );
			$this->assertArrayNotHasKey( 'status_code', $calls[0]['context'] );
			$this->assertArrayNotHasKey( 'api_trace_id', $calls[0]['context'] );
		}
	}

	public function test_send_translates_other_wp_errors_to_spart_transport_exception(): void {
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );
		Monkey\Functions\when( 'wp_safe_remote_request' )->justReturn(
			new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' )
		);

		$logger = new RecordingSpartLogger();
		$client = new WpHttpClient( $logger );

		try {
			$client->send( new HttpRequest( 'POST', 'https://api.spartpay.com/api/intents' ) );
			$this->fail( 'Expected SpartTransportException.' );
		} catch ( SpartTransportException $e ) {
			$this->assertStringContainsString( 'transport error', $e->getMessage() );
			$calls = $logger->calls_for_event( LogEvents::API_REQUEST_COMPLETED );
			$this->assertCount( 1, $calls );
			$this->assertSame( 'transport_error', $calls[0]['context']['outcome'] );
			$this->assertArrayNotHasKey( 'status_code', $calls[0]['context'] );
		}
	}

	public function test_send_does_not_translate_non_2xx_itself(): void {
		// The SDK's Endpoints classify failures; HttpClient only returns the response.
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );
		Monkey\Functions\when( 'wp_safe_remote_request' )->justReturn(
			array(
				'response' => array(
					'code'    => 401,
					'message' => 'Unauthorized',
				),
				'headers'  => array(
					'x-trace-id' => 'api-trace-401',
				),
				'body'     => '{"isSuccessful":false,"error":"Invalid API key"}',
			)
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => $r['response']['code'] );
		Monkey\Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn ( $r ) => $r['headers'] );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => $r['body'] );

		$logger = new RecordingSpartLogger();
		$client = new WpHttpClient( $logger );
		$resp   = $client->send( new HttpRequest( 'POST', 'https://api.spartpay.com/api/intents' ) );

		$this->assertSame( 401, $resp->statusCode );
		$calls = $logger->calls_for_event( LogEvents::API_REQUEST_COMPLETED );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'response', $calls[0]['context']['outcome'] );
		$this->assertSame( 401, $calls[0]['context']['status_code'] );
		$this->assertSame( 'api-trace-401', $calls[0]['context']['api_trace_id'] );
		// No exception thrown by HttpClient itself — that's IntentsEndpoint's job.
	}

	public function test_send_passes_timeout_through(): void {
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );

		$captured = array();
		Monkey\Functions\when( 'wp_safe_remote_request' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'body'     => '{}',
				);
			}
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => $r['response']['code'] );
		Monkey\Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn ( $r ) => $r['headers'] );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => $r['body'] );

		( new WpHttpClient() )->send( new HttpRequest( 'POST', 'https://api.spartpay.com/api/intents', array(), '{}', 7 ) );

		$this->assertSame( 7, $captured['timeout'] );
		$this->assertSame( 'POST', $captured['method'] );
		$this->assertSame( '{}', $captured['body'] );
	}

	public function test_send_normalises_header_array(): void {
		Monkey\Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );
		Monkey\Functions\when( 'wp_safe_remote_request' )->justReturn(
			array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => new class() {
					/** @return array<string, string> */
					// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mimics WP Requests_Utility_CaseInsensitiveDictionary::getAll().
					public function getAll(): array {
						return array( 'x-foo' => 'bar' );
					}
				},
				'body'     => '{}',
			)
		);
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => $r['response']['code'] );
		Monkey\Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn ( $r ) => $r['headers'] );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => $r['body'] );

		$resp = ( new WpHttpClient() )->send( new HttpRequest( 'POST', 'https://api.spartpay.com/api/intents' ) );

		$this->assertSame( 'bar', $resp->header( 'X-Foo' ) );
	}
}
