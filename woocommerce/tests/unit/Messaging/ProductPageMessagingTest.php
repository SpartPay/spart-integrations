<?php
// tests/unit/Messaging/ProductPageMessagingTest.php
declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Messaging;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Messaging\ProductPageMessaging;

final class ProductPageMessagingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'plugins_url' )->alias(
			static fn( string $path, string $file ): string => // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $file kept to match WP signature.
				'https://example.test/wp-content/plugins/spart-woocommerce/' . ltrim( $path, '/' )
		);
		Functions\when( 'trailingslashit' )->alias(
			static fn( string $s ): string => rtrim( $s, '/' ) . '/'
		);
		Functions\when( 'plugin_dir_path' )->alias(
			static fn( string $f ): string => '/var/www/spart-woocommerce/' // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $f kept to match WP signature.
		);
		Functions\when( 'did_action' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_adds_init_closure_unconditionally(): void {
		$registered = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $cb, int $priority = 10 ) use ( &$registered ): void {
				$registered[] = array(
					'hook'     => $hook,
					'cb'       => $cb,
					'priority' => $priority,
				);
			}
		);

		ProductPageMessaging::register();

		$this->assertCount( 1, $registered );
		$this->assertSame( 'init', $registered[0]['hook'] );
		$this->assertInstanceOf( \Closure::class, $registered[0]['cb'] );
	}

	public function test_init_closure_adds_wc_hook_when_toggle_on(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_product' => 'yes' ) );
		$registered = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $cb, int $priority = 10 ) use ( &$registered ): void {
				$registered[] = array(
					'hook'     => $hook,
					'cb'       => $cb,
					'priority' => $priority,
				);
			}
		);

		ProductPageMessaging::register();
		( $registered[0]['cb'] )();

		$this->assertCount( 2, $registered );
		$this->assertSame( 'woocommerce_single_product_summary', $registered[1]['hook'] );
		$this->assertSame( array( ProductPageMessaging::class, 'render_action' ), $registered[1]['cb'] );
		$this->assertSame( 11, $registered[1]['priority'] );
	}

	public function test_init_closure_does_not_add_wc_hook_when_toggle_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_product' => 'no' ) );
		$registered = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $cb, int $priority = 10 ) use ( &$registered ): void {
				$registered[] = array(
					'hook'     => $hook,
					'cb'       => $cb,
					'priority' => $priority,
				);
			}
		);

		ProductPageMessaging::register();
		( $registered[0]['cb'] )();

		$this->assertCount( 1, $registered, 'init closure should not register the WC hook when toggle is off' );
	}

	public function test_init_closure_does_not_add_wc_hook_when_option_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$registered = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $cb, int $priority = 10 ) use ( &$registered ): void {
				$registered[] = array(
					'hook'     => $hook,
					'cb'       => $cb,
					'priority' => $priority,
				);
			}
		);

		ProductPageMessaging::register();
		( $registered[0]['cb'] )();

		$this->assertCount( 1, $registered, 'init closure should treat missing option as off' );
	}

	public function test_register_invokes_callback_inline_when_init_already_fired(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_product' => 'yes' ) );
		$registered = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $cb, int $priority = 10 ) use ( &$registered ): void {
				$registered[] = array(
					'hook'     => $hook,
					'cb'       => $cb,
					'priority' => $priority,
				);
			}
		);

		ProductPageMessaging::register();

		$this->assertCount( 1, $registered, 'init scheduling should be skipped when init already fired' );
		$this->assertSame( 'woocommerce_single_product_summary', $registered[0]['hook'] );
		$this->assertSame( 11, $registered[0]['priority'] );
	}

	public function test_render_returns_html_with_both_messaging_lines(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_product' => 'yes' ) );

		$html = ProductPageMessaging::render();

		$this->assertStringContainsString( 'spart-messaging', $html );
		$this->assertStringContainsString( 'spart-messaging--product', $html );
		$this->assertStringContainsString( 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1', $html );
		$this->assertStringContainsString( 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_2', $html );
		$this->assertStringNotContainsString( 'aria-live', $html );
	}

	public function test_render_returns_empty_string_when_toggle_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_product' => 'no' ) );

		$this->assertSame( '', ProductPageMessaging::render() );
	}

	public function test_render_returns_empty_string_when_option_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', ProductPageMessaging::render() );
	}

	public function test_render_action_echoes_html(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_product' => 'yes' ) );

		ob_start();
		ProductPageMessaging::render_action();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'spart-messaging--product', $output );
	}
}
