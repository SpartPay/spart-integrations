<?php
/**
 * Logging\ElapsedTime — monotonic elapsed-time conversion for telemetry.
 *
 * @package Spart\WooCommerce\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Logging;

/**
 * Convert monotonic nanosecond timestamps to rounded millisecond deltas.
 */
final class ElapsedTime {

	private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

	/**
	 * Capture the current monotonic clock value.
	 *
	 * @return int Nanoseconds from hrtime(true).
	 */
	public static function start(): int {
		return \hrtime( true );
	}

	/**
	 * Calculate rounded milliseconds elapsed since the starting timestamp.
	 *
	 * @param int $started_at Nanosecond timestamp from start().
	 * @return float Rounded millisecond delta.
	 */
	public static function milliseconds_since( int $started_at ): float {
		$elapsed_nanoseconds = max( 0, \hrtime( true ) - $started_at );
		return round( $elapsed_nanoseconds / self::NANOSECONDS_PER_MILLISECOND, 3 );
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
