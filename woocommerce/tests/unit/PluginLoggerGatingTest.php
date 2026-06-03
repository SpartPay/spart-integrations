<?php
/**
 * Regression test for Plugin::logger() level gating.
 *
 * @package Spart\WooCommerce\Tests\Unit
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Logging\LevelFilteredLogger;
use Spart\WooCommerce\Plugin;

final class PluginLoggerGatingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Plugin::reset_for_tests();
	}

	protected function tearDown(): void {
		Plugin::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_logger_is_level_filtered_when_wc_logger_available(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k ? array( 'debug_logging' => 'no' ) : $d
		);
		Monkey\Functions\when( 'wc_get_logger' )->justReturn( new \stdClass() );

		$logger = Plugin::logger();

		$this->assertInstanceOf( LevelFilteredLogger::class, $logger );
	}

	public function test_order_disposer_accessor_returns_singleton(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k ? array() : $d
		);
		Monkey\Functions\when( 'wc_get_logger' )->justReturn( new \stdClass() );

		$a = Plugin::order_disposer();
		$b = Plugin::order_disposer();

		$this->assertInstanceOf( \Spart\WooCommerce\Checkout\OrderDisposer::class, $a );
		$this->assertSame( $a, $b, 'Plugin::order_disposer() must memoise.' );
	}

	public function test_reset_for_tests_clears_order_disposer(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn ( $k, $d ) => 'woocommerce_spart_settings' === $k ? array() : $d
		);
		Monkey\Functions\when( 'wc_get_logger' )->justReturn( new \stdClass() );

		$a = Plugin::order_disposer();
		Plugin::reset_for_tests();
		$b = Plugin::order_disposer();

		$this->assertNotSame( $a, $b );
	}
}
