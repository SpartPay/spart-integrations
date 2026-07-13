<?php
/**
 * WpHttpClient — SDK HttpClient backed by wp_safe_remote_request().
 *
 * @package Spart\WooCommerce\Http
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Http;

use Spart\Sdk\Exceptions\SpartTimeoutException;
use Spart\Sdk\Exceptions\SpartTransportException;
use Spart\Sdk\Http\HttpClient;
use Spart\Sdk\Http\HttpRequest;
use Spart\Sdk\Http\HttpResponse;
use Spart\WooCommerce\Logging\ElapsedTime;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * SDK HttpClient implementation backed by wp_safe_remote_request().
 *
 * Non-2xx responses are returned verbatim — the SDK's Endpoints (specifically
 * `Spart\Sdk\Internal\HttpResponseClassifier`) translate status codes to typed
 * exceptions. Only transport-level failures are translated here.
 */
final class WpHttpClient implements HttpClient {

	/**
	 * Creates a client with optional request-completion telemetry logging.
	 *
	 * @param SpartLoggerInterface|null $logger Optional logger for request-completion telemetry.
	 * @param array<string, mixed>      $log_context Sanitized context copied to request telemetry.
	 */
	public function __construct(
		private readonly ?SpartLoggerInterface $logger = null,
		private readonly array $log_context = array(),
	) {}

	/**
	 * Sends an HTTP request via wp_safe_remote_request().
	 *
	 * @param HttpRequest $request The request to send.
	 * @return HttpResponse The raw response (status, headers, body).
	 * @throws SpartTimeoutException   On cURL timeout errors.
	 * @throws SpartTransportException On any other WP_Error transport failure.
	 */
	public function send( HttpRequest $request ): HttpResponse {
		$started_at = ElapsedTime::start();
		$args       = array(
			'method'      => $request->method,
			'timeout'     => $request->timeoutSeconds,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $request->headers,
		);

		if ( $request->body !== null ) {
			$args['body'] = $request->body;
		}

		$raw = \wp_safe_remote_request( $request->url, $args );

		if ( \is_wp_error( $raw ) ) {
			$message = (string) $raw->get_error_message();
			$outcome = self::is_timeout_message( $message ) ? 'timeout' : 'transport_error';
			$this->log_completion( $request, $started_at, $outcome );

			if ( 'timeout' === $outcome ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message; not rendered as HTML output.
				throw new SpartTimeoutException( 'Spart HTTP request timed out: ' . $message );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message; not rendered as HTML output.
			throw new SpartTransportException( 'Spart HTTP transport error: ' . $message );
		}

		$status  = (int) \wp_remote_retrieve_response_code( $raw );
		$body    = (string) \wp_remote_retrieve_body( $raw );
		$headers = $this->normalise_headers( \wp_remote_retrieve_headers( $raw ) );

		$this->log_completion(
			$request,
			$started_at,
			'response',
			$status,
			$headers['x-trace-id'] ?? null
		);

		return new HttpResponse( $status, $headers, $body );
	}

	/**
	 * Emits sanitized HTTP request-completion telemetry.
	 *
	 * @param HttpRequest $request      Original request.
	 * @param int         $started_at   Monotonic request start time from hrtime(true).
	 * @param string      $outcome      Request outcome category.
	 * @param int|null    $status_code  Response status code when available.
	 * @param string|null $api_trace_id Trace identifier returned by the Spart API.
	 * @return void
	 */
	private function log_completion(
		HttpRequest $request,
		int $started_at,
		string $outcome,
		?int $status_code = null,
		?string $api_trace_id = null
	): void {
		if ( null === $this->logger ) {
			return;
		}

		$path    = \wp_parse_url( $request->url, PHP_URL_PATH );
		$context = array_merge(
			$this->log_context,
			array(
				'event'              => LogEvents::API_REQUEST_COMPLETED,
				'http_method'        => strtoupper( $request->method ),
				'endpoint_path'      => is_string( $path ) && '' !== $path ? $path : '/',
				'http_round_trip_ms' => ElapsedTime::milliseconds_since( $started_at ),
				'outcome'            => $outcome,
			)
		);

		if ( null !== $status_code ) {
			$context['status_code'] = $status_code;
		}

		$api_trace_id = null !== $api_trace_id ? trim( $api_trace_id ) : '';
		if ( '' !== $api_trace_id ) {
			$context['api_trace_id'] = $api_trace_id;
		}

		$this->logger->info( 'Spart API request completed.', $context );
	}

	/**
	 * Normalises the headers returned by WP into a lowercase-keyed array.
	 *
	 * @param mixed $headers Raw headers from wp_remote_retrieve_headers().
	 * @return array<string, string> Lowercase-keyed header map.
	 */
	private function normalise_headers( mixed $headers ): array {
		if ( is_array( $headers ) ) {
			$out = array();
			foreach ( $headers as $k => $v ) {
				$out[ strtolower( (string) $k ) ] = is_array( $v )
					? implode( ', ', array_map( 'strval', $v ) )
					: (string) $v;
			}
			return $out;
		}

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$out = array();
			foreach ( (array) $headers->getAll() as $k => $v ) {
				$out[ strtolower( (string) $k ) ] = is_array( $v )
					? implode( ', ', array_map( 'strval', $v ) )
					: (string) $v;
			}
			return $out;
		}

		return array();
	}

	/**
	 * Returns true when the error message indicates a connection timeout.
	 *
	 * @param string $message The WP_Error message string.
	 * @return bool True if the message describes a timeout.
	 */
	private static function is_timeout_message( string $message ): bool {
		$lower = strtolower( $message );
		return str_contains( $lower, 'curl error 28' )
			|| str_contains( $lower, 'timed out' )
			|| str_contains( $lower, 'connection timed out' );
	}
}
