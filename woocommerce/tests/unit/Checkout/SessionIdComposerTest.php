<?php
/**
 * Unit tests for SessionIdComposer.
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Checkout\SessionIdComposer;

final class SessionIdComposerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Constructor
	// ------------------------------------------------------------------

	public function test_constructor_accepts_valid_token(): void {
		$composer = new SessionIdComposer( 'a1b2c3d4' );
		$this->assertInstanceOf( SessionIdComposer::class, $composer );
	}

	public function test_constructor_rejects_token_wrong_length(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SessionIdComposer( 'abc' );
	}

	public function test_constructor_rejects_non_hex_token(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SessionIdComposer( 'gggggggg' );
	}

	// ------------------------------------------------------------------
	// compose()
	// ------------------------------------------------------------------

	public function test_compose_returns_expected_format(): void {
		$composer = new SessionIdComposer( 'a1b2c3d4' );
		$this->assertSame( 'spart-wc-a1b2c3d4-42', $composer->compose( 42 ) );
	}

	public function test_compose_throws_overflow_for_huge_order_id(): void {
		$composer = new SessionIdComposer( 'a1b2c3d4' );
		// 64-char limit: prefix(8)+dash(1)+token(8)+dash(1) = 18 chars; remaining = 46.
		$huge_id = str_repeat( '9', 47 );
		$this->expectException( \OverflowException::class );
		$composer->compose( $huge_id );
	}

	public function test_compose_allows_max_length_exactly(): void {
		$composer = new SessionIdComposer( 'a1b2c3d4' );
		// Build an order_id that fills exactly 64 chars.
		// spart-wc-a1b2c3d4- = 18 chars; order_id can be 46 chars.
		$max_order_id = (int) str_repeat( '1', 46 );
		$result       = $composer->compose( $max_order_id );
		$this->assertLessThanOrEqual( 64, strlen( $result ) );
	}

	// ------------------------------------------------------------------
	// Static helpers
	// ------------------------------------------------------------------

	public function test_extract_order_id_returns_int_for_valid_id(): void {
		$this->assertSame( 42, SessionIdComposer::extract_order_id( 'spart-wc-a1b2c3d4-42' ) );
	}

	public function test_extract_order_id_returns_null_for_garbage(): void {
		$this->assertNull( SessionIdComposer::extract_order_id( 'not-a-spart-id' ) );
		$this->assertNull( SessionIdComposer::extract_order_id( 'shopify-session-12345' ) );
		$this->assertNull( SessionIdComposer::extract_order_id( 'spart-wc-a1b2c3d4-not-a-number' ) );
	}

	public function test_belongs_to_site_token_true_when_matching(): void {
		$this->assertTrue(
			SessionIdComposer::belongs_to_site_token( 'spart-wc-a1b2c3d4-99', 'a1b2c3d4' )
		);
	}

	public function test_belongs_to_site_token_false_when_not_matching(): void {
		$this->assertFalse(
			SessionIdComposer::belongs_to_site_token( 'spart-wc-deadbeef-99', 'a1b2c3d4' )
		);
	}

	public function test_derive_site_token_is_first_8_hex_of_sha256(): void {
		$token = SessionIdComposer::derive_site_token( 'https://shop.example/' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $token );
		$this->assertSame(
			substr( hash( 'sha256', 'https://shop.example/' ), 0, 8 ),
			$token
		);
	}
}
