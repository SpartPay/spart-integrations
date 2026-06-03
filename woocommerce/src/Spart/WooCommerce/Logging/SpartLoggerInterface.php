<?php
/**
 * Logging\SpartLoggerInterface — tiny PSR-3-shaped interface for plugin logging.
 *
 * @package Spart\WooCommerce\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Logging;

/**
 * Minimal logger surface used by the plugin.
 *
 * Modelled on PSR-3 but intentionally narrow — only the four levels we
 * actually emit. Implementations decide whether to forward to wc_get_logger()
 * (when debug logging is enabled) or no-op.
 */
interface SpartLoggerInterface {

	/**
	 * Log at info level.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Log at warning level.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * Log at error level.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function error( string $message, array $context = array() ): void;

	/**
	 * Log at debug level.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Structured context.
	 */
	public function debug( string $message, array $context = array() ): void;
}
