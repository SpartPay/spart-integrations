<?php
/**
 * Unit tests for Activation.
 *
 * @package Spart\WooCommerce\Tests\Unit
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Activation;
use Spart\WooCommerce\Persistence\WebhookDeliveriesSchema;

final class ActivationTest extends TestCase {

	/** @var array<string, mixed> */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->install_fake_wpdb();
		$this->captured = array();

		// Activation now schedules the cleanup cron; these tests focus
		// on dbDelta/option behavior, so stub the cron calls as no-ops.
		// ActivationCleanupCronTest covers the scheduling explicitly.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_activate_calls_db_delta_with_schema_sql(): void {
		$captured = &$this->captured;
		Functions\when( 'dbDelta' )->alias(
			static function ( $sql ) use ( &$captured ) {
				$captured['sql'] = $sql;
				return array();
			}
		);
		Functions\when( 'get_option' )->justReturn( 'existingtoken' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		Activation::activate();

		$this->assertArrayHasKey( 'sql', $captured );
		$this->assertSame(
			WebhookDeliveriesSchema::create_table_sql( 'wp_', 'DEFAULT CHARSET=utf8mb4' ),
			$captured['sql']
		);
	}

	public function test_activate_uses_wpdb_prefix_and_charset_collate(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'custom_';
			public function get_charset_collate(): string {
				return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
			}
		};

		$captured = &$this->captured;
		Functions\when( 'dbDelta' )->alias(
			static function ( $sql ) use ( &$captured ) {
				$captured['sql'] = $sql;
				return array();
			}
		);
		Functions\when( 'get_option' )->justReturn( 'existingtoken' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		Activation::activate();

		$this->assertStringContainsString( 'CREATE TABLE custom_spart_webhook_deliveries', $captured['sql'] );
		$this->assertStringEndsWith( 'utf8mb4_unicode_520_ci;', \trim( $captured['sql'] ) );
	}

	/**
	 * Truly fresh install: spart_site_token does not yet exist (the canonical
	 * first-install signal). Activation must persist both the new site token
	 * AND set spart_webhook_url_migration_dismissed to true so the merchant
	 * never sees a migration notice they have nothing to migrate from.
	 *
	 * @return void
	 */
	public function test_activate_suppresses_migration_notice_on_truly_fresh_install(): void {
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'spart_site_token' === $name ) {
					return '';
				}
				if ( 'spart_webhook_url_migration_dismissed' === $name ) {
					return $default;
				}
				if ( 'spart_destroy_orders_upgrade_notice_dismissed' === $name ) {
					return $default;
				}
				return $default;
			}
		);

		Functions\expect( 'update_option' )
			->once()
			->with( 'spart_site_token', \Mockery::any(), false )
			->andReturn( true );
		Functions\expect( 'update_option' )
			->once()
			->with( 'spart_webhook_url_migration_dismissed', true, false )
			->andReturn( true );
		Functions\expect( 'update_option' )
			->once()
			->with( 'spart_destroy_orders_upgrade_notice_dismissed', true, false )
			->andReturn( true );

		Activation::activate();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Existing PR2 site reactivating after a file-level upgrade to PR3:
	 * spart_site_token already exists (PR2 set it on its own activation),
	 * so even if spart_webhook_url_migration_dismissed is absent we MUST
	 * NOT suppress the migration notice — the merchant genuinely needs to
	 * update their dashboard URL. Regression test for the Copilot review
	 * comment on PR #220.
	 *
	 * @return void
	 */
	public function test_activate_does_not_suppress_migration_notice_when_site_token_already_exists(): void {
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'spart_site_token' === $name ) {
					return 'existingtoken';
				}
				if ( 'spart_webhook_url_migration_dismissed' === $name ) {
					return $default;
				}
				return $default;
			}
		);

		Functions\expect( 'update_option' )
			->with( 'spart_webhook_url_migration_dismissed', \Mockery::any(), \Mockery::any() )
			->never();

		Activation::activate();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Existing install where the dismissed flag is already persisted (e.g.
	 * PR3 user re-activating after they previously dismissed the notice).
	 * Activation must not overwrite it — and with the fresh-install gating
	 * in place, the option isn't even consulted. Belt-and-braces test that
	 * the suppression branch never fires when site_token already exists.
	 *
	 * @return void
	 */
	public function test_activate_does_not_overwrite_existing_migration_dismissed_flag(): void {
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'spart_site_token' === $name ) {
					return 'existingtoken';
				}
				if ( 'spart_webhook_url_migration_dismissed' === $name ) {
					return true;
				}
				return $default;
			}
		);

		Functions\expect( 'update_option' )
			->with( 'spart_webhook_url_migration_dismissed', \Mockery::any(), \Mockery::any() )
			->never();

		Activation::activate();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Existing PR<n> site reactivating after a file-level upgrade that
	 * adds the destroy-on-failure feature: spart_site_token already exists
	 * so even if spart_destroy_orders_upgrade_notice_dismissed is absent we
	 * MUST NOT suppress the upgrade notice — the merchant genuinely needs
	 * to be told about the behavior change.
	 *
	 * @return void
	 */
	public function test_activate_does_not_suppress_destroy_orders_notice_when_site_token_already_exists(): void {
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'spart_site_token' === $name ) {
					return 'existingtoken';
				}
				return $default;
			}
		);

		Functions\expect( 'update_option' )
			->with( 'spart_destroy_orders_upgrade_notice_dismissed', \Mockery::any(), \Mockery::any() )
			->never();

		Activation::activate();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Existing install where the destroy-orders dismissed flag is already
	 * persisted (e.g. merchant re-activating after they previously dismissed
	 * the notice). Activation must not overwrite it — and with the
	 * fresh-install gating in place, the option isn't even consulted.
	 *
	 * @return void
	 */
	public function test_activate_does_not_overwrite_existing_destroy_orders_dismissed_flag(): void {
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'spart_site_token' === $name ) {
					return 'existingtoken';
				}
				if ( 'spart_destroy_orders_upgrade_notice_dismissed' === $name ) {
					return true;
				}
				return $default;
			}
		);

		Functions\expect( 'update_option' )
			->with( 'spart_destroy_orders_upgrade_notice_dismissed', \Mockery::any(), \Mockery::any() )
			->never();

		Activation::activate();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Installs a `$GLOBALS['wpdb']` double exposing only the surface
	 * Activation::activate() needs.
	 */
	private function install_fake_wpdb(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function get_charset_collate(): string {
				return 'DEFAULT CHARSET=utf8mb4';
			}
		};
	}
}
