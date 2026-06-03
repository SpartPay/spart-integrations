<?php
/**
 * Unit test for WC_Gateway_Spart::process_payment.
 *
 * @package Spart\WooCommerce\Tests\Unit\Gateway
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Gateway;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Checkout\CheckoutResult;
use Spart\WooCommerce\Checkout\CheckoutSession;
use Spart\WooCommerce\Checkout\FailureCode;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Tests\Unit\Gateway\Fixtures\DisposerSpy;

/**
 * Locks the gateway adapter contract: WC_Gateway_Spart::process_payment
 * forwards the order to CheckoutSession and translates the result into
 * WooCommerce's `['result' => …, 'redirect' => …]` array.
 */
final class ProcessPaymentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Plugin::reset_for_tests();
		Monkey\Functions\when( 'home_url' )->justReturn( 'https://shop.example/' );
		Monkey\Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'https://shop.example/wp-json/' . ltrim( (string) $path, '/' )
		);
		Monkey\Functions\when( 'wc_get_logger' )->justReturn( new \stdClass() );
		Monkey\Functions\when( 'get_option' )->justReturn( array() );
		Monkey\Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Plugin::reset_for_tests();
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_success_returns_redirect_array(): void {
		$order = $this->order( 42 );
		Monkey\Functions\when( 'wc_get_order' )->justReturn( $order );
		Monkey\Functions\when( 'wc_add_notice' )->justReturn( null );

		$session = Mockery::mock( CheckoutSession::class );
		$session->shouldReceive( 'checkout' )
			->with( $order, Mockery::type( 'string' ) )
			->andReturn( CheckoutResult::success( 'https://pay.spart/abc', 'abc' ) );

		$this->inject_session( $session );

		$gateway = new WC_Gateway_Spart();
		$out     = $gateway->process_payment( 42 );

		$this->assertSame( 'success', $out['result'] );
		$this->assertSame( 'https://pay.spart/abc', $out['redirect'] );
	}

	public function test_failure_returns_fail_result_and_adds_notice(): void {
		$order    = $this->order( 7 );
		$captured = array();
		Monkey\Functions\when( 'wc_get_order' )->justReturn( $order );
		Monkey\Functions\when( 'wc_add_notice' )->alias(
			static function ( $msg, $type = 'success' ) use ( &$captured ): void {
				$captured[] = array(
					'msg'  => $msg,
					'type' => $type,
				);
			}
		);

		$session = Mockery::mock( CheckoutSession::class );
		$session->shouldReceive( 'checkout' )
			->with( $order, Mockery::type( 'string' ) )
			->andReturn( CheckoutResult::failure( 'We could not start your payment.' ) );

		$this->inject_session( $session );

		$gateway = new WC_Gateway_Spart();
		$out     = $gateway->process_payment( 7 );

		$this->assertSame( 'fail', $out['result'] );
		$this->assertSame( '', $out['redirect'] );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'error', $captured[0]['type'] );
		$this->assertSame( 'We could not start your payment.', $captured[0]['msg'] );
	}

	public function test_unknown_order_returns_failure_array(): void {
		Monkey\Functions\when( 'wc_get_order' )->justReturn( false );
		$captured = array();
		Monkey\Functions\when( 'wc_add_notice' )->alias(
			static function ( $msg, $type = 'success' ) use ( &$captured ): void {
				$captured[] = array(
					'msg'  => $msg,
					'type' => $type,
				);
			}
		);

		$gateway = new WC_Gateway_Spart();
		$out     = $gateway->process_payment( 99999 );

		$this->assertSame( 'fail', $out['result'] );
		$this->assertSame( '', $out['redirect'] );
		$this->assertCount( 1, $captured );
		$this->assertStringContainsString( 'could not load your order', $captured[0]['msg'] );
	}

	public function test_failure_path_invokes_order_disposer(): void {
		$order = $this->order( 13 );
		Monkey\Functions\when( 'wc_get_order' )->justReturn( $order );
		Monkey\Functions\when( 'wc_add_notice' )->justReturn( null );
		Monkey\Functions\when( 'wp_generate_uuid4' )->justReturn( 'corr-uuid-13' );

		$session = Mockery::mock( CheckoutSession::class );
		$session->shouldReceive( 'checkout' )
			->with( $order, 'corr-uuid-13' )
			->andReturn( CheckoutResult::failure( 'No.', 'log.', FailureCode::TIMEOUT ) );
		$this->inject_session( $session );

		$disposer = new DisposerSpy();
		Plugin::set_order_disposer_for_tests( $disposer );

		$gateway = new WC_Gateway_Spart();
		$out     = $gateway->process_payment( 13 );

		$this->assertSame( 'fail', $out['result'] );
		$this->assertCount( 1, $disposer->calls );
		$this->assertSame( $order, $disposer->calls[0]['order'] );
		$this->assertInstanceOf( CheckoutResult::class, $disposer->calls[0]['result'] );
		$this->assertSame( 'corr-uuid-13', $disposer->calls[0]['correlation_id'] );
	}

	public function test_success_path_does_not_invoke_disposer(): void {
		$order = $this->order( 14 );
		Monkey\Functions\when( 'wc_get_order' )->justReturn( $order );
		Monkey\Functions\when( 'wp_generate_uuid4' )->justReturn( 'corr-uuid-14' );

		$session = Mockery::mock( CheckoutSession::class );
		$session->shouldReceive( 'checkout' )
			->with( $order, 'corr-uuid-14' )
			->andReturn( CheckoutResult::success( 'https://pay.spart/x', 'x' ) );
		$this->inject_session( $session );

		$disposer = new DisposerSpy();
		Plugin::set_order_disposer_for_tests( $disposer );

		$gateway = new WC_Gateway_Spart();
		$out     = $gateway->process_payment( 14 );

		$this->assertSame( 'success', $out['result'] );
		$this->assertCount( 0, $disposer->calls );
	}

	private function order( int $id ): \WC_Order {
		$o = new \WC_Order();
		$o->__test_init(
			array(
				'id'       => $id,
				'currency' => 'USD',
				'total'    => '99.99',
				'email'    => 'jane@example.com',
				'first'    => 'Jane',
				'last'     => 'Doe',
				'items'    => array(
					array(
						'name' => 'Widget',
						'qty'  => 1,
					),
				),
			)
		);
		return $o;
	}

	/**
	 * Reflectively replace Plugin's lazy CheckoutSession singleton.
	 */
	private function inject_session( CheckoutSession $session ): void {
		$reflection = new \ReflectionClass( Plugin::class );
		$prop       = $reflection->getProperty( 'checkout_session' );
		$prop->setValue( null, $session );
	}
}
