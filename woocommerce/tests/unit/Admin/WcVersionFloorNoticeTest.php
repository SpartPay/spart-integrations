<?php
/**
 * Unit tests for the WC-version-floor admin notice.
 *
 * @package Spart\WooCommerce\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\WcVersionFloorNotice;

final class WcVersionFloorNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text, $domain ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $domain present to match WP signature.
				echo (string) $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub mimics esc_html_e, no escaping needed.
			}
		);
		Functions\when( 'esc_html' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_admin_notices(): void {
		Actions\expectAdded( 'admin_notices' )->once();

		( new WcVersionFloorNotice() )->register();

		$this->addToAssertionCount( 1 );
	}

	public function test_render_emits_nothing_when_wc_version_constant_undefined(): void {
		// WC_VERSION is intentionally NOT defined in this test process —
		// the notice has nothing to compare against.
		ob_start();
		try {
			( new WcVersionFloorNotice() )->render();
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertSame( '', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_emits_warning_when_wc_version_below_floor(): void {
		define( 'WC_VERSION', '7.9.0' );
		// Re-mock the i18n shims inside the isolated child process.
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text, $domain ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $domain present to match WP signature.
				echo (string) $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub mimics esc_html_e, no escaping needed.
			}
		);
		Functions\when( 'esc_html' )->returnArg( 1 );

		ob_start();
		try {
			( new WcVersionFloorNotice() )->render();
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( '7.9.0', $output );
		$this->assertStringContainsString( '8.0', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_emits_nothing_when_wc_version_meets_floor(): void {
		define( 'WC_VERSION', '8.0.0' );
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text, $domain ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $domain present to match WP signature.
				echo (string) $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub mimics esc_html_e, no escaping needed.
			}
		);
		Functions\when( 'esc_html' )->returnArg( 1 );

		ob_start();
		try {
			( new WcVersionFloorNotice() )->render();
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertSame( '', $output );
	}
}
