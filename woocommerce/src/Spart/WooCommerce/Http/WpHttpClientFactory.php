<?php
/**
 * WpHttpClientFactory — produces WpHttpClient instances for the SDK.
 *
 * @package Spart\WooCommerce\Http
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Http;

use Spart\Sdk\Http\HttpClient;
use Spart\Sdk\Http\HttpClientFactory;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * Builds WpHttpClient instances and exposes the canonical Spart base URLs.
 *
 * Also hosts the static callback registered by Plugin::boot() against the
 * `http_request_host_is_external` WordPress filter, so wp_safe_remote_request
 * permits requests to the Spart API even from `localhost` development hosts.
 */
final class WpHttpClientFactory implements HttpClientFactory {

	private const LIVE_BASE_URL    = 'https://api.spartpay.com';
	private const SANDBOX_BASE_URL = 'https://sandbox-api.spartpay.com';

	/**
	 * Creates a factory that propagates sanitized telemetry context to clients.
	 *
	 * @param SpartLoggerInterface|null $logger Optional logger for request-completion telemetry.
	 * @param array<string, mixed>      $log_context Sanitized context copied to each client.
	 */
	public function __construct(
		private readonly ?SpartLoggerInterface $logger = null,
		private readonly array $log_context = array(),
	) {}

	/**
	 * Returns a fresh WpHttpClient.
	 *
	 * @return HttpClient
	 */
	public function createClient(): HttpClient {
		return new WpHttpClient( $this->logger, $this->log_context );
	}

	/**
	 * Returns the API base URL for the given environment.
	 *
	 * If `WP_SPART_BASE_URL` is defined (typically in wp-config.php for local
	 * stub-server development), it overrides both live and sandbox.
	 *
	 * @param string $environment Either 'live' or 'sandbox'. Anything else falls back to live.
	 * @return string Base URL with no trailing slash.
	 */
	public static function base_url_for( string $environment ): string {
		if ( defined( 'WP_SPART_BASE_URL' ) && is_string( \WP_SPART_BASE_URL ) && '' !== \WP_SPART_BASE_URL ) {
			return rtrim( \WP_SPART_BASE_URL, '/' );
		}

		return 'sandbox' === $environment
			? self::SANDBOX_BASE_URL
			: self::LIVE_BASE_URL;
	}

	/**
	 * Returns the list of hostnames the plugin is allowed to call.
	 *
	 * @return list<string>
	 */
	public static function allowed_spart_hosts(): array {
		$hosts = array( 'api.spartpay.com', 'sandbox-api.spartpay.com' );

		if ( defined( 'WP_SPART_BASE_URL' ) && is_string( \WP_SPART_BASE_URL ) && '' !== \WP_SPART_BASE_URL ) {
			$parsed = wp_parse_url( \WP_SPART_BASE_URL, PHP_URL_HOST );
			if ( is_string( $parsed ) && '' !== $parsed && ! in_array( $parsed, $hosts, true ) ) {
				$hosts[] = $parsed;
			}
		}

		return $hosts;
	}

	/**
	 * Callback for the `http_request_host_is_external` filter.
	 *
	 * Returns true for our allowed hosts (so wp_safe_remote_request lets the
	 * call through even from `localhost` dev hosts). Defers to the upstream
	 * value for everything else.
	 *
	 * @param bool   $external Upstream filter value.
	 * @param string $host     Hostname being requested.
	 * @param string $url      Full URL being requested (unused; required by filter signature).
	 * @return bool
	 */
	public static function filter_host_is_external( bool $external, string $host, string $url ): bool {
		unset( $url );
		return in_array( $host, self::allowed_spart_hosts(), true ) ? true : $external;
	}
}
