<?php
/**
 * Integration test for cart-page messaging.
 *
 * @package Spart\WooCommerce\Tests\Integration\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Messaging;

use Spart\WooCommerce\Constants;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

/**
 * Drives the woocommerce_before_cart_totals hook directly and inspects the
 * buffered output, asserting the cart toggle gates rendering correctly.
 */
final class CartMessagingDisplayTest extends WC_Spart_IntegrationTestCase {

	public function test_messaging_renders_when_toggle_is_on(): void {
		$plugin_file = Plugin::plugin_file();

		$settings                                     = get_option( Constants::OPTION_KEY, array() );
		$settings[ Constants::TOGGLE_MESSAGING_CART ] = 'yes';
		update_option( Constants::OPTION_KEY, $settings );

		// Strip any pre-existing callbacks (WC defaults, prior tests) so the hook
		// only carries OUR registration after re-booting the plugin.
		remove_all_actions( 'woocommerce_before_cart_totals' );

		Plugin::reset_for_tests();
		Plugin::boot( $plugin_file );

		ob_start();
		try {
			do_action( 'woocommerce_before_cart_totals' );
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'spart-messaging--cart', $output );
		$this->assertStringContainsString( 'Spart it: pay in installments with friends.', $output );
		$this->assertStringContainsString( 'Choose Spart at checkout to split this order.', $output );
		$this->assertStringContainsString( 'aria-live="polite"', $output );
	}

	public function test_messaging_does_not_render_when_toggle_is_off(): void {
		$plugin_file = Plugin::plugin_file();

		$settings                                     = get_option( Constants::OPTION_KEY, array() );
		$settings[ Constants::TOGGLE_MESSAGING_CART ] = 'no';
		update_option( Constants::OPTION_KEY, $settings );

		// Critical: without this, a previous test's add_action survives reset_for_tests
		// (which only clears Plugin's $booted flag, not WP's $wp_filter state).
		remove_all_actions( 'woocommerce_before_cart_totals' );

		Plugin::reset_for_tests();
		Plugin::boot( $plugin_file );

		ob_start();
		try {
			do_action( 'woocommerce_before_cart_totals' );
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertStringNotContainsString( 'spart-messaging--cart', $output );
	}
}
