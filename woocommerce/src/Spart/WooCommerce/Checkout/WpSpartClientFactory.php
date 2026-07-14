<?php
/**
 * Checkout\WpSpartClientFactory — builds a SpartClient from plugin settings.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

use Spart\Sdk\Retry\RetryPolicy;
use Spart\Sdk\SpartClient;
use Spart\Sdk\SpartClientConfig;
use Spart\WooCommerce\Http\WpHttpClientFactory;
use Spart\WooCommerce\Logging\SpartLoggerInterface;
use Spart\WooCommerce\Plugin;

/**
 * Builds a SpartClient configured from the WooCommerce settings.
 *
 * Reads `woocommerce_spart_settings` for `api_key` and `environment` on every
 * call (cheap, since WP autoloads the option), then assembles a
 * SpartClientConfig with:
 *  - baseUrl per environment (live/sandbox).
 *  - retryPolicy = RetryPolicy::none() — the customer is staring at a spinner;
 *    we cannot afford 3 retries × 200ms.
 *  - userAgent = "spart-wc/<plugin-version> wp/<wp-version> wc/<wc-version>".
 *
 * Returns `new SpartClient($config, new WpHttpClientFactory($this->logger, $log_context))`
 * so HTTP request telemetry receives the optional logger and sanitized context.
 */
final class WpSpartClientFactory implements SpartClientFactoryInterface {

	private const SETTINGS_OPTION     = 'woocommerce_spart_settings';
	private const DEFAULT_ENVIRONMENT = 'live';
	private const DEFAULT_TIMEOUT_S   = 30;

	/**
	 * Build a factory that can attach sanitized telemetry context to SDK clients.
	 *
	 * @param SpartLoggerInterface|null $logger Optional logger for HTTP telemetry.
	 */
	public function __construct(
		private readonly ?SpartLoggerInterface $logger = null,
	) {}

	/**
	 * Build a configured SpartClient.
	 *
	 * @param array<string, mixed> $log_context Sanitized context for HTTP telemetry.
	 *
	 * @throws MissingApiKeyException When the merchant has not configured an API key.
	 */
	public function create( array $log_context = array() ): SpartClient {
		return $this->create_with_timeout( self::DEFAULT_TIMEOUT_S, $log_context );
	}

	/**
	 * Build a configured SpartClient with a caller-supplied request timeout.
	 *
	 * Used by {@see \Spart\WooCommerce\Eligibility\EligibilityChecker} to
	 * tighten the per-request timeout well below the customer-facing
	 * checkout default — a non-responsive Spart API must not block the
	 * merchant's WP admin from rendering for tens of seconds.
	 *
	 * @param int                  $timeout_seconds Per-request HTTP timeout, in whole seconds.
	 * @param array<string, mixed> $log_context     Sanitized context for HTTP telemetry.
	 *
	 * @throws MissingApiKeyException When the merchant has not configured an API key.
	 */
	public function create_with_timeout(
		int $timeout_seconds,
		array $log_context = array()
	): SpartClient {
		$api_key = $this->api_key();
		if ( '' === $api_key ) {
			throw new MissingApiKeyException( 'Spart API key is not configured.' );
		}

		$config = new SpartClientConfig(
			baseUrl: WpHttpClientFactory::base_url_for( $this->environment() ),
			apiKey: $api_key,
			timeoutSeconds: $timeout_seconds,
			retryPolicy: RetryPolicy::none(),
			userAgent: $this->user_agent(),
		);

		return new SpartClient(
			$config,
			new WpHttpClientFactory( $this->logger, $log_context )
		);
	}

	/**
	 * Currently-configured API key (empty when missing).
	 */
	public function api_key(): string {
		$settings = $this->settings();
		return (string) ( $settings['api_key'] ?? '' );
	}

	/**
	 * Currently-configured environment (defaults to "live").
	 */
	private function environment(): string {
		$settings = $this->settings();
		return (string) ( $settings['environment'] ?? self::DEFAULT_ENVIRONMENT );
	}

	/**
	 * Read and normalise the plugin settings option.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$raw = \get_option( self::SETTINGS_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Construct the User-Agent string sent on every Spart API request.
	 */
	private function user_agent(): string {
		$wp = function_exists( 'get_bloginfo' ) ? (string) \get_bloginfo( 'version' ) : 'unknown';
		$wc = defined( 'WC_VERSION' ) ? (string) \WC_VERSION : 'unknown';
		return sprintf( 'spart-wc/%s wp/%s wc/%s', Plugin::VERSION, $wp, $wc );
	}
}
