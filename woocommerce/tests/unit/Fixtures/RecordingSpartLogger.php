<?php

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Fixtures;

use Spart\WooCommerce\Logging\SpartLoggerInterface;

final class RecordingSpartLogger implements SpartLoggerInterface {

	/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
	public array $calls = array();

	public function info( string $message, array $context = array() ): void {
		$this->record( 'info', $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->record( 'warning', $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->record( 'error', $message, $context );
	}

	public function debug( string $message, array $context = array() ): void {
		$this->record( 'debug', $message, $context );
	}

	/**
	 * @return list<array{level:string,message:string,context:array<string,mixed>}>
	 */
	public function calls_for_event( string $event ): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn ( array $call ): bool => $event === ( $call['context']['event'] ?? null )
			)
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function record( string $level, string $message, array $context ): void {
		$this->calls[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}
}
