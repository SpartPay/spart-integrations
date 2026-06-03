<?php
/**
 * Unit test for Logging\LevelFilteredLogger.
 *
 * @package Spart\WooCommerce\Tests\Unit\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Logging;

use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Logging\LevelFilteredLogger;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

final class LevelFilteredLoggerTest extends TestCase {

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	public function test_warning_and_error_always_forwarded_when_verbose_off(): void {
		$inner = Mockery::mock( SpartLoggerInterface::class );
		$inner->shouldReceive( 'warning' )->once()->with( 'w', array( 'k' => 'v' ) );
		$inner->shouldReceive( 'error' )->once()->with( 'e', array( 'k' => 'v' ) );
		$inner->shouldNotReceive( 'info' );
		$inner->shouldNotReceive( 'debug' );

		$logger = new LevelFilteredLogger( $inner, static fn(): bool => false );
		$logger->warning( 'w', array( 'k' => 'v' ) );
		$logger->error( 'e', array( 'k' => 'v' ) );
		$logger->info( 'i', array( 'k' => 'v' ) );
		$logger->debug( 'd', array( 'k' => 'v' ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_info_and_debug_forwarded_when_verbose_on(): void {
		$inner = Mockery::mock( SpartLoggerInterface::class );
		$inner->shouldReceive( 'info' )->once()->with( 'i', array() );
		$inner->shouldReceive( 'debug' )->once()->with( 'd', array() );
		$inner->shouldReceive( 'warning' )->once();
		$inner->shouldReceive( 'error' )->once();

		$logger = new LevelFilteredLogger( $inner, static fn(): bool => true );
		$logger->info( 'i' );
		$logger->debug( 'd' );
		$logger->warning( 'w' );
		$logger->error( 'e' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Toggle the verbose provider's return value between calls and verify
	 * that each info() / debug() invocation re-reads the current state.
	 * Locks in the bug fix: capturing the bool at construction time meant
	 * a merchant who flipped "verbose logging" in admin during a long-
	 * running request still saw the stale value for the rest of the
	 * request. The callable removes the capture window entirely.
	 */
	public function test_verbose_provider_is_re_evaluated_per_emit(): void {
		$verbose = false;

		$inner = Mockery::mock( SpartLoggerInterface::class );
		// First info() with verbose=false → swallowed.
		// Then verbose flipped to true.
		// Second info() → forwarded once.
		$inner->shouldReceive( 'info' )->once()->with( 'i2', array() );

		$logger = new LevelFilteredLogger(
			$inner,
			static function () use ( &$verbose ): bool {
				return $verbose;
			}
		);

		$logger->info( 'i1' ); // suppressed
		$verbose = true;
		$logger->info( 'i2' ); // forwarded

		$this->addToAssertionCount( 1 );
	}
}
