<?php
/**
 * Unit tests for Webhooks\RestRouteRegistrar.
 *
 * @package Spart\WooCommerce\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Webhooks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Webhooks\RestRouteRegistrar;
use Spart\WooCommerce\Webhooks\WebhookReceiver;

/**
 * @covers \Spart\WooCommerce\Webhooks\RestRouteRegistrar
 */
final class RestRouteRegistrarTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_register_calls_register_rest_route_with_expected_arguments(): void {
		$receiver = Mockery::mock( WebhookReceiver::class );

		$captured_args = null;
		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				RestRouteRegistrar::NAMESPACE,
				RestRouteRegistrar::ROUTE,
				Mockery::on(
					static function ( $args ) use ( &$captured_args ) {
						$captured_args = $args;
						return is_array( $args );
					}
				)
			)
			->andReturn( true );

		( new RestRouteRegistrar( $receiver ) )->register();

		self::assertSame( 'spart/v1', RestRouteRegistrar::NAMESPACE );
		self::assertSame( '/webhook', RestRouteRegistrar::ROUTE );
		self::assertIsArray( $captured_args );
		self::assertSame( 'POST', $captured_args['methods'] );
		self::assertSame( '__return_true', $captured_args['permission_callback'] );
		self::assertIsArray( $captured_args['callback'] );
		self::assertSame( $receiver, $captured_args['callback'][0] );
		self::assertSame( 'handle', $captured_args['callback'][1] );
	}

	public function test_register_uses_open_permission_callback_because_hmac_is_the_authorization(): void {
		$receiver = Mockery::mock( WebhookReceiver::class );

		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				Mockery::any(),
				Mockery::any(),
				Mockery::on(
					static function ( $args ): bool {
						return isset( $args['permission_callback'] )
							&& '__return_true' === $args['permission_callback'];
					}
				)
			)
			->andReturn( true );

		( new RestRouteRegistrar( $receiver ) )->register();
		$this->addToAssertionCount( 1 );
	}
}
