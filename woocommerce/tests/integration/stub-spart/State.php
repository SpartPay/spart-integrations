<?php
/**
 * Persistent state for the stub-spart sidecar.
 *
 * The PHP-built-in-server forks a fresh process per request, so all state
 * (active scenario, recorded requests) lives in a JSON file under /tmp.
 * This file is invoked by router.php — it is NOT part of the plugin's
 * production autoload tree.
 */

declare(strict_types=1);

final class StubSpartState {

	private const FILE = '/tmp/stub-spart-state.json';

	/**
	 * @return array{scenario: string, recorded: list<array<string, mixed>>, replay_index: int, signing_secret: string}
	 */
	public static function load(): array {
		$default = array(
			'scenario'       => 'happy',
			'recorded'       => array(),
			'replay_index'   => 0,
			'signing_secret' => '',
		);

		if ( ! file_exists( self::FILE ) ) {
			return $default;
		}

		$raw     = (string) file_get_contents( self::FILE );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $default;
		}

		return $decoded + $default;
	}

	/**
	 * @param array{scenario: string, recorded: list<array<string, mixed>>, replay_index: int, signing_secret: string} $state
	 */
	public static function save( array $state ): void {
		file_put_contents( self::FILE, json_encode( $state, JSON_PRETTY_PRINT ) );
	}

	public static function reset(): void {
		if ( file_exists( self::FILE ) ) {
			unlink( self::FILE );
		}
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	public static function record( array $entry ): void {
		$state               = self::load();
		$state['recorded'][] = $entry;
		self::save( $state );
	}
}
