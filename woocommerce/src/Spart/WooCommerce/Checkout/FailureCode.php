<?php
/**
 * Checkout\FailureCode — stable lowercase tokens identifying why a checkout failed.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

use Spart\Sdk\Exceptions\SpartApiException;
use Spart\Sdk\Exceptions\SpartAuthException;
use Spart\Sdk\Exceptions\SpartRateLimitException;
use Spart\Sdk\Exceptions\SpartServerException;
use Spart\Sdk\Exceptions\SpartTimeoutException;
use Spart\Sdk\Exceptions\SpartTransportException;
use Spart\Sdk\Exceptions\SpartValidationException;

/**
 * Closed set of `failure_code` tokens written into every Spart checkout
 * failure log line and `CheckoutResult::failure()` value. Lowercase
 * snake_case so dashboards and grep filters can pin them; stable across
 * SDK refactors (an exception class rename does not silently move the
 * token).
 *
 * Every catch arm in {@see CheckoutSession::checkout()} maps a concrete
 * exception type to one of these constants — see
 * {@see self::from_exception()} for the mapping.
 */
final class FailureCode {

	public const TIMEOUT         = 'timeout';
	public const AUTH_FAILED     = 'auth_failed';
	public const RATE_LIMITED    = 'rate_limited';
	public const SERVER_ERROR    = 'server_error';
	public const API_ERROR       = 'api_error';
	public const VALIDATION      = 'validation';
	public const TRANSPORT       = 'transport';
	public const FREE_ORDER      = 'free_order';
	public const MISSING_API_KEY = 'missing_api_key';
	public const MALFORMED       = 'malformed';
	public const UNKNOWN         = 'unknown';

	/**
	 * Prevent instantiation; this class holds constants only.
	 */
	private function __construct() {}

	/**
	 * Map any thrown exception to its canonical FailureCode token.
	 *
	 * The order of `instanceof` checks matters: SpartValidationException,
	 * SpartAuthException, SpartRateLimitException, SpartTimeoutException,
	 * SpartTransportException and SpartServerException are all subclasses
	 * of SpartApiException, so they must be matched before it.
	 *
	 * @param \Throwable $e Originating exception, of any type.
	 * @return string One of self::* constants. Never empty.
	 */
	public static function from_exception( \Throwable $e ): string {
		if ( $e instanceof MissingApiKeyException ) {
			return self::MISSING_API_KEY;
		}
		if ( $e instanceof FreeOrderException ) {
			return self::FREE_ORDER;
		}
		if ( $e instanceof SpartAuthException ) {
			return self::AUTH_FAILED;
		}
		if ( $e instanceof SpartValidationException ) {
			return self::VALIDATION;
		}
		if ( $e instanceof SpartRateLimitException ) {
			return self::RATE_LIMITED;
		}
		if ( $e instanceof SpartTimeoutException ) {
			return self::TIMEOUT;
		}
		if ( $e instanceof SpartTransportException ) {
			return self::TRANSPORT;
		}
		if ( $e instanceof SpartServerException ) {
			return self::SERVER_ERROR;
		}
		if ( $e instanceof SpartApiException ) {
			return self::API_ERROR;
		}
		if ( $e instanceof \InvalidArgumentException ) {
			return self::MALFORMED;
		}
		return self::UNKNOWN;
	}
}
