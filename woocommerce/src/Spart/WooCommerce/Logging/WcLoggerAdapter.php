<?php
/**
 * Logging\WcLoggerAdapter — bridges SpartLoggerInterface to WC_Logger_Interface.
 *
 * @package Spart\WooCommerce\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Logging;

/**
 * Adapter from SpartLoggerInterface to WC's wc_get_logger() output.
 *
 * Used when debug_logging=yes. Wraps any object that quacks like
 * WC_Logger_Interface (info/warning/error/debug methods accepting
 * `string $message, array $context`). The "source" context key is set
 * to "spart" so messages land in `wc-logs/spart-*.log`.
 */
final class WcLoggerAdapter implements SpartLoggerInterface {

	private const LOG_SOURCE = 'spart';

	/**
	 * Wrapped WC logger.
	 *
	 * Held as `object` (not WC_Logger_Interface) so unit tests can pass a
	 * mock without loading WooCommerce.
	 *
	 * @var object
	 */
	private object $wc_logger;

	/**
	 * Construct an adapter wrapping a WC_Logger_Interface object.
	 *
	 * @param object $wc_logger WC_Logger_Interface-compatible object.
	 */
	public function __construct( object $wc_logger ) {
		$this->wc_logger = $wc_logger;
	}

	/**
	 * Log at info level.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->call( 'info', $message, $context );
	}

	/**
	 * Log at warning level.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->call( 'warning', $message, $context );
	}

	/**
	 * Log at error level.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->call( 'error', $message, $context );
	}

	/**
	 * Log at debug level.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->call( 'debug', $message, $context );
	}

	/**
	 * Forward to the wrapped WC logger if it supports the requested level.
	 *
	 * @param string               $level   Log level method name on the WC logger.
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	private function call( string $level, string $message, array $context ): void {
		if ( method_exists( $this->wc_logger, $level ) ) {
			$this->wc_logger->{$level}( $message, array_merge( array( 'source' => self::LOG_SOURCE ), $context ) );
		}
	}
}
