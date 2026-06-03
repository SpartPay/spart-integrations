<?php
/**
 * Unit tests for Http\WpHttpClientFactory.
 *
 * @package Spart\WooCommerce\Tests\Unit\Http
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Spart\Sdk\Http\HttpClient;
use Spart\WooCommerce\Http\WpHttpClient;
use Spart\WooCommerce\Http\WpHttpClientFactory;

/**
 * @covers \Spart\WooCommerce\Http\WpHttpClientFactory
 */
final class WpHttpClientFactoryTest extends TestCase {

	public function test_create_client_returns_wp_http_client(): void {
		$factory = new WpHttpClientFactory();
		$client  = $factory->createClient();
		$this->assertInstanceOf( WpHttpClient::class, $client );
		$this->assertInstanceOf( HttpClient::class, $client );
	}

	public function test_base_url_for_live(): void {
		$this->assertSame( 'https://api.spartpay.com', WpHttpClientFactory::base_url_for( 'live' ) );
	}

	public function test_base_url_for_sandbox(): void {
		$this->assertSame( 'https://sandbox-api.spartpay.com', WpHttpClientFactory::base_url_for( 'sandbox' ) );
	}

	public function test_base_url_for_unknown_falls_back_to_live(): void {
		$this->assertSame( 'https://api.spartpay.com', WpHttpClientFactory::base_url_for( 'staging' ) );
	}

	public function test_base_url_constant_overrides_environment(): void {
		if ( ! defined( 'WP_SPART_BASE_URL' ) ) {
			define( 'WP_SPART_BASE_URL', 'http://stub-spart:8080' );
		}
		$this->assertSame( 'http://stub-spart:8080', WpHttpClientFactory::base_url_for( 'live' ) );
		$this->assertSame( 'http://stub-spart:8080', WpHttpClientFactory::base_url_for( 'sandbox' ) );
	}

	public function test_allowed_spart_hosts_includes_live_and_sandbox(): void {
		$hosts = WpHttpClientFactory::allowed_spart_hosts();
		$this->assertContains( 'api.spartpay.com', $hosts );
		$this->assertContains( 'sandbox-api.spartpay.com', $hosts );
	}

	public function test_allowed_spart_hosts_includes_constant_host_when_defined(): void {
		// WP_SPART_BASE_URL is set by an earlier test; cannot be unset.
		$hosts = WpHttpClientFactory::allowed_spart_hosts();
		$this->assertContains( 'stub-spart', $hosts );
	}

	public function test_filter_allows_known_spart_host(): void {
		$this->assertTrue(
			WpHttpClientFactory::filter_host_is_external( false, 'api.spartpay.com', 'https://api.spartpay.com/api/intents' )
		);
		$this->assertTrue(
			WpHttpClientFactory::filter_host_is_external( false, 'sandbox-api.spartpay.com', 'https://sandbox-api.spartpay.com/' )
		);
	}

	public function test_filter_passes_through_unknown_host(): void {
		// Returns the upstream value untouched for hosts we don't manage.
		$this->assertFalse(
			WpHttpClientFactory::filter_host_is_external( false, 'evil.example', 'https://evil.example/' )
		);
		$this->assertTrue(
			WpHttpClientFactory::filter_host_is_external( true, 'evil.example', 'https://evil.example/' )
		);
	}
}
