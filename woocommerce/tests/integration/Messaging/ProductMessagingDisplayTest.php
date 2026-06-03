<?php
/**
 * Integration test for product-page messaging.
 *
 * @package Spart\WooCommerce\Tests\Integration\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Messaging;

use Spart\WooCommerce\Constants;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

/**
 * Drives the woocommerce_single_product_summary hook directly and inspects
 * the buffered output, asserting the toggle gates rendering correctly.
 */
final class ProductMessagingDisplayTest extends WC_Spart_IntegrationTestCase {

	public function test_messaging_renders_when_toggle_is_on(): void {
		$plugin_file = Plugin::plugin_file();

		$settings                                        = get_option( Constants::OPTION_KEY, array() );
		$settings[ Constants::TOGGLE_MESSAGING_PRODUCT ] = 'yes';
		update_option( Constants::OPTION_KEY, $settings );

		// Strip any pre-existing callbacks (WC defaults, prior tests) so the hook
		// only carries OUR registration after re-booting the plugin.
		remove_all_actions( 'woocommerce_single_product_summary' );

		// Re-boot so ProductPageMessaging::register sees the new option value.
		Plugin::reset_for_tests();
		Plugin::boot( $plugin_file );

		ob_start();
		try {
			do_action( 'woocommerce_single_product_summary' );
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'spart-messaging--product', $output );
		$this->assertStringContainsString( 'Pay in 3 interest-free installments with Spart.', $output );
		$this->assertStringContainsString( 'Split the payment with your friends!', $output );
	}

	public function test_messaging_does_not_render_when_toggle_is_off(): void {
		$plugin_file = Plugin::plugin_file();

		$settings                                        = get_option( Constants::OPTION_KEY, array() );
		$settings[ Constants::TOGGLE_MESSAGING_PRODUCT ] = 'no';
		update_option( Constants::OPTION_KEY, $settings );

		// Strip any pre-existing callbacks (WC defaults, prior tests) so a leftover
		// add_action from a previous toggle-on state can't pollute this assertion.
		remove_all_actions( 'woocommerce_single_product_summary' );

		Plugin::reset_for_tests();
		Plugin::boot( $plugin_file );

		ob_start();
		try {
			do_action( 'woocommerce_single_product_summary' );
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertStringNotContainsString( 'spart-messaging--product', $output );
	}
}
