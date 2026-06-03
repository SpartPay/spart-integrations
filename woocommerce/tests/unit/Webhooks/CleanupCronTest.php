<?php
/**
 * Unit tests for Webhooks\CleanupCron.
 *
 * @package Spart\WooCommerce\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Webhooks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Logging\SpartLoggerInterface;
use Spart\WooCommerce\Webhooks\CleanupCron;
use Spart\WooCommerce\Webhooks\DeliveryRepository;

/**
 * @covers \Spart\WooCommerce\Webhooks\CleanupCron
 */
final class CleanupCronTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_schedule_creates_event_when_not_already_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( CleanupCron::HOOK )
			->andReturn( false );

		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( Mockery::type( 'int' ), 'daily', CleanupCron::HOOK )
			->andReturn( true );

		CleanupCron::schedule();
		$this->addToAssertionCount( 1 );
	}

	public function test_schedule_is_noop_when_already_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( CleanupCron::HOOK )
			->andReturn( strtotime( '+1 day' ) );

		Functions\expect( 'wp_schedule_event' )->never();

		CleanupCron::schedule();
		$this->addToAssertionCount( 1 );
	}

	public function test_unschedule_clears_every_instance(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( CleanupCron::HOOK )
			->andReturn( 1 );

		CleanupCron::unschedule();
		$this->addToAssertionCount( 1 );
	}

	public function test_run_delegates_to_repository_and_logs_row_count(): void {
		$repository = Mockery::mock( DeliveryRepository::class );
		$repository->shouldReceive( 'cleanup_older_than' )
			->once()
			->with( 30 )
			->andReturn( 7 );

		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )
			->once()
			->with(
				'webhook.cleanup.run',
				array(
					'retention_days' => 30,
					'rows_deleted'   => 7,
				)
			);

		( new CleanupCron( $repository, $logger ) )->run();
		$this->addToAssertionCount( 1 );
	}

	public function test_run_logs_zero_when_repository_deletes_nothing(): void {
		$repository = Mockery::mock( DeliveryRepository::class );
		$repository->shouldReceive( 'cleanup_older_than' )
			->once()
			->with( 30 )
			->andReturn( 0 );

		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )
			->once()
			->with(
				'webhook.cleanup.run',
				array(
					'retention_days' => 30,
					'rows_deleted'   => 0,
				)
			);

		( new CleanupCron( $repository, $logger ) )->run();
		$this->addToAssertionCount( 1 );
	}
}
