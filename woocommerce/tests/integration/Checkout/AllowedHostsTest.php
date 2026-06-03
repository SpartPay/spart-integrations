<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Http\WpHttpClientFactory;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class AllowedHostsTest extends WC_Spart_IntegrationTestCase {

	public function test_filter_lets_stub_spart_through_on_localhost(): void {
		$this->assertTrue(
			apply_filters( 'http_request_host_is_external', false, 'stub-spart', 'http://stub-spart:8080/__stub/health' )
		);
	}

	public function test_filter_does_not_open_arbitrary_hosts(): void {
		$this->assertFalse(
			apply_filters( 'http_request_host_is_external', false, 'evil.example', 'https://evil.example/' )
		);
	}

	public function test_allowed_hosts_includes_live_and_sandbox_and_stub(): void {
		$hosts = WpHttpClientFactory::allowed_spart_hosts();
		$this->assertContains( 'api.spartpay.com', $hosts );
		$this->assertContains( 'sandbox-api.spartpay.com', $hosts );
		$this->assertContains( 'stub-spart', $hosts );
	}
}
