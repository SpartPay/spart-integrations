<?php
/**
 * Unit tests for Admin\WebhookUrlMigrationNotice.
 *
 * @package Spart\WooCommerce\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\WebhookUrlMigrationNotice;

/**
 * Coverage for WebhookUrlMigrationNotice rendering, dismissal, and
 * permission-check branches.
 */
final class WebhookUrlMigrationNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Common i18n / escaping passthroughs — tests that need richer
		// behavior override these per-test via Functions\expect().
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

	/**
	 * register() wires the render callback to admin_notices and the dismiss
	 * handler to admin_post_<action>. Both registrations are unconditional
	 * (cheap add_action calls); the actual gating happens inside render().
	 *
	 * @return void
	 */
	public function test_register_adds_admin_notices_and_admin_post_actions(): void {
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'admin_post_' . WebhookUrlMigrationNotice::DISMISS_ACTION )->once();

		( new WebhookUrlMigrationNotice() )->register();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * render() must short-circuit when spart_webhook_url_migration_dismissed
	 * is anything other than the WP-default false (i.e. the option exists in
	 * wp_options). Output buffer must remain empty so the admin notices area
	 * shows nothing for this notice.
	 *
	 * @return void
	 */
	public function test_render_outputs_nothing_when_dismissed(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( WebhookUrlMigrationNotice::OPTION_NAME === $name ) {
					return true;
				}
				return $default;
			}
		);

		\ob_start();
		( new WebhookUrlMigrationNotice() )->render();
		$output = (string) \ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * render() must emit a notice div containing the canonical PR3 REST
	 * webhook URL plus a dismiss link carrying the wp_nonce_url for the
	 * admin-post.php dismiss action. On sites with pretty permalinks the
	 * URL has the /wp-json/ prefix.
	 *
	 * @return void
	 */
	public function test_render_outputs_notice_with_rest_webhook_url_when_not_dismissed(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( WebhookUrlMigrationNotice::OPTION_NAME === $name ) {
					return $default;
				}
				return $default;
			}
		);
		Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'admin_url' )->alias(
			static fn ( $path = '' ) => 'https://example.test/wp-admin/' . $path
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static fn ( $url, $action ) => $url . '&_wpnonce=fake-nonce-' . $action
		);

		\ob_start();
		( new WebhookUrlMigrationNotice() )->render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'notice notice-warning', $output );
		$this->assertStringContainsString(
			'https://example.test/wp-json/spart/v1/webhook',
			$output
		);
		$this->assertStringContainsString( 'Spart webhook URL has changed', $output );
		$this->assertStringContainsString( 'Got it, dismiss', $output );
		$this->assertStringContainsString(
			'admin-post.php?action=' . WebhookUrlMigrationNotice::DISMISS_ACTION,
			$output
		);
		$this->assertStringContainsString(
			'_wpnonce=fake-nonce-' . WebhookUrlMigrationNotice::DISMISS_ACTION,
			$output
		);
	}

	/**
	 * On Plain-permalink sites WP's rest_url() returns the ?rest_route=
	 * query-string form. The migration notice must surface that exact URL
	 * (not the /wp-json/ form which would 404 on hosts without mod_rewrite),
	 * so merchants copy/paste the correct receiver URL into their dashboard.
	 *
	 * @return void
	 */
	public function test_render_outputs_query_param_webhook_url_with_plain_permalinks(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( $name, $default = false ) => $default
		);
		Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'https://example.test/?rest_route=/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'admin_url' )->alias(
			static fn ( $path = '' ) => 'https://example.test/wp-admin/' . $path
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static fn ( $url, $action ) => $url . '&_wpnonce=fake-nonce-' . $action
		);

		\ob_start();
		( new WebhookUrlMigrationNotice() )->render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString(
			'https://example.test/?rest_route=/spart/v1/webhook',
			$output
		);
	}

	/**
	 * handle_dismiss() validates the nonce first; with a valid nonce but a
	 * caller lacking manage_options, it fires wp_die. update_option and the
	 * redirect must NOT run. wp_die is mocked to throw so the test can
	 * observe early termination.
	 *
	 * @return void
	 */
	public function test_handle_dismiss_calls_wp_die_when_user_lacks_manage_options(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( WebhookUrlMigrationNotice::DISMISS_ACTION )
			->andReturn( 1 );
		Functions\when( 'current_user_can' )->alias(
			static fn ( $cap ) => 'manage_options' === $cap ? false : true
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

		( new WebhookUrlMigrationNotice() )->handle_dismiss();
	}

	/**
	 * handle_dismiss() rejects callers with a missing/invalid nonce BEFORE
	 * checking capabilities, so the same 403/exit response is returned
	 * regardless of whether the caller is an admin. This avoids leaking a
	 * capability oracle through differing error messages.
	 *
	 * @return void
	 */
	public function test_handle_dismiss_rejects_invalid_nonce_before_capability_check(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( WebhookUrlMigrationNotice::DISMISS_ACTION )
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

		( new WebhookUrlMigrationNotice() )->handle_dismiss();
	}

	/**
	 * handle_dismiss() persists the dismissal and redirects when the caller
	 * has manage_options and the nonce passes. wp_safe_redirect is mocked
	 * to throw an exception to simulate the immediately-following exit;
	 * the test asserts update_option fired exactly once with the canonical
	 * arguments before the redirect short-circuits execution.
	 *
	 * @return void
	 */
	public function test_handle_dismiss_persists_dismissal_and_redirects_when_user_authorized(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( 'https://example.test/wp-admin/plugins.php' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/' );

		Functions\expect( 'update_option' )
			->once()
			->with( WebhookUrlMigrationNotice::OPTION_NAME, true, false )
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

		( new WebhookUrlMigrationNotice() )->handle_dismiss();
	}
}
