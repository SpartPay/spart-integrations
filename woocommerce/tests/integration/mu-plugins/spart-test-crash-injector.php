<?php
/**
 * Plugin Name: Spart Test Crash Injector
 * Description: TEST-ONLY mu-plugin. Reads the `spart_test_crash_count`
 *              option; if positive, throws a RuntimeException in the
 *              `spart_webhook_before_apply` hook (decrementing the
 *              counter on each invocation). Lets integration tests
 *              deterministically force a webhook handler exception
 *              without registering closures from the test process —
 *              the REST request runs in a separate PHP worker, so any
 *              hook must be wired in by a file that's loaded on every
 *              WP boot.
 *
 * Loaded by `.wp-env.json` mappings only — never shipped (the
 * `tests/` directory is excluded from the production zip via
 * tools/build-dev-zip.sh).
 *
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

add_action(
	'spart_webhook_before_apply',
	static function (): void {
		$remaining = (int) get_option( 'spart_test_crash_count', 0 );
		if ( $remaining > 0 ) {
			update_option( 'spart_test_crash_count', $remaining - 1 );
			throw new \RuntimeException( 'spart-test-induced-crash' );
		}
	},
	10,
	0
);
