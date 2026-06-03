<?php
/**
 * Webhooks\CleanupCron — daily housekeeping of the dedupe table.
 *
 * @package Spart\WooCommerce\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Webhooks;

use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * Schedules and runs the daily cleanup of old webhook delivery rows.
 *
 * Static surface (schedule / unschedule) wires into the activation and
 * deactivation hooks. Instance surface (run) is invoked by WP-Cron via
 * the action handler registered in Plugin::on_plugins_loaded().
 *
 * Retention is fixed at 30 days; a webhook delivery record older than
 * that is no longer useful for diagnostics or replay because the Spart
 * server itself drops the corresponding event from its retry window.
 */
final class CleanupCron {

	/**
	 * WP-Cron hook name for the daily cleanup task.
	 */
	public const HOOK = 'spart_webhook_cleanup';

	/**
	 * Retention window in days; rows older than this are deleted.
	 */
	public const RETENTION_DAYS = 30;

	/**
	 * Wire CleanupCron with its repository and logger.
	 *
	 * @param DeliveryRepository   $repository Dedupe-table accessor.
	 * @param SpartLoggerInterface $logger     Logger sink for cleanup runs.
	 */
	public function __construct(
		private readonly DeliveryRepository $repository,
		private readonly SpartLoggerInterface $logger,
	) {
	}

	/**
	 * Register the daily cleanup hook with WP-Cron.
	 *
	 * Idempotent: if a future run is already scheduled, no second event
	 * is added. Safe to call from Activation::activate() on every
	 * activation.
	 */
	public static function schedule(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'daily', self::HOOK );
	}

	/**
	 * Remove every scheduled instance of the cleanup hook.
	 *
	 * Uses wp_clear_scheduled_hook() — the canonical WP idiom for
	 * "unschedule every future instance of this hook", equivalent to
	 * looping wp_unschedule_event() but atomic. Called from
	 * Deactivation::deactivate().
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run a single cleanup pass.
	 *
	 * Invoked by the WP-Cron event registered in
	 * Plugin::on_plugins_loaded(). Logs a single info entry per run
	 * carrying the deleted row count for auditability.
	 */
	public function run(): void {
		$deleted = $this->repository->cleanup_older_than( self::RETENTION_DAYS );

		$this->logger->info(
			'webhook.cleanup.run',
			array(
				'retention_days' => self::RETENTION_DAYS,
				'rows_deleted'   => $deleted,
			)
		);
	}
}
