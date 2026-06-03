<?php
/**
 * Logging\NullSpartLogger — no-op logger used when debug logging is disabled.
 *
 * @package Spart\WooCommerce\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Logging;

/**
 * Default logger when debug_logging=no. Discards every message.
 */
final class NullSpartLogger implements SpartLoggerInterface {

	/**
	 * No-op.
	 *
	 * @param string               $message Ignored.
	 * @param array<string, mixed> $context Ignored.
	 */
	public function info( string $message, array $context = array() ): void {}

	/**
	 * No-op.
	 *
	 * @param string               $message Ignored.
	 * @param array<string, mixed> $context Ignored.
	 */
	public function warning( string $message, array $context = array() ): void {}

	/**
	 * No-op.
	 *
	 * @param string               $message Ignored.
	 * @param array<string, mixed> $context Ignored.
	 */
	public function error( string $message, array $context = array() ): void {}

	/**
	 * No-op.
	 *
	 * @param string               $message Ignored.
	 * @param array<string, mixed> $context Ignored.
	 */
	public function debug( string $message, array $context = array() ): void {}
}
