<?php
/**
 * Unit tests for WebhookDeliveriesSchema.
 *
 * @package Spart\WooCommerce\Tests\Unit\Persistence
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Persistence\WebhookDeliveriesSchema;

/**
 * Tests for WebhookDeliveriesSchema pure-function class.
 */
final class WebhookDeliveriesSchemaTest extends TestCase {

	/**
	 * Test that table_name() returns prefixed table name.
	 */
	public function test_table_name_is_prefixed(): void {
		$this->assertSame( 'wp_spart_webhook_deliveries', WebhookDeliveriesSchema::table_name( 'wp_' ) );
		$this->assertSame( 'foo_spart_webhook_deliveries', WebhookDeliveriesSchema::table_name( 'foo_' ) );
	}

	/**
	 * Test that create_table_sql() contains all required columns and constraints.
	 */
	public function test_create_table_sql_contains_required_columns(): void {
		$sql = WebhookDeliveriesSchema::create_table_sql( 'wp_', 'utf8mb4_unicode_520_ci' );

		$this->assertStringContainsString( 'CREATE TABLE wp_spart_webhook_deliveries', $sql );
		foreach ( array(
			'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
			'delivery_id VARCHAR(64) NOT NULL',
			'event_type VARCHAR(64) NOT NULL',
			'wc_order_id BIGINT UNSIGNED NULL',
			"state ENUM('received', 'applied', 'skipped', 'errored') NOT NULL",
			'attempt_count INT UNSIGNED NOT NULL DEFAULT 1',
			'received_at DATETIME NOT NULL',
			'applied_at DATETIME NULL',
			'error_message TEXT NULL',
			'PRIMARY KEY (id)',
			'UNIQUE KEY uk_delivery_id (delivery_id)',
			'KEY idx_received_at (received_at)',
			'KEY idx_wc_order_id (wc_order_id)',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $sql, "Missing: $needle" );
		}
	}

	/**
	 * Test that create_table_sql() uses the supplied charset and collation.
	 */
	public function test_create_table_sql_uses_supplied_charset_collate(): void {
		$sql = WebhookDeliveriesSchema::create_table_sql( 'wp_', 'utf8mb4_unicode_520_ci' );
		$this->assertStringEndsWith( 'utf8mb4_unicode_520_ci;', \trim( $sql ) );
	}
}
