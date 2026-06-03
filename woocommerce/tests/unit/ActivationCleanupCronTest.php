<?php
/**
 * Unit tests for the cleanup-cron scheduling added to Activation::activate().
 *
 * @package Spart\WooCommerce\Tests\Unit
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Activation;
use Spart\WooCommerce\Webhooks\CleanupCron;

/**
 * @covers \Spart\WooCommerce\Activation
 * @covers \Spart\WooCommerce\Webhooks\CleanupCron
 */
final class ActivationCleanupCronTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->install_fake_wpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_activate_schedules_cleanup_cron_when_not_already_scheduled(): void {
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( 'existingtoken' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( CleanupCron::HOOK )
			->andReturn( false );

		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( Mockery::type( 'int' ), 'daily', CleanupCron::HOOK )
			->andReturn( true );

		Activation::activate();
		$this->addToAssertionCount( 1 );
	}

	public function test_activate_does_not_reschedule_when_cleanup_cron_already_scheduled(): void {
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( 'existingtoken' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( CleanupCron::HOOK )
			->andReturn( strtotime( '+1 day' ) );

		Functions\expect( 'wp_schedule_event' )->never();

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
