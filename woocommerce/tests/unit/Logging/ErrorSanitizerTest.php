<?php
/**
 * Unit tests for Logging\ErrorSanitizer.
 *
 * @package Spart\WooCommerce\Tests\Unit\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Spart\Sdk\Exceptions\SpartAuthException;
use Spart\WooCommerce\Logging\ErrorSanitizer;

/**
 * @covers \Spart\WooCommerce\Logging\ErrorSanitizer
 */
final class ErrorSanitizerTest extends TestCase {

	public function test_sanitises_to_class_basename_plus_message(): void {
		$e = new SpartAuthException( 'Invalid API key' );
		$this->assertSame( 'SpartAuthException: Invalid API key', ErrorSanitizer::sanitize( $e ) );
	}

	public function test_truncates_message_to_500_chars(): void {
		$msg = str_repeat( 'A', 600 );
		$e   = new \RuntimeException( $msg );
		$out = ErrorSanitizer::sanitize( $e );
		$this->assertSame( strlen( 'RuntimeException: ' ) + 500, strlen( $out ) );
	}

	public function test_redacts_api_key_substring(): void {
		$e = new \RuntimeException( 'Request failed for sk_live_abc123def456' );
		$this->assertSame(
			'RuntimeException: Request failed for <redacted>',
			ErrorSanitizer::sanitize( $e, 'sk_live_abc123def456' )
		);
	}

	public function test_does_not_redact_when_api_key_empty(): void {
		$e = new \RuntimeException( 'Generic message' );
		$this->assertSame( 'RuntimeException: Generic message', ErrorSanitizer::sanitize( $e, '' ) );
	}

	public function test_redacts_then_truncates(): void {
		$key  = 'sk_live_' . str_repeat( 'x', 24 );
		$body = str_repeat( 'A', 480 ) . $key . str_repeat( 'B', 80 );
		$e    = new \RuntimeException( $body );
		$out  = ErrorSanitizer::sanitize( $e, $key );
		$this->assertStringContainsString( '<redacted>', $out );
		$this->assertSame( strlen( 'RuntimeException: ' ) + 500, strlen( $out ) );
	}
}
