<?php
/**
 * Unit test for Checkout\CheckoutSession.
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\Sdk\Exceptions\SpartApiException;
use Spart\Sdk\Exceptions\SpartAuthException;
use Spart\Sdk\Exceptions\SpartRateLimitException;
use Spart\Sdk\Exceptions\SpartServerException;
use Spart\Sdk\Exceptions\SpartTimeoutException;
use Spart\Sdk\Exceptions\SpartTransportException;
use Spart\Sdk\Exceptions\SpartValidationException;
use Spart\Sdk\Http\HttpClient;
use Spart\Sdk\Http\HttpClientFactory;
use Spart\Sdk\Http\HttpRequest;
use Spart\Sdk\Http\HttpResponse;
use Spart\Sdk\SpartClient;
use Spart\Sdk\SpartClientConfig;
use Spart\WooCommerce\Checkout\CheckoutSession;
use Spart\WooCommerce\Checkout\FailureCode;
use Spart\WooCommerce\Checkout\IntentRequestBuilder;
use Spart\WooCommerce\Checkout\MissingApiKeyException;
use Spart\WooCommerce\Checkout\SpartClientFactoryInterface;
use Spart\WooCommerce\Logging\NullSpartLogger;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * Locks the full error-branch table from spec §Errors & edge cases.
 */
final class CheckoutSessionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( $k, $d ) {
				if ( 'spart_site_token' === $k ) {
					return 'a1b2c3d4';
				}
				if ( 'woocommerce_spart_settings' === $k ) {
					return array(
						'api_key'     => 'sk_live_x',
						'environment' => 'live',
					);
				}
				return $d;
			}
		);
		Monkey\Functions\when( 'home_url' )->justReturn( 'https://shop.example/' );
		Monkey\Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example/checkout/' );
		Monkey\Functions\when( 'wc_get_endpoint_url' )->alias(
			static fn ( $endpoint, $value ) => 'https://shop.example/order-received/' . $value . '/'
		);
		Monkey\Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- We ARE the wp_json_encode stub.
			static fn ( $data ) => json_encode( $data )
		);
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_happy_path_returns_success(): void {
		$order = $this->make_order();

		$body = (string) wp_json_encode(
			array(
				'isSuccessful' => true,
				'value'        => array(
					'intentShortId' => 'abc',
					'checkoutUrl'   => 'https://pay.spart/abc',
				),
				'error'        => null,
			)
		);

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create' )->andReturn( $this->make_real_client_returning( 201, $body ) );
		$factory->shouldReceive( 'api_key' )->andReturn( 'sk_live_x' );

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), new NullSpartLogger() );
		$result  = $session->checkout( $order, 'test-corr-id' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'https://pay.spart/abc', $result->redirect_url() );
		$this->assertSame( 'abc', $result->intent_short_id() );
	}

	public function test_missing_api_key_returns_friendly_failure(): void {
		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create' )->andThrow( new MissingApiKeyException( 'no key' ) );
		$factory->shouldReceive( 'api_key' )->andReturn( '' );

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), new NullSpartLogger() );
		$result  = $session->checkout( $this->make_order(), 'test-corr-id' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'not yet configured', $result->customer_message() );
	}

	public function test_free_order_failure(): void {
		$order   = $this->make_order( '0.00' );
		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'api_key' )->andReturn( 'sk_live_x' );

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), new NullSpartLogger() );
		$result  = $session->checkout( $order, 'test-corr-id' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'zero total', $result->customer_message() );
	}

	/**
	 * @dataProvider exception_to_message_provider
	 */
	public function test_sdk_exceptions_are_translated( \Throwable $thrown, string $expected_substring ): void {
		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create' )->andThrow( $thrown );
		$factory->shouldReceive( 'api_key' )->andReturn( 'sk_live_x' );

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), new NullSpartLogger() );
		$result  = $session->checkout( $this->make_order(), 'test-corr-id' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( $expected_substring, $result->customer_message() );
	}

	/**
	 * Maps each SDK exception type to a substring expected in the customer message.
	 *
	 * @return array<string, array{0: \Throwable, 1: string}>
	 */
	public static function exception_to_message_provider(): array {
		return array(
			'auth'        => array( new SpartAuthException( 'bad key' ), 'try another method' ),
			'validation'  => array( new SpartValidationException( 'bad' ), 'invalid' ),
			'rate-limit'  => array( new SpartRateLimitException( 'slow' ), 'busy right now' ),
			'timeout'     => array( new SpartTimeoutException( 'slow' ), 'too long to respond' ),
			'transport'   => array( new SpartTransportException( 'netfail' ), "couldn't reach" ),
			'server'      => array( new SpartServerException( 'boom', 500 ), 'trouble right now' ),
			'api'         => array( new SpartApiException( 'weird', 418 ), "couldn't start your payment" ),
			'unknown'     => array( new \RuntimeException( 'mystery' ), "couldn't start your payment" ),
			'invalid-arg' => array( new \InvalidArgumentException( 'bad' ), 'invalid' ),
		);
	}

	public function test_failure_carries_lowercase_failure_code_token(): void {
		$order = $this->make_order();

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'api_key' )->andReturn( 'sk_live_x' );
		$factory->shouldReceive( 'create' )->andThrow( new SpartTimeoutException( 'slow' ) );

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), new NullSpartLogger() );

		$result = $session->checkout( $order, '00000000-0000-4000-8000-000000000001' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( FailureCode::TIMEOUT, $result->failure_code() );
	}

	public function test_info_log_on_success_includes_correlation_id(): void {
		$order = $this->make_order();

		$body = (string) wp_json_encode(
			array(
				'isSuccessful' => true,
				'value'        => array(
					'intentShortId' => 'abc',
					'checkoutUrl'   => 'https://pay.spart/abc',
				),
				'error'        => null,
			)
		);

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'api_key' )->andReturn( 'sk_live_x' );
		$factory->shouldReceive( 'create' )->andReturn( $this->make_real_client_returning( 201, $body ) );

		$logger = new class() implements SpartLoggerInterface {
			/** @var array<int, array{level:string, message:string, context:array<string,mixed>}> */
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

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), $logger );
		$result  = $session->checkout( $order, 'corr-xyz' );

		$this->assertTrue( $result->is_success() );
		$this->assertNotEmpty( $logger->calls );
		$this->assertSame( 'corr-xyz', $logger->calls[0]['context']['correlation_id'] ?? null );
		$this->assertSame( $order->get_id(), $logger->calls[0]['context']['order_id'] ?? null );
	}

	public function test_successful_checkout_persists_correlation_id_to_order_meta(): void {
		$order = $this->make_order();

		$body = (string) wp_json_encode(
			array(
				'isSuccessful' => true,
				'value'        => array(
					'intentShortId' => 'abc',
					'checkoutUrl'   => 'https://pay.spart/abc',
				),
				'error'        => null,
			)
		);

		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'api_key' )->andReturn( 'sk_live_x' );
		$factory->shouldReceive( 'create' )->andReturn( $this->make_real_client_returning( 201, $body ) );

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), new NullSpartLogger() );
		$result  = $session->checkout( $order, 'corr-meta-test' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame(
			'corr-meta-test',
			(string) $order->get_meta( CheckoutSession::META_CORRELATION_ID ),
			'successful intent creation MUST stamp the request-scoped correlation_id on order meta so later webhook handlers can link webhook log lines back to the original checkout attempt'
		);
		$this->assertSame(
			'abc',
			(string) $order->get_meta( CheckoutSession::META_INTENT_SHORT_ID ),
			'successful intent creation MUST also stamp the Spart-side intent_short_id on order meta so the admin webhook deliveries meta box can surface it'
		);
	}

	public function test_failed_checkout_does_not_persist_correlation_id_to_order_meta(): void {
		$order   = $this->make_order();
		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create' )->andThrow( new SpartTimeoutException( 'slow' ) );
		$factory->shouldReceive( 'api_key' )->andReturn( 'sk_live_x' );

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), new NullSpartLogger() );
		$result  = $session->checkout( $order, 'corr-failure' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame(
			'',
			(string) $order->get_meta( CheckoutSession::META_CORRELATION_ID ),
			'failed checkout MUST NOT persist correlation_id meta; the OrderDisposer will delete the order anyway and a stamped meta would only pollute the failure path'
		);
	}

	public function test_failed_checkout_does_not_persist_intent_short_id_to_order_meta(): void {
		$order   = $this->make_order();
		$factory = Mockery::mock( SpartClientFactoryInterface::class );
		$factory->shouldReceive( 'create' )->andThrow( new SpartTimeoutException( 'slow' ) );
		$factory->shouldReceive( 'api_key' )->andReturn( 'sk_live_x' );

		$session = new CheckoutSession( $factory, new IntentRequestBuilder( 10080 ), new NullSpartLogger() );
		$result  = $session->checkout( $order, 'corr-failure' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame(
			'',
			(string) $order->get_meta( CheckoutSession::META_INTENT_SHORT_ID ),
			'failed checkout MUST NOT persist intent_short_id meta; the OrderDisposer will delete the order anyway and a stamped meta would only pollute the failure path'
		);
	}

	private function make_order( string $total = '99.99' ): \WC_Order {
		$o = new \WC_Order();
		$o->__test_init(
			array(
				'id'       => 99,
				'currency' => 'USD',
				'total'    => $total,
				'email'    => 'jane@example.com',
				'first'    => 'Jane',
				'last'     => 'Doe',
				'items'    => array(
					array(
						'name' => 'Widget',
						'qty'  => 1,
					),
				),
			)
		);
		return $o;
	}

	/**
	 * Build a real SpartClient backed by an HttpClient that returns a single canned response.
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
