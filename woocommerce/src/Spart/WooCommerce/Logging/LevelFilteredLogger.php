<?php
/**
 * Logging\LevelFilteredLogger — decorator that gates INFO/DEBUG behind a verbose flag.
 *
 * @package Spart\WooCommerce\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Logging;

/**
 * Decorator over a SpartLoggerInterface that ALWAYS forwards WARNING and
 * ERROR but only forwards INFO and DEBUG when the constructor's
 * `$verbose_provider` callable returns true.
 *
 * Rationale: production stores must always have a record of failures
 * (so support can reconstruct what happened during a botched checkout),
 * but the high-cardinality INFO/DEBUG trace lines stay opt-in to avoid
 * filling the merchant's wc-logs directory with noise.
 *
 * The verbose state is read fresh on EVERY emit. This means flipping the
 * "verbose logging" admin setting takes effect immediately for the next
 * log line, without needing to rebuild the logger or restart anything.
 * The Plugin-level singleton can therefore stay alive across an entire
 * request without leaking stale verbose state.
 */
final class LevelFilteredLogger implements SpartLoggerInterface {

	/**
	 * Wire the decorator.
	 *
	 * @param SpartLoggerInterface $inner            Underlying logger that performs the writes.
	 * @param callable             $verbose_provider Zero-arg callable returning the current bool verbose state. Re-invoked on every info()/debug() call.
	 */
	public function __construct(
		private readonly SpartLoggerInterface $inner,
		private $verbose_provider,
	) {}

	/**
	 * Log at info level (forwarded only when the verbose provider returns true).
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function info( string $message, array $context = array() ): void {
		if ( $this->is_verbose() ) {
			$this->inner->info( $message, $context );
		}
	}

	/**
	 * Log at warning level (always forwarded).
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->inner->warning( $message, $context );
	}

	/**
	 * Log at error level (always forwarded).
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->inner->error( $message, $context );
	}

	/**
	 * Log at debug level (forwarded only when the verbose provider returns true).
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function debug( string $message, array $context = array() ): void {
		if ( $this->is_verbose() ) {
			$this->inner->debug( $message, $context );
		}
	}

	/**
	 * Re-evaluate the verbose state via the injected provider. Cast to
	 * bool defensively so a misconfigured provider returning truthy
	 * non-bools (e.g. '1', 'yes') still behaves predictably.
	 */
	private function is_verbose(): bool {
		return (bool) ( $this->verbose_provider )();
	}
}
