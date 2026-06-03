<?php
/**
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Persistence\WebhookDeliveriesSchema;

final class SmokeTest extends TestCase {

	public function test_plugin_is_active(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertTrue( is_plugin_active( 'spart-woocommerce/spart-woocommerce.php' ) );
	}

	public function test_dedupe_table_exists(): void {
		global $wpdb;
		$table = WebhookDeliveriesSchema::table_name( $wpdb->prefix );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found );
	}

	public function test_gateway_is_registered_with_wc(): void {
		$available = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'spart', $available );
		$this->assertSame( 'Spart', $available['spart']->method_title );
	}

	public function test_woocommerce_compatibility_is_declared(): void {
		$features = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$this->assertContains(
			'spart-woocommerce/spart-woocommerce.php',
			$features['compatible'] ?? array()
		);
	}
}
