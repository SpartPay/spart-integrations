<?php
/**
 * Unit tests for Eligibility\EligibilityChecker.
 *
 * @package Spart\WooCommerce\Tests\Unit\Eligibility
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Eligibility;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\Sdk\Exceptions\SpartServerException;
use Spart\Sdk\Exceptions\SpartTimeoutException;
use Spart\Sdk\Http\HttpClient;
use Spart\Sdk\Http\HttpClientFactory;
use Spart\Sdk\Http\HttpRequest;
use Spart\Sdk\Http\HttpResponse;
use Spart\Sdk\SpartClient;
use Spart\Sdk\SpartClientConfig;
use Spart\WooCommerce\Checkout\MissingApiKeyException;
use Spart\WooCommerce\Checkout\SpartClientFactoryInterface;
use Spart\WooCommerce\Eligibility\EligibilityChecker;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * @covers \Spart\WooCommerce\Eligibility\EligibilityChecker
 */
final class EligibilityCheckerTest extends TestCase {

	/**
	 * In-memory transient store keyed by name.
	 *
	 * @var array<string, string>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();

		// In-memory WP transients backed by $this->transients.
		Functions\when( 'get_transient' )->alias( fn ( $key ) => $this->transients[ (string) $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				unset( $ttl ); // We don't simulate expiry in unit tests.
				$this->transients[ (string) $key ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ (string) $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_returns_true_when_positive_cache_hit_without_calling_api(): void {
		$this->transients[ EligibilityChecker::POSITIVE_TRANSIENT ] = '1';

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldNotReceive( 'create_with_timeout' );

		$checker = new EligibilityChecker( $factory );
		$this->assertTrue( $checker->is_eligible() );
	}

	public function test_returns_false_when_negative_cache_hit_without_calling_api(): void {
		$this->transients[ EligibilityChecker::NEGATIVE_TRANSIENT ] = '1';

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldNotReceive( 'create_with_timeout' );

		$checker = new EligibilityChecker( $factory );
		$this->assertFalse( $checker->is_eligible() );
	}

	public function test_returns_true_when_error_breaker_set_without_calling_api(): void {
		// Fail-open semantics: while the error breaker is set we allow checkout.
		$this->transients[ EligibilityChecker::ERROR_TRANSIENT ] = '1';

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldNotReceive( 'create_with_timeout' );

		$checker = new EligibilityChecker( $factory );
		$this->assertTrue( $checker->is_eligible() );
	}

	public function test_returns_true_and_caches_positive_when_api_says_eligible(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test helper building a raw HTTP response body; wp_json_encode() not available in unit tests.
		$body = (string) \json_encode(
			array(
				'isSuccessful' => true,
				'value'        => array(
					'eligible' => true,
					'reasons'  => array(),
				),
				'error'        => null,
			)
		);

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create_with_timeout' )
			->once()
			->with( EligibilityChecker::TIMEOUT_SECONDS )
			->andReturn( $this->make_real_client_returning( 200, $body ) );

		$checker = new EligibilityChecker( $factory );
		$this->assertTrue( $checker->is_eligible() );

		$this->assertSame( '1', $this->transients[ EligibilityChecker::POSITIVE_TRANSIENT ] ?? null );
		$this->assertArrayNotHasKey( EligibilityChecker::NEGATIVE_TRANSIENT, $this->transients );
		$this->assertArrayNotHasKey( EligibilityChecker::ERROR_TRANSIENT, $this->transients );
	}

	public function test_returns_false_and_caches_negative_when_api_says_ineligible(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test helper building a raw HTTP response body; wp_json_encode() not available in unit tests.
		$body = (string) \json_encode(
			array(
				'isSuccessful' => true,
				'value'        => array(
					'eligible' => false,
					'reasons'  => array(
						array(
							'code'    => 'stripe.not_connected',
							'message' => 'Stripe is not connected.',
						),
					),
				),
				'error'        => null,
			)
		);

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create_with_timeout' )
			->once()
			->with( EligibilityChecker::TIMEOUT_SECONDS )
			->andReturn( $this->make_real_client_returning( 200, $body ) );

		$checker = new EligibilityChecker( $factory );
		$this->assertFalse( $checker->is_eligible() );

		$this->assertSame( '1', $this->transients[ EligibilityChecker::NEGATIVE_TRANSIENT ] ?? null );
		$this->assertArrayNotHasKey( EligibilityChecker::POSITIVE_TRANSIENT, $this->transients );
		$this->assertArrayNotHasKey( EligibilityChecker::ERROR_TRANSIENT, $this->transients );
	}

	public function test_fails_open_logs_event_and_sets_error_breaker_on_sdk_exception(): void {
		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create_with_timeout' )
			->once()
			->with( EligibilityChecker::TIMEOUT_SECONDS )
			->andThrow( new SpartTimeoutException( 'request timed out after 2.0s' ) );

		$logger = new class() implements SpartLoggerInterface {
			/** @var list<array{level:string, message:string, context: array<string,mixed>}> */
			public array $calls = array();
			public function info( string $message, array $context = array() ): void {
				$this->calls[] = array(
					'level'   => 'info',
					'message' => $message,
					'context' => $context,
				);
			}
			public function warning( string $message, array $context = array() ): void {
				$this->calls[] = array(
					'level'   => 'warning',
					'message' => $message,
					'context' => $context,
				);
			}
			public function error( string $message, array $context = array() ): void {
				$this->calls[] = array(
					'level'   => 'error',
					'message' => $message,
					'context' => $context,
				);
			}
			public function debug( string $message, array $context = array() ): void {
				$this->calls[] = array(
					'level'   => 'debug',
					'message' => $message,
					'context' => $context,
				);
			}
		};

		$checker = new EligibilityChecker( $factory, $logger );
		$this->assertTrue( $checker->is_eligible(), 'SDK failures MUST fail open so checkout stays available.' );

		// Error breaker MUST be set so the next is_eligible() does not re-trigger the SDK call.
		$this->assertSame( '1', $this->transients[ EligibilityChecker::ERROR_TRANSIENT ] ?? null );

		// Exactly one warning emitted carrying the canonical event name + exception type.
		$this->assertCount( 1, $logger->calls );
		$this->assertSame( 'warning', $logger->calls[0]['level'] );
		$this->assertSame( LogEvents::ELIGIBILITY_CHECK_FAILED, $logger->calls[0]['context']['event'] ?? null );
		$this->assertSame( SpartTimeoutException::class, $logger->calls[0]['context']['exception_type'] ?? null );
	}

	public function test_fails_open_silently_when_api_key_missing(): void {
		// Missing key is a config state, not an outage — no log spam, but still
		// set the breaker so we don't recompute on every page render.
		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create_with_timeout' )
			->once()
			->with( EligibilityChecker::TIMEOUT_SECONDS )
			->andThrow( new MissingApiKeyException( 'Spart API key is not configured.' ) );

		$logger = new class() implements SpartLoggerInterface {
			/** @var list<string> */
			public array $levels = array();
			public function info( string $message, array $context = array() ): void {
				$this->levels[] = 'info';
			}
			public function warning( string $message, array $context = array() ): void {
				$this->levels[] = 'warning';
			}
			public function error( string $message, array $context = array() ): void {
				$this->levels[] = 'error';
			}
			public function debug( string $message, array $context = array() ): void {
				$this->levels[] = 'debug';
			}
		};

		$checker = new EligibilityChecker( $factory, $logger );
		$this->assertTrue( $checker->is_eligible() );

		$this->assertSame( '1', $this->transients[ EligibilityChecker::ERROR_TRANSIENT ] ?? null );
		$this->assertSame( array(), $logger->levels, 'Missing API key MUST NOT emit a log line — it is a config state, not an outage.' );
	}

	public function test_fails_open_without_logger_does_not_crash_on_sdk_exception(): void {
		// The logger argument is optional; passing null must still result in
		// fail-open behaviour without a TypeError or null-method call.
		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create_with_timeout' )
			->once()
			->andThrow( new SpartServerException( 'upstream 503', 503 ) );

		$checker = new EligibilityChecker( $factory, null );
		$this->assertTrue( $checker->is_eligible() );
		$this->assertSame( '1', $this->transients[ EligibilityChecker::ERROR_TRANSIENT ] ?? null );
	}

	public function test_purge_cache_deletes_all_three_transients(): void {
		$this->transients[ EligibilityChecker::POSITIVE_TRANSIENT ] = '1';
		$this->transients[ EligibilityChecker::NEGATIVE_TRANSIENT ] = '1';
		$this->transients[ EligibilityChecker::ERROR_TRANSIENT ]    = '1';

		EligibilityChecker::purge_cache();

		$this->assertSame( array(), $this->transients, 'purge_cache MUST drop every eligibility-cache key so the next is_eligible() call re-queries the API.' );
	}

	/**
	 * Build a real SpartClient backed by an HttpClient that returns a single canned response.
	 *
	 * Mirrors the pattern used by CheckoutSessionTest::make_real_client_returning so we
	 * exercise the real SDK code path end-to-end (envelope decode, DTO factory) without
	 * having to runtime-mock the final SpartClient / MerchantsEndpoint classes.
	 *
	 * @param int    $status HTTP status code to return.
	 * @param string $body   Raw response body.
	 */
	private function make_real_client_returning( int $status, string $body ): SpartClient {
		$http = new class( $status, $body ) implements HttpClient {
			public function __construct(
				private readonly int $status,
				private readonly string $body,
			) {}
			public function send( HttpRequest $request ): HttpResponse {
				return new HttpResponse( $this->status, array(), $this->body );
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
				apiKey: 'sk_live_x',
			),
			$factory
		);
	}
}
