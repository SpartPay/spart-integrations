<?php
/**
 * Unit tests for Checkout\CheckoutResult.
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Checkout\CheckoutResult;
use Spart\WooCommerce\Checkout\FailureCode;

/**
 * @covers \Spart\WooCommerce\Checkout\CheckoutResult
 */
final class CheckoutResultTest extends TestCase {

	public function test_success_factory(): void {
		$r = CheckoutResult::success( 'https://pay.spart/abc', 'abc' );
		$this->assertTrue( $r->is_success() );
		$this->assertSame( 'https://pay.spart/abc', $r->redirect_url() );
		$this->assertSame( 'abc', $r->intent_short_id() );
	}

	public function test_failure_factory(): void {
		$r = CheckoutResult::failure( 'Sorry, please try again.', '401 Unauthorized' );
		$this->assertFalse( $r->is_success() );
		$this->assertSame( 'Sorry, please try again.', $r->customer_message() );
		$this->assertSame( '401 Unauthorized', $r->log_message() );
	}

	public function test_failure_default_log_message_is_customer_message(): void {
		$r = CheckoutResult::failure( 'Network unavailable.' );
		$this->assertSame( 'Network unavailable.', $r->log_message() );
	}

	public function test_success_throws_on_customer_message_access(): void {
		$r = CheckoutResult::success( 'https://pay.spart/abc', 'abc' );
		$this->expectException( \LogicException::class );
		$r->customer_message();
	}

	public function test_failure_throws_on_redirect_url_access(): void {
		$r = CheckoutResult::failure( 'Sorry.' );
		$this->expectException( \LogicException::class );
		$r->redirect_url();
	}

	public function test_failure_throws_on_intent_short_id_access(): void {
		$r = CheckoutResult::failure( 'Sorry.' );
		$this->expectException( \LogicException::class );
		$r->intent_short_id();
	}

	public function test_success_throws_on_log_message_access(): void {
		$r = CheckoutResult::success( 'https://pay.spart/abc', 'abc' );
		$this->expectException( \LogicException::class );
		$r->log_message();
	}

	public function test_failure_default_failure_code_is_unknown(): void {
		$result = CheckoutResult::failure( 'Boom.' );

		$this->assertSame( CheckoutResult::UNKNOWN_FAILURE_CODE, $result->failure_code() );
	}

	public function test_failure_carries_explicit_failure_code(): void {
		$result = CheckoutResult::failure( 'Boom.', 'log message.', FailureCode::TIMEOUT );

		$this->assertSame( FailureCode::TIMEOUT, $result->failure_code() );
	}

	public function test_success_failure_code_throws_logic_exception(): void {
		$result = CheckoutResult::success( 'https://pay.spart/abc', 'abc' );

		$this->expectException( \LogicException::class );
		$result->failure_code();
	}
}
