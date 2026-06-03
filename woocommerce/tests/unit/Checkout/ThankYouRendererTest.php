<?php
/**
 * Unit tests for Checkout\ThankYouRenderer.
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Checkout\ThankYouRenderer;

/**
 * @covers \Spart\WooCommerce\Checkout\ThankYouRenderer
 */
final class ThankYouRendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function captureRender( int $order_id ): string {
		ob_start();
		( new ThankYouRenderer() )->render( $order_id );
		return (string) ob_get_clean();
	}

	private function mockOrderWithStatus( string $status ): \WC_Order {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		return $order;
	}

	public function test_pending_status_renders_placeholder_paragraph(): void {
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 42 )
			->andReturn( $this->mockOrderWithStatus( 'pending' ) );

		$output = $this->captureRender( 42 );

		$this->assertStringContainsString( '<p class="spart-thankyou-pending">', $output );
		$this->assertStringContainsString(
			"Your payment is being processed by Spart. You'll receive a confirmation email shortly.",
			$output
		);
	}

	public function test_on_hold_status_renders_placeholder(): void {
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 7 )
			->andReturn( $this->mockOrderWithStatus( 'on-hold' ) );

		$output = $this->captureRender( 7 );

		$this->assertStringContainsString( '<p class="spart-thankyou-pending">', $output );
		$this->assertStringContainsString(
			"Your payment is being processed by Spart. You'll receive a confirmation email shortly.",
			$output
		);
	}

	public function test_processing_status_does_not_render(): void {
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 99 )
			->andReturn( $this->mockOrderWithStatus( 'processing' ) );

		$this->assertSame( '', $this->captureRender( 99 ) );
	}

	public function test_completed_status_does_not_render(): void {
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 11 )
			->andReturn( $this->mockOrderWithStatus( 'completed' ) );

		$this->assertSame( '', $this->captureRender( 11 ) );
	}

	public function test_missing_order_does_not_render(): void {
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 0 )
			->andReturn( false );

		$this->assertSame( '', $this->captureRender( 0 ) );
	}
}
