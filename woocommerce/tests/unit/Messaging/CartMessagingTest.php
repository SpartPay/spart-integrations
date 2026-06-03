<?php
// tests/unit/Messaging/CartMessagingTest.php
declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Messaging;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Messaging\CartMessaging;

final class CartMessagingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
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

		CartMessaging::register();

		$this->assertCount( 1, $registered );
		$this->assertSame( 'init', $registered[0]['hook'] );
		$this->assertInstanceOf( \Closure::class, $registered[0]['cb'] );
	}

	public function test_init_closure_adds_wc_hook_when_toggle_on(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_cart' => 'yes' ) );
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

		CartMessaging::register();
		( $registered[0]['cb'] )();

		$this->assertCount( 2, $registered );
		$this->assertSame( 'woocommerce_before_cart_totals', $registered[1]['hook'] );
		$this->assertSame( array( CartMessaging::class, 'render_action' ), $registered[1]['cb'] );
		$this->assertSame( 10, $registered[1]['priority'] );
	}

	public function test_init_closure_does_not_add_wc_hook_when_toggle_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_cart' => 'no' ) );
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

		CartMessaging::register();
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

		CartMessaging::register();
		( $registered[0]['cb'] )();

		$this->assertCount( 1, $registered, 'init closure should treat missing option as off' );
	}

	public function test_register_invokes_callback_inline_when_init_already_fired(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_cart' => 'yes' ) );
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

		CartMessaging::register();

		$this->assertCount( 1, $registered, 'init scheduling should be skipped when init already fired' );
		$this->assertSame( 'woocommerce_before_cart_totals', $registered[0]['hook'] );
		$this->assertSame( 10, $registered[0]['priority'] );
	}

	public function test_render_returns_html_with_both_messaging_lines(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_cart' => 'yes' ) );

		$html = CartMessaging::render();

		$this->assertStringContainsString( 'spart-messaging', $html );
		$this->assertStringContainsString( 'spart-messaging--cart', $html );
		$this->assertStringContainsString( 'SPART_MSG_CART_BEFORE_TOTALS_LINE_1', $html );
		$this->assertStringContainsString( 'SPART_MSG_CART_BEFORE_TOTALS_LINE_2', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
	}

	public function test_render_returns_empty_string_when_toggle_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_cart' => 'no' ) );

		$this->assertSame( '', CartMessaging::render() );
	}

	public function test_render_returns_empty_string_when_option_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', CartMessaging::render() );
	}

	public function test_render_action_echoes_html(): void {
		Functions\when( 'get_option' )->justReturn( array( 'messaging_enabled_cart' => 'yes' ) );

		ob_start();
		CartMessaging::render_action();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'spart-messaging--cart', $output );
	}
}
