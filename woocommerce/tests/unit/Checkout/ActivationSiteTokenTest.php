<?php
/**
 * Unit tests for the site-token persistence added to Activation::activate().
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Activation;

final class ActivationSiteTokenTest extends TestCase {

	/** @var array<string, mixed> */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->install_fake_wpdb();
		$this->captured = array();

		// Activation now schedules the cleanup cron; these tests focus
		// on the site-token behavior, so stub the cron calls as no-ops.
		// ActivationCleanupCronTest covers the scheduling explicitly.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_activate_writes_site_token_when_absent(): void {
		$captured = &$this->captured;

		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$captured ) {
				$captured['key']   = $key;
				$captured['value'] = $value;
				return true;
			}
		);

		Activation::activate();

		$this->assertArrayHasKey( 'key', $captured );
		$this->assertSame( 'spart_site_token', $captured['key'] );
		$this->assertSame(
			substr( hash( 'sha256', 'https://example.com' ), 0, 8 ),
			$captured['value']
		);
	}

	public function test_activate_does_not_overwrite_existing_token(): void {
		$captured = &$this->captured;

		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( 'deadbeef' );
		Functions\when( 'update_option' )->alias(
			static function ( $key ) use ( &$captured ) {
				$captured['key'] = $key;
				return true;
			}
		);

		Activation::activate();

		$this->assertArrayNotHasKey( 'key', $captured );
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
