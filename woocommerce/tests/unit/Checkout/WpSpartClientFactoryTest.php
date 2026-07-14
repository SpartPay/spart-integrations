<?php
/**
 * Unit tests for Checkout\WpSpartClientFactory.
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Spart\Sdk\SpartClient;
use Spart\WooCommerce\Checkout\MissingApiKeyException;
use Spart\WooCommerce\Checkout\SpartClientFactoryInterface;
use Spart\WooCommerce\Checkout\WpSpartClientFactory;

/**
 * @covers \Spart\WooCommerce\Checkout\WpSpartClientFactory
 * @covers \Spart\WooCommerce\Checkout\MissingApiKeyException
 */
final class WpSpartClientFactoryTest extends TestCase {

	protected function setUp(): void {
		Monkey\setUp();
		Monkey\Functions\when( 'get_bloginfo' )->justReturn( '6.5.5' );
		if ( ! defined( 'WC_VERSION' ) ) {
			define( 'WC_VERSION', '9.4.3' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_implements_interface(): void {
		$this->assertInstanceOf( SpartClientFactoryInterface::class, new WpSpartClientFactory() );
	}

	public function test_create_returns_spart_client_for_live(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k
				? array(
					'api_key'     => 'sk_live_xyz',
					'environment' => 'live',
				)
				: $d
		);

		$client = ( new WpSpartClientFactory() )->create();
		$this->assertInstanceOf( SpartClient::class, $client );
		$this->assertSame( 'https://api.spartpay.com', $client->config->baseUrl );
		$this->assertSame( 'sk_live_xyz', $client->config->apiKey );
		$this->assertStringContainsString( 'spart-wc/', $client->config->userAgent );
		$this->assertStringContainsString( 'wp/6.5.5', $client->config->userAgent );
		$this->assertStringContainsString( 'wc/', $client->config->userAgent );
	}

	public function test_create_returns_spart_client_for_sandbox(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k
				? array(
					'api_key'     => 'sk_test_xyz',
					'environment' => 'sandbox',
				)
				: $d
		);

		$client = ( new WpSpartClientFactory() )->create();
		$this->assertSame( 'https://sandbox-api.spartpay.com', $client->config->baseUrl );
	}

	public function test_create_throws_when_api_key_missing(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k
				? array(
					'api_key'     => '',
					'environment' => 'live',
				)
				: $d
		);

		$this->expectException( MissingApiKeyException::class );
		( new WpSpartClientFactory() )->create();
	}

	public function test_create_throws_when_settings_missing(): void {
		Monkey\Functions\when( 'get_option' )->justReturn( false );

		$this->expectException( MissingApiKeyException::class );
		( new WpSpartClientFactory() )->create();
	}

	public function test_api_key_returns_configured_value(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k
				? array( 'api_key' => 'sk_live_abc' )
				: $d
		);

		$this->assertSame( 'sk_live_abc', ( new WpSpartClientFactory() )->api_key() );
	}

	public function test_create_with_timeout_overrides_default_timeout(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k
				? array(
					'api_key'     => 'sk_live_xyz',
					'environment' => 'live',
				)
				: $d
		);

		$client = ( new WpSpartClientFactory() )->create_with_timeout( 2 );

		$this->assertInstanceOf( SpartClient::class, $client );
		$this->assertSame( 2, $client->config->timeoutSeconds );
	}

	public function test_create_accepts_sanitized_log_context_without_changing_config(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $key, $default ) => 'woocommerce_spart_settings' === $key
				? array(
					'api_key'     => 'sk_live_xyz',
					'environment' => 'live',
				)
				: $default
		);

		$client = ( new WpSpartClientFactory() )->create(
			array( 'correlation_id' => 'corr-config' )
		);

		$this->assertSame( 'https://api.spartpay.com', $client->config->baseUrl );
		$this->assertSame( 30, $client->config->timeoutSeconds );
	}

	public function test_create_with_timeout_propagates_missing_key(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k
				? array(
					'api_key'     => '',
					'environment' => 'live',
				)
				: $d
		);

		$this->expectException( MissingApiKeyException::class );
		( new WpSpartClientFactory() )->create_with_timeout( 2 );
	}

	public function test_create_delegates_to_create_with_timeout_with_default(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k
				? array(
					'api_key'     => 'sk_live_xyz',
					'environment' => 'live',
				)
				: $d
		);

		$client = ( new WpSpartClientFactory() )->create();

		// Documents that create() and create_with_timeout(30) yield the same
		// timeout — a regression here would silently change the customer-
		// facing 30s checkout budget.
		$this->assertSame( 30, $client->config->timeoutSeconds );
	}
}
