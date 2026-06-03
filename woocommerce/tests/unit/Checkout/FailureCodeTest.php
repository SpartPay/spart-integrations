<?php
/**
 * Tests for Checkout\FailureCode::from_exception() — exception-to-token mapping.
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Spart\Sdk\Exceptions\SpartApiException;
use Spart\Sdk\Exceptions\SpartAuthException;
use Spart\Sdk\Exceptions\SpartRateLimitException;
use Spart\Sdk\Exceptions\SpartServerException;
use Spart\Sdk\Exceptions\SpartTimeoutException;
use Spart\Sdk\Exceptions\SpartTransportException;
use Spart\Sdk\Exceptions\SpartValidationException;
use Spart\WooCommerce\Checkout\FailureCode;
use Spart\WooCommerce\Checkout\FreeOrderException;
use Spart\WooCommerce\Checkout\MissingApiKeyException;

final class FailureCodeTest extends TestCase {

	/**
	 * @dataProvider exception_to_token_provider
	 */
	public function test_from_exception_maps_each_supported_type( \Throwable $e, string $expected ): void {
		$this->assertSame( $expected, FailureCode::from_exception( $e ) );
	}

	public static function exception_to_token_provider(): array {
		return array(
			'missing api key' => array( new MissingApiKeyException( 'no key' ), FailureCode::MISSING_API_KEY ),
			'free order'      => array( new FreeOrderException( 'zero total' ), FailureCode::FREE_ORDER ),
			'auth'            => array( new SpartAuthException( 'forbidden' ), FailureCode::AUTH_FAILED ),
			'validation'      => array( new SpartValidationException( 'bad' ), FailureCode::VALIDATION ),
			'rate limit'      => array( new SpartRateLimitException( 'slow down' ), FailureCode::RATE_LIMITED ),
			'timeout'         => array( new SpartTimeoutException( 'too slow' ), FailureCode::TIMEOUT ),
			'transport'       => array( new SpartTransportException( 'curl' ), FailureCode::TRANSPORT ),
			'server'          => array( new SpartServerException( '5xx' ), FailureCode::SERVER_ERROR ),
			'api base'        => array( new SpartApiException( 'generic' ), FailureCode::API_ERROR ),
			'malformed'       => array( new \InvalidArgumentException( 'bad arg' ), FailureCode::MALFORMED ),
			'unknown'         => array( new \RuntimeException( 'mystery' ), FailureCode::UNKNOWN ),
		);
	}

	public function test_specific_spart_subclass_takes_precedence_over_api_base(): void {
		// SpartAuthException extends SpartApiException — must NOT collapse to API_ERROR.
		$this->assertSame( FailureCode::AUTH_FAILED, FailureCode::from_exception( new SpartAuthException( 'x' ) ) );
		$this->assertSame( FailureCode::TIMEOUT, FailureCode::from_exception( new SpartTimeoutException( 'x' ) ) );
	}
}
