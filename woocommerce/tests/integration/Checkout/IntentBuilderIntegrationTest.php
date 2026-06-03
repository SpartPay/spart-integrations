<?php
/**
 * Integration coverage for the merchant-configurable checkout window
 * (default_order_duration_minutes setting → IntentRequestBuilder →
 * recorded SDK request body).
 *
 * @package Spart\WooCommerce\Tests\Integration\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Checkout;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Settings\Schema;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class IntentBuilderIntegrationTest extends WC_Spart_IntegrationTestCase {

	public function test_checkout_uses_default_order_duration_minutes_from_settings(): void {
		$this->set_stub_scenario( 'happy' );

		// Snapshot the booted plugin's entry-file path BEFORE reset_for_tests
		// nulls it; we need it to re-boot below.
		$plugin_file = Plugin::plugin_file();

		// Merge the new key into the option the base setUp wrote.
		$existing = (array) get_option( 'woocommerce_spart_settings', array() );
		update_option(
			'woocommerce_spart_settings',
			array_merge( $existing, array( 'default_order_duration_minutes' => 45 ) )
		);

		// The CheckoutSession singleton is built lazily and caches the
		// IntentRequestBuilder with whatever default_order_duration_minutes
		// was at first call. Reset the singletons and reboot so the next
		// Plugin::checkout_session() reads the option we just wrote.
		Plugin::reset_for_tests();
		Plugin::boot( $plugin_file );

		$order   = $this->make_order( '129.99' );
		$gateway = new WC_Gateway_Spart();
		$result  = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'], 'process_payment must succeed under the happy stub scenario.' );

		$recorded = $this->stub_recorded_requests();
		$this->assertNotEmpty( $recorded, 'Gateway must have called the stub for /api/intents.' );
		$intent_request = null;
		foreach ( $recorded as $entry ) {
			if ( ( $entry['path'] ?? '' ) === '/api/intents' ) {
				$intent_request = $entry;
				break;
			}
		}
		$this->assertNotNull( $intent_request, 'Expected one recorded POST to /api/intents.' );

		// 45 minutes → 45 × 60 s × 10_000_000 ticks/s = 27_000_000_000.
		// PHP int on 64-bit; json_decode without JSON_BIGINT_AS_STRING returns int natively.
		$expected_ticks = 45 * 60 * 10_000_000;
		$this->assertSame(
			$expected_ticks,
			$intent_request['body']['options']['maxDurationTicks'] ?? null,
			'Merchant-configured default_order_duration_minutes (45) must surface as the wire-format maxDurationTicks.'
		);
	}

	public function test_checkout_falls_back_to_seven_day_default_when_setting_is_absent(): void {
		$this->set_stub_scenario( 'happy' );

		$plugin_file = Plugin::plugin_file();

		// Force the option WITHOUT default_order_duration_minutes set; the
		// production wiring's Schema::DEFAULT_ORDER_DURATION_MINUTES fallback
		// (currently 10080 = 7 days) should kick in.
		$existing = (array) get_option( 'woocommerce_spart_settings', array() );
		unset( $existing['default_order_duration_minutes'] );
		update_option( 'woocommerce_spart_settings', $existing );

		Plugin::reset_for_tests();
		Plugin::boot( $plugin_file );

		$order   = $this->make_order( '50.00' );
		$gateway = new WC_Gateway_Spart();
		$result  = $gateway->process_payment( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );

		$recorded       = $this->stub_recorded_requests();
		$intent_request = null;
		foreach ( $recorded as $entry ) {
			if ( ( $entry['path'] ?? '' ) === '/api/intents' ) {
				$intent_request = $entry;
				break;
			}
		}
		$this->assertNotNull( $intent_request );

		// Default minutes × 60 s × 10_000_000 ticks/s. Sourced from Schema so
		// the test tracks the canonical default without manual edits if it changes.
		$expected_ticks = Schema::DEFAULT_ORDER_DURATION_MINUTES * 60 * 10_000_000;
		$this->assertSame(
			$expected_ticks,
			$intent_request['body']['options']['maxDurationTicks'] ?? null,
			'Missing setting must fall back to Schema::DEFAULT_ORDER_DURATION_MINUTES per Plugin::checkout_session().'
		);
	}
}
