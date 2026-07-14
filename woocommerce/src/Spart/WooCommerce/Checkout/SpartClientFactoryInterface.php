<?php
/**
 * Checkout\SpartClientFactoryInterface — abstraction over SpartClient construction.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

use Spart\Sdk\SpartClient;

/**
 * Lets CheckoutSession take a Mockery test-double in unit tests instead of
 * the real WpSpartClientFactory (which depends on get_option() and the
 * WC_VERSION constant).
 */
interface SpartClientFactoryInterface {

	/**
	 * Build a configured SpartClient ready to call the Spart API.
	 *
	 * @param array<string, mixed> $log_context Sanitized context for HTTP telemetry.
	 *
	 * @throws MissingApiKeyException When the merchant has not configured an API key.
	 */
	public function create( array $log_context = array() ): SpartClient;

	/**
	 * Build a configured SpartClient using a non-default request timeout.
	 *
	 * The {@see \Spart\WooCommerce\Eligibility\EligibilityChecker} uses
	 * this to keep an admin-side merchant eligibility probe well below
	 * the customer-facing 30 s checkout timeout — a stale or
	 * mis-configured Spart API must not block WooCommerce's checkout
	 * page from rendering for the merchant for tens of seconds.
	 *
	 * @param int                  $timeout_seconds Per-request HTTP timeout.
	 * @param array<string, mixed> $log_context     Sanitized context for HTTP telemetry.
	 *
	 * @throws MissingApiKeyException When the merchant has not configured an API key.
	 */
	public function create_with_timeout(
		int $timeout_seconds,
		array $log_context = array()
	): SpartClient;

	/**
	 * Currently-configured API key (used by ErrorSanitizer to redact it from
	 * log messages). Empty string when not configured.
	 */
	public function api_key(): string;
}
