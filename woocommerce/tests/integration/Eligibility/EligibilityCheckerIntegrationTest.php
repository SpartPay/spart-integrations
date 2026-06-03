<?php
/**
 * Integration tests for Eligibility\EligibilityChecker.
 *
 * Exercises the real WP transients API (set_transient / get_transient /
 * delete_transient) and the real WC filter chain
 * (woocommerce_settings_api_sanitized_fields_spart) that the unit tests
 * stub via Brain\Monkey. The SDK layer is exercised end-to-end through a
 * real SpartClient whose HTTP transport is a deterministic in-memory
 * stub, so we don't depend on the stub-spart sidecar for these tests.
 *
 * What this covers that the unit tests don't:
 *  - Real WP options-table transient round-trip: the second is_eligible()
 *    call must read its verdict back from wp_options (set by the first
 *    call), not from any in-process cache.
 *  - Real {@see EligibilityChecker::purge_cache()} clearing the same
 *    real transients.
 *  - Real WC filter chain: constructing {@see WC_Gateway_Spart} registers
 *    the priority-20 purge callback, and merchant settings save (via
 *    process_admin_options) drops the cached verdict so the next
 *    is_eligible() refetches against the new credentials.
 *
 * @package Spart\WooCommerce\Tests\Integration\Eligibility
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Eligibility;

use Spart\WooCommerce\Eligibility\EligibilityChecker;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Tests\Integration\Eligibility\Fixtures\CountingSpartClientFactory;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

/**
 * @covers \Spart\WooCommerce\Eligibility\EligibilityChecker
 * @covers \Spart\WooCommerce\Gateway\WC_Gateway_Spart::purge_eligibility_cache_on_save
 */
final class EligibilityCheckerIntegrationTest extends WC_Spart_IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Clear all three transients between tests so previous-test verdicts
		// can't leak through wp_options.
		\delete_transient( EligibilityChecker::POSITIVE_TRANSIENT );
		\delete_transient( EligibilityChecker::NEGATIVE_TRANSIENT );
		\delete_transient( EligibilityChecker::ERROR_TRANSIENT );

		// Reset the Plugin singleton so each test can install its own
		// deterministic EligibilityChecker.
		Plugin::set_eligibility_checker_for_tests( null );

		$_POST = array();
	}

	protected function tearDown(): void {
		\delete_transient( EligibilityChecker::POSITIVE_TRANSIENT );
		\delete_transient( EligibilityChecker::NEGATIVE_TRANSIENT );
		\delete_transient( EligibilityChecker::ERROR_TRANSIENT );
		Plugin::set_eligibility_checker_for_tests( null );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * First is_eligible() call invokes the SDK and writes the positive
	 * transient via real set_transient(). The second call must read the
	 * cached verdict from real get_transient() (wp_options) and skip the
	 * factory entirely — proving the WP transient round-trip works in the
	 * real environment, not just under Brain\Monkey stubs.
	 */
	public function test_positive_verdict_persists_through_real_wp_transients(): void {
		$factory = new CountingSpartClientFactory(
			'{"isSuccessful":true,"value":{"eligible":true,"reasons":[]},"error":null}'
		);

		$checker = new EligibilityChecker( $factory );

		$this->assertTrue( $checker->is_eligible(), 'First call should resolve eligible from API.' );
		$this->assertSame( 1, $factory->call_count, 'Factory should be invoked exactly once.' );
		$this->assertSame( '1', \get_transient( EligibilityChecker::POSITIVE_TRANSIENT ) );

		$this->assertTrue( $checker->is_eligible(), 'Second call should resolve eligible from cache.' );
		$this->assertSame( 1, $factory->call_count, 'Second call must NOT hit the factory.' );
	}

	/**
	 * Negative verdict is cached with the shorter TTL key. We don't assert
	 * the exact TTL (WP doesn't expose it), only that the right transient
	 * is set and the subsequent call hits the cache.
	 */
	public function test_negative_verdict_persists_through_real_wp_transients(): void {
		$factory = new CountingSpartClientFactory(
			'{"isSuccessful":true,"value":{"eligible":false,"reasons":[{"code":"merchant.not_connected_to_stripe","message":"Connect your Stripe account to enable Spart."}]},"error":null}'
		);

		$checker = new EligibilityChecker( $factory );

		$this->assertFalse( $checker->is_eligible() );
		$this->assertSame( '1', \get_transient( EligibilityChecker::NEGATIVE_TRANSIENT ) );
		$this->assertFalse( \get_transient( EligibilityChecker::POSITIVE_TRANSIENT ) );

		$this->assertFalse( $checker->is_eligible() );
		$this->assertSame( 1, $factory->call_count, 'Second call must read from the negative transient.' );
	}

	/**
	 * purge_cache() must clear all three transient keys via real
	 * delete_transient() so the next is_eligible() refetches against the
	 * current credentials.
	 */
	public function test_purge_cache_clears_all_three_real_transients(): void {
		\set_transient( EligibilityChecker::POSITIVE_TRANSIENT, '1', EligibilityChecker::POSITIVE_TTL_SECONDS );
		\set_transient( EligibilityChecker::NEGATIVE_TRANSIENT, '1', EligibilityChecker::NEGATIVE_TTL_SECONDS );
		\set_transient( EligibilityChecker::ERROR_TRANSIENT, '1', EligibilityChecker::ERROR_TTL_SECONDS );

		EligibilityChecker::purge_cache();

		$this->assertFalse( \get_transient( EligibilityChecker::POSITIVE_TRANSIENT ) );
		$this->assertFalse( \get_transient( EligibilityChecker::NEGATIVE_TRANSIENT ) );
		$this->assertFalse( \get_transient( EligibilityChecker::ERROR_TRANSIENT ) );
	}

	/**
	 * Merchant edits and saves the gateway settings via the WC admin form.
	 * The priority-20 purge filter registered in WC_Gateway_Spart's
	 * constructor must clear the cached verdict so the next is_eligible()
	 * call refetches against the new credentials.
	 *
	 * This is the only end-to-end proof that the constructor's
	 * add_filter wiring works against the real WC settings save chain.
	 */
	public function test_settings_save_purges_cache_through_real_wc_filter_chain(): void {
		\set_transient( EligibilityChecker::POSITIVE_TRANSIENT, '1', EligibilityChecker::POSITIVE_TTL_SECONDS );
		\set_transient( EligibilityChecker::NEGATIVE_TRANSIENT, '1', EligibilityChecker::NEGATIVE_TTL_SECONDS );
		\set_transient( EligibilityChecker::ERROR_TRANSIENT, '1', EligibilityChecker::ERROR_TTL_SECONDS );

		// Real merchant POST: edit description, leaving API key untouched
		// (the masked sentinel + WC_Gateway_Spart's mask-handling keep the
		// stored key intact — see GatewaySettingsSaveTest::test_save_keeps_
		// existing_api_key_when_mask_unchanged).
		$_POST['woocommerce_spart_title']       = 'Pay with Spart';
		$_POST['woocommerce_spart_description'] = 'Updated description copy';

		// Constructing the gateway registers the priority-20 purge filter
		// on woocommerce_settings_api_sanitized_fields_spart. The real
		// process_admin_options() fires that filter once it has sanitised
		// the form fields, which invokes purge_eligibility_cache_on_save()
		// and clears all three transients.
		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$this->assertFalse(
			\get_transient( EligibilityChecker::POSITIVE_TRANSIENT ),
			'Positive verdict must be purged on settings save.'
		);
		$this->assertFalse(
			\get_transient( EligibilityChecker::NEGATIVE_TRANSIENT ),
			'Negative verdict must be purged on settings save.'
		);
		$this->assertFalse(
			\get_transient( EligibilityChecker::ERROR_TRANSIENT ),
			'Error verdict must be purged on settings save.'
		);
	}
}
