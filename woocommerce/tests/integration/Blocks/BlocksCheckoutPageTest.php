<?php
/**
 * Smoke test: PR4's Blocks-checkout wiring works end-to-end inside a real
 * WP + WC bootstrap.
 *
 * This test deliberately avoids HTTP. WC's Cart/Checkout blocks redirect on
 * empty cart, WP canonical-redirects across host/port mismatches, and
 * wp-env's tests-cli container cannot reach the public WP host directly —
 * stacking those concerns behind one wp_remote_get() produced more brittle
 * test infrastructure than verification value (see commit history of this
 * file). Instead we drive the same hooks the live request would, then
 * inspect their effects through public APIs.
 *
 * Coverage:
 * - Plugin → blocks_support() listens on the WC Blocks
 *   payment-method registration hook AND, when the hook fires, registers
 *   our SpartBlocksSupport against the registry.
 * - SpartBlocksSupport's get_payment_method_data() returns the
 *   merchant-configured title/description verbatim plus a logoUrl pointing
 *   at the shipped SVG asset (proves the PaymentMethodDataBuilder is wired
 *   to the live Settings\Field reader).
 * - SpartBlocksSupport's script handle is registered with WP and its src
 *   resolves to the shipped assets/js/blocks-checkout.js with the bumped
 *   plugin version in the ?ver= query string.
 * - The PR4 thank-you renderer fires on the woocommerce_thankyou_spart
 *   action with a real WC order in a 'pending' status.
 *
 * @package Spart\WooCommerce\Tests\Integration\Blocks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Blocks;

use Spart\WooCommerce\Gateway\Blocks\SpartBlocksSupport;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class BlocksCheckoutPageTest extends WC_Spart_IntegrationTestCase {

	private const TEST_TITLE       = 'Pay with Spart (block test)';
	private const TEST_DESCRIPTION = 'Split it across friends.';

	protected function setUp(): void {
		parent::setUp();

		update_option(
			'woocommerce_spart_settings',
			array(
				'enabled'        => 'yes',
				'title'          => self::TEST_TITLE,
				'description'    => self::TEST_DESCRIPTION,
				'api_key'        => 'sk_test_blocks',
				'webhook_secret' => 'whsec_test',
				'environment'    => 'live',
				'debug_logging'  => 'no',
			)
		);
	}

	public function test_blocks_payment_method_registers_with_spart_data_via_real_wc_hook(): void {
		// Other listeners on woocommerce_blocks_payment_method_type_registration
		// (notably WC Blocks' own Api::register_payment_method_integrations)
		// have a typed PaymentMethodRegistry parameter, so we use the real
		// registry from WC Blocks' container rather than fabricating one.
		$registry = \Automattic\WooCommerce\Blocks\Package::container()->get(
			\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class
		);

		do_action( 'woocommerce_blocks_payment_method_type_registration', $registry );

		$all_methods = $registry->get_all_registered();
		$this->assertArrayHasKey(
			'spart',
			$all_methods,
			'Plugin must register SpartBlocksSupport on woocommerce_blocks_payment_method_type_registration.'
		);

		$method = $all_methods['spart'];
		$this->assertInstanceOf( SpartBlocksSupport::class, $method );
		$this->assertSame( 'spart', $method->get_name() );

		$method->initialize();

		$data = $method->get_payment_method_data();

		$this->assertIsArray( $data );
		$this->assertSame(
			self::TEST_TITLE,
			$data['title'] ?? null,
			'Block payload title must reflect the merchant-configured title verbatim.'
		);
		$this->assertSame(
			self::TEST_DESCRIPTION,
			$data['description'] ?? null,
			'Block payload description must reflect the merchant-configured description verbatim.'
		);
		$this->assertArrayHasKey( 'logoUrl', $data, 'Block payload must include a logoUrl.' );
		$this->assertStringContainsString(
			'images/spart-logo.svg',
			(string) $data['logoUrl'],
			'logoUrl must point at the shipped Spart logo asset.'
		);
	}

	public function test_blocks_script_handle_is_registered_and_points_at_shipped_asset(): void {
		$registry = \Automattic\WooCommerce\Blocks\Package::container()->get(
			\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class
		);

		do_action( 'woocommerce_blocks_payment_method_type_registration', $registry );

		$all_methods = $registry->get_all_registered();
		$method      = $all_methods['spart'] ?? null;
		$this->assertNotNull( $method, 'SpartBlocksSupport must be registered before script-handle assertions.' );
		$method->initialize();

		// Calling this triggers wp_register_script() inside
		// SpartBlocksSupport, which is what we want to assert against.
		$handles = $method->get_payment_method_script_handles();

		$this->assertNotEmpty( $handles, 'SpartBlocksSupport must declare at least one script handle.' );
		$handle = $handles[0];

		global $wp_scripts;
		$this->assertNotEmpty( $wp_scripts, 'WP_Scripts global must be initialised by the test bootstrap.' );
		$this->assertTrue(
			wp_script_is( $handle, 'registered' ),
			"Script handle '{$handle}' must be registered with WP after SpartBlocksSupport initialises."
		);

		$registered = $wp_scripts->registered[ $handle ] ?? null;
		$this->assertNotNull( $registered, "Registered script object for '{$handle}' must be retrievable." );

		$src = (string) ( $registered->src ?? '' );
		$this->assertStringContainsString(
			'assets/js/blocks-checkout.js',
			$src,
			'Registered script src must point at the shipped assets/js/blocks-checkout.js.'
		);

		$ver = (string) ( $registered->ver ?? '' );
		$this->assertSame(
			'0.5.0',
			$ver,
			"Registered script ver must equal the bumped plugin version 0.5.0 (got '{$ver}')."
		);
	}

	public function test_woocommerce_thankyou_spart_action_renders_pending_message(): void {
		$order = wc_create_order();
		$order->set_status( 'pending' );
		$order->save();

		ob_start();
		do_action( 'woocommerce_thankyou_spart', $order->get_id() );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'spart-thankyou-pending',
			$output,
			'ThankYouRenderer must emit its CSS class for pending orders.'
		);
		$this->assertStringContainsString(
			'being processed',
			$output,
			'ThankYouRenderer must include the pending-payment placeholder copy for pending orders.'
		);
	}
}
