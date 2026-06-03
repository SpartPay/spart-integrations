<?php
/**
 * Unit tests for Admin\DestroyOrdersUpgradeNotice.
 *
 * @package Spart\WooCommerce\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\DestroyOrdersUpgradeNotice;

/**
 * Coverage for DestroyOrdersUpgradeNotice rendering, dismissal, and
 * permission-check branches. Mirrors WebhookUrlMigrationNoticeTest.
 */
final class DestroyOrdersUpgradeNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test passthrough.
			}
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_adds_admin_notices_and_admin_post_actions(): void {
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'admin_post_' . DestroyOrdersUpgradeNotice::DISMISS_ACTION )->once();

		( new DestroyOrdersUpgradeNotice() )->register();

		$this->addToAssertionCount( 1 );
	}

	public function test_render_outputs_nothing_when_user_lacks_manage_woocommerce(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			static fn ( $cap ) => 'manage_woocommerce' === $cap ? false : true
		);

		\ob_start();
		( new DestroyOrdersUpgradeNotice() )->render();
		$output = (string) \ob_get_clean();

		$this->assertSame( '', $output, 'render() must short-circuit for users without manage_woocommerce — low-privilege users (subscribers, contributors) should not see a merchant-targeted notice they cannot dismiss anyway' );
	}

	public function test_render_outputs_nothing_when_dismissed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( DestroyOrdersUpgradeNotice::OPTION_NAME === $name ) {
					return true;
				}
				return $default;
			}
		);

		\ob_start();
		( new DestroyOrdersUpgradeNotice() )->render();
		$output = (string) \ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_render_outputs_notice_explaining_destroy_on_failure_when_not_dismissed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'admin_url' )->alias(
			static fn ( $path = '' ) => 'https://example.test/wp-admin/' . $path
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static fn ( $url, $action ) => $url . '&_wpnonce=fake-nonce-' . $action
		);

		\ob_start();
		( new DestroyOrdersUpgradeNotice() )->render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'notice notice-info', $output );
		$this->assertStringContainsString( 'Spart now destroys pending orders', $output );
		$this->assertStringContainsString( 'Got it, dismiss', $output );
		$this->assertStringContainsString(
			'admin-post.php?action=' . DestroyOrdersUpgradeNotice::DISMISS_ACTION,
			$output
		);
		$this->assertStringContainsString(
			'_wpnonce=fake-nonce-' . DestroyOrdersUpgradeNotice::DISMISS_ACTION,
			$output
		);
	}

	public function test_handle_dismiss_rejects_invalid_nonce_before_capability_check(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( DestroyOrdersUpgradeNotice::DISMISS_ACTION )
			->andReturnUsing(
				static function () {
					throw new \RuntimeException( 'nonce_rejected' );
				}
			);
		Functions\expect( 'current_user_can' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'nonce_rejected' );

		( new DestroyOrdersUpgradeNotice() )->handle_dismiss();
	}

	public function test_handle_dismiss_calls_wp_die_when_user_lacks_manage_woocommerce(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( DestroyOrdersUpgradeNotice::DISMISS_ACTION )
			->andReturn( 1 );
		Functions\when( 'current_user_can' )->alias(
			static fn ( $cap ) => 'manage_woocommerce' === $cap ? false : true
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die_called' );
			}
		);
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die_called' );

		( new DestroyOrdersUpgradeNotice() )->handle_dismiss();
	}

	public function test_handle_dismiss_persists_dismissal_and_redirects_when_user_authorized(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( 'https://example.test/wp-admin/plugins.php' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/' );

		Functions\expect( 'update_option' )
			->once()
			->with( DestroyOrdersUpgradeNotice::OPTION_NAME, true, false )
			->andReturn( true );
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( 'https://example.test/wp-admin/plugins.php' )
			->andReturnUsing(
				static function () {
					throw new \RuntimeException( 'redirect_called' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect_called' );

		( new DestroyOrdersUpgradeNotice() )->handle_dismiss();
	}
}
