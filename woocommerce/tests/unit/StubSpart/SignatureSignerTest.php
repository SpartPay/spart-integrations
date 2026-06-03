<?php
/**
 * Unit tests for stub-spart's StubSpartSignatureSigner.
 *
 * Exercises the standalone signer against the SDK's SignatureVerifier so
 * the deliberate duplication in stub-spart never silently drifts away
 * from the canonical algorithm in the spart/sdk library
 * (spartpay/spart-sdks, php/src/Webhooks/SignatureVerifier.php).
 *
 * @package Spart\WooCommerce\Tests\Unit\StubSpart
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\StubSpart;

use PHPUnit\Framework\TestCase;
use Spart\Sdk\Webhooks\SignatureVerifier;

require_once __DIR__ . '/../../integration/stub-spart/SignatureSigner.php';

/**
 * @covers \StubSpartSignatureSigner
 */
final class SignatureSignerTest extends TestCase {

	private const SECRET = 'whsec_test_signer';

	public function test_sign_returns_header_and_timestamp_in_expected_format(): void {
		$result = \StubSpartSignatureSigner::sign( '{"hello":"world"}', self::SECRET, 1_700_000_000 );

		$this->assertSame( 1_700_000_000, $result['timestamp'] );
		$this->assertMatchesRegularExpression(
			'/^t=1700000000,v1=[0-9a-f]{64}$/',
			$result['header']
		);
	}

	public function test_signed_header_verifies_against_sdk_SignatureVerifier(): void {
		$body   = '{"id":"evt_1","type":"webhook.test","data":{"test":{"message":"hi"}}}';
		$signed = \StubSpartSignatureSigner::sign( $body, self::SECRET );

		$verifier = new SignatureVerifier( self::SECRET );

		$this->assertTrue( $verifier->verify( $body, $signed['header'] ) );
	}

	public function test_default_timestamp_uses_current_time(): void {
		$before = time();
		$result = \StubSpartSignatureSigner::sign( 'body', self::SECRET );
		$after  = time();

		$this->assertGreaterThanOrEqual( $before, $result['timestamp'] );
		$this->assertLessThanOrEqual( $after, $result['timestamp'] );
	}

	public function test_different_secrets_produce_different_signatures(): void {
		$body = '{"a":1}';
		$ts   = 1_700_000_000;

		$a = \StubSpartSignatureSigner::sign( $body, 'secret_a', $ts );
		$b = \StubSpartSignatureSigner::sign( $body, 'secret_b', $ts );

		$this->assertNotSame( $a['header'], $b['header'] );
	}

	public function test_different_bodies_produce_different_signatures(): void {
		$ts = 1_700_000_000;

		$a = \StubSpartSignatureSigner::sign( '{"a":1}', self::SECRET, $ts );
		$b = \StubSpartSignatureSigner::sign( '{"a":2}', self::SECRET, $ts );

		$this->assertNotSame( $a['header'], $b['header'] );
	}

	public function test_signature_is_lowercase_hex(): void {
		$result = \StubSpartSignatureSigner::sign( 'body', self::SECRET, 1_700_000_000 );
		$this->assertSame( 1, preg_match( '/^t=\d+,v1=([0-9a-f]+)$/', $result['header'], $matches ) );
		$this->assertSame( 64, strlen( $matches[1] ) );
		$this->assertSame( strtolower( $matches[1] ), $matches[1] );
	}
}
