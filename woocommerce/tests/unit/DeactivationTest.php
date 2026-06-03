<?php
/**
 * Unit tests for Deactivation.
 *
 * @package Spart\WooCommerce\Tests\Unit
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Deactivation;
use Spart\WooCommerce\Webhooks\CleanupCron;

final class DeactivationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Deactivation::deactivate() must clear the scheduled cleanup cron
	 * by delegating to CleanupCron::unschedule(), which in turn calls
	 * wp_clear_scheduled_hook() with the cleanup hook name.
	 *
	 * @return void
	 */
	public function test_deactivate_clears_scheduled_cleanup_cron_hook(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( CleanupCron::HOOK );

		Deactivation::deactivate();

		$this->addToAssertionCount( 1 );
	}
}
