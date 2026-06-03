<?php
/**
 * Schema definition for webhook deliveries.
 *
 * @package Spart\WooCommerce\Persistence
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Persistence;

/**
 * Schema definition for the `{prefix}spart_webhook_deliveries` dedupe table.
 *
 * Pure-function class — does not touch `$wpdb`. The `Activation` class
 * passes the SQL into `dbDelta()`. Splitting it like this keeps the SQL
 * unit-testable without any WordPress runtime.
 */
final class WebhookDeliveriesSchema {

	public const TABLE_SUFFIX = 'spart_webhook_deliveries';

	/**
	 * Get the prefixed table name for webhook deliveries.
	 *
	 * @param string $wpdb_prefix The WordPress table prefix.
	 * @return string The full table name.
	 */
	public static function table_name( string $wpdb_prefix ): string {
		return $wpdb_prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Generate the CREATE TABLE SQL for the webhook deliveries table.
	 *
	 * @param string $wpdb_prefix The WordPress table prefix.
	 * @param string $charset_collate The charset and collation specification.
	 * @return string The CREATE TABLE SQL statement.
	 */
	public static function create_table_sql( string $wpdb_prefix, string $charset_collate ): string {
		$table = self::table_name( $wpdb_prefix );
		return <<<SQL
CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  delivery_id VARCHAR(64) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  wc_order_id BIGINT UNSIGNED NULL,
  state ENUM('received', 'applied', 'skipped', 'errored') NOT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
  received_at DATETIME NOT NULL,
  applied_at DATETIME NULL,
  error_message TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_delivery_id (delivery_id),
  KEY idx_received_at (received_at),
  KEY idx_wc_order_id (wc_order_id)
) {$charset_collate};
SQL;
	}
}
