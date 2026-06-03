<?php
/**
 * Plugin deactivation hook handler.
 *
 * @package Spart\WooCommerce
 */

declare(strict_types=1);

namespace Spart\WooCommerce;

use Spart\WooCommerce\Webhooks\CleanupCron;

/**
 * Plugin deactivation hook handler.
 *
 * Mirror of `Activation`. WordPress fires `register_deactivation_hook`
 * synchronously when an admin clicks "Deactivate" on the plugins screen
 * (before unloading the plugin code), so it is safe to read CleanupCron
 * here. Deactivation is best-effort: `CleanupCron::unschedule()` calls
 * `wp_clear_scheduled_hook()`, which removes every scheduled instance
 * of our hook in a single call (handles the rare race where multiple
 * future events have been queued for the same hook).
 *
 * Re-activating after deactivation re-installs the schedule via
 * `Activation::activate()`, so this is a clean teardown.
 */
final class Deactivation {

	/**
	 * Run on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		CleanupCron::unschedule();
	}
}
