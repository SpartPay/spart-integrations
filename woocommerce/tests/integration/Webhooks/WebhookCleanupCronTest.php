<?php
/**
 * Integration test for the daily dedupe-table housekeeping cron.
 *
 * CleanupCron::run() — wired to the `spart_webhook_cleanup` action by
 * Plugin::on_plugins_loaded — invokes
 * DeliveryRepository::cleanup_older_than(CleanupCron::RETENTION_DAYS),
 * which deletes every row whose `received_at` is older than 30 days.
 *
 * This test pins the contract end-to-end:
 *   - Direct $wpdb inserts of one stale row (received_at = 31 days ago)
 *     and one fresh row (received_at = 1 day ago).
 *   - Trigger the cron via do_action(CleanupCron::HOOK).
 *   - Stale row gone, fresh row survives.
 *
 * Implements PR3 task t7-cleanup-test (cleanup-cron coverage referenced
 * at lines 481-487 of
 * the webhook receiver design).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Persistence\WebhookDeliveriesSchema;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use Spart\WooCommerce\Webhooks\CleanupCron;

final class WebhookCleanupCronTest extends WC_Spart_IntegrationTestCase {

	private string $stale_delivery_id;
	private string $fresh_delivery_id;

	protected function setUp(): void {
		parent::setUp();
		$random                  = bin2hex( random_bytes( 8 ) );
		$this->stale_delivery_id = 'spart_delivery_stale_' . $random;
		$this->fresh_delivery_id = 'spart_delivery_fresh_' . $random;
	}

	protected function tearDown(): void {
		global $wpdb;
		$table = WebhookDeliveriesSchema::table_name( $wpdb->prefix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only cleanup.
		$wpdb->delete( $table, array( 'delivery_id' => $this->stale_delivery_id ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only cleanup.
		$wpdb->delete( $table, array( 'delivery_id' => $this->fresh_delivery_id ), array( '%s' ) );
		parent::tearDown();
	}

	public function test_cron_deletes_stale_rows_and_keeps_recent_rows(): void {
		global $wpdb;
		$table = WebhookDeliveriesSchema::table_name( $wpdb->prefix );

		$stale_received_at = gmdate( 'Y-m-d H:i:s', time() - ( ( CleanupCron::RETENTION_DAYS + 1 ) * DAY_IN_SECONDS ) );
		$fresh_received_at = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture insert.
		$inserted_stale = $wpdb->insert(
			$table,
			array(
				'delivery_id'   => $this->stale_delivery_id,
				'event_type'    => 'order.completed',
				'state'         => 'applied',
				'attempt_count' => 1,
				'received_at'   => $stale_received_at,
				'applied_at'    => $stale_received_at,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$this->assertSame( 1, $inserted_stale, 'Sanity: stale fixture row must insert.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture insert.
		$inserted_fresh = $wpdb->insert(
			$table,
			array(
				'delivery_id'   => $this->fresh_delivery_id,
				'event_type'    => 'order.completed',
				'state'         => 'applied',
				'attempt_count' => 1,
				'received_at'   => $fresh_received_at,
				'applied_at'    => $fresh_received_at,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$this->assertSame( 1, $inserted_fresh, 'Sanity: fresh fixture row must insert.' );

		$this->assertNotNull(
			$this->find_dedupe_row( $this->stale_delivery_id ),
			'Sanity: stale row must be present before the cron runs.'
		);
		$this->assertNotNull(
			$this->find_dedupe_row( $this->fresh_delivery_id ),
			'Sanity: fresh row must be present before the cron runs.'
		);

		do_action( CleanupCron::HOOK );

		$this->assertNull(
			$this->find_dedupe_row( $this->stale_delivery_id ),
			'Stale row (received_at older than RETENTION_DAYS) must be deleted by the cron.'
		);
		$this->assertNotNull(
			$this->find_dedupe_row( $this->fresh_delivery_id ),
			'Fresh row (received_at within retention window) must survive the cron.'
		);
	}
}
