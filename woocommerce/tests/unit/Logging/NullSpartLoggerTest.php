<?php
/**
 * Unit tests for Logging\NullSpartLogger.
 *
 * @package Spart\WooCommerce\Tests\Unit\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Logging\NullSpartLogger;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * @covers \Spart\WooCommerce\Logging\NullSpartLogger
 */
final class NullSpartLoggerTest extends TestCase {

	public function test_implements_interface_and_no_ops(): void {
		$log = new NullSpartLogger();
		$this->assertInstanceOf( SpartLoggerInterface::class, $log );
		$log->info( 'x' );
		$log->warning( 'y', array( 'k' => 'v' ) );
		$log->error( 'z' );
		$log->debug( 'w' );
		// Reaching this point without throwing is the assertion.
		$this->assertTrue( true );
	}
}
