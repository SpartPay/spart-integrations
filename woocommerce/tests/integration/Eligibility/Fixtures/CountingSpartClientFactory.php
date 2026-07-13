<?php
/**
 * Deterministic test factory for the eligibility integration test.
 *
 * Every call to {@see self::create_with_timeout()} returns a real
 * {@see SpartClient} backed by a fixed-response HTTP transport, and
 * increments a counter so tests can assert cache-hit behaviour against
 * the real WP transients API without touching the network.
 *
 * @package Spart\WooCommerce\Tests\Integration\Eligibility\Fixtures
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Eligibility\Fixtures;

use Spart\Sdk\Http\HttpClient;
use Spart\Sdk\Http\HttpClientFactory;
use Spart\Sdk\Http\HttpRequest;
use Spart\Sdk\Http\HttpResponse;
use Spart\Sdk\SpartClient;
use Spart\Sdk\SpartClientConfig;
use Spart\WooCommerce\Checkout\SpartClientFactoryInterface;

final class CountingSpartClientFactory implements SpartClientFactoryInterface {

	public int $call_count = 0;

	private const API_KEY = 'sk_live_integration';

	public function __construct( private readonly string $response_body ) {}

	public function create( array $log_context = array() ): SpartClient {
		unset( $log_context );
		return $this->build_client();
	}

	public function create_with_timeout(
		int $seconds,
		array $log_context = array()
	): SpartClient {
		unset( $seconds, $log_context );
		++$this->call_count;
		return $this->build_client();
	}

	public function api_key(): string {
		return self::API_KEY;
	}

	private function build_client(): SpartClient {
		$http = new class( $this->response_body ) implements HttpClient {
			public function __construct( private readonly string $body ) {}
			public function send( HttpRequest $request ): HttpResponse {
				return new HttpResponse( 200, array(), $this->body );
			}
		};

		$factory = new class( $http ) implements HttpClientFactory {
			public function __construct( private readonly HttpClient $http ) {}
			public function createClient(): HttpClient {
				return $this->http;
			}
		};

		return new SpartClient(
			new SpartClientConfig(
				baseUrl: 'https://api.spartpay.com',
				apiKey: self::API_KEY,
			),
			$factory
		);
	}
}
