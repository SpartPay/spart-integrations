<?php
/**
 * Unit tests for WC_Gateway_Spart::is_available().
 *
 * @package Spart\WooCommerce\Tests\Unit\Gateway
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Gateway;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Eligibility\EligibilityChecker;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Settings\Schema;

/**
 * @covers \Spart\WooCommerce\Gateway\WC_Gateway_Spart::is_available
 */
final class WC_Gateway_Spart_IsAvailableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Plugin::reset_for_tests();
		Schema::reset_for_tests();

		// Constructor-time stubs (same set as WC_Gateway_SpartTest::setUp).
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'http://localhost' . (string) $path );
		Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'http://localhost/wp-json/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_unslash' )->alias( static fn ( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
	}

	protected function tearDown(): void {
		Plugin::reset_for_tests();
		Schema::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_false_when_gateway_disabled(): void {
		// EligibilityChecker MUST NOT be consulted when the gateway is disabled —
		// no point spending an API budget on a switched-off integration.
		Plugin::set_eligibility_checker_for_tests( $this->makeFailingChecker( 'eligibility checker MUST NOT be consulted when disabled' ) );

		$gateway = new WC_Gateway_Spart();
		// Simulate WC having loaded settings: enabled=no even though api_key is set.
		$this->setGatewaySettings( $gateway, 'no', 'sk_live_present' );

		$this->assertFalse( $gateway->is_available() );
	}

	public function test_returns_false_when_api_key_is_empty(): void {
		// Same as the disabled case: with no API key the eligibility probe
		// can't succeed, so the checker MUST be short-circuited.
		Plugin::set_eligibility_checker_for_tests( $this->makeFailingChecker( 'eligibility checker MUST NOT be consulted when api_key blank' ) );

		$gateway = new WC_Gateway_Spart();
		$this->setGatewaySettings( $gateway, 'yes', '' );

		$this->assertFalse( $gateway->is_available() );
	}

	public function test_returns_true_when_enabled_with_api_key_and_eligible_verdict(): void {
		Plugin::set_eligibility_checker_for_tests( $this->makeFixedVerdictChecker( true ) );

		$gateway = new WC_Gateway_Spart();
		$this->setGatewaySettings( $gateway, 'yes', 'sk_live_present' );

		$this->assertTrue( $gateway->is_available() );
	}

	public function test_returns_false_when_enabled_with_api_key_but_ineligible_verdict(): void {
		Plugin::set_eligibility_checker_for_tests( $this->makeFixedVerdictChecker( false ) );

		$gateway = new WC_Gateway_Spart();
		$this->setGatewaySettings( $gateway, 'yes', 'sk_live_present' );

		$this->assertFalse( $gateway->is_available() );
	}

	/**
	 * Mutate the gateway to look like WC loaded its settings.
	 *
	 * The unit-test stand-in for WC_Payment_Gateway has an empty
	 * init_settings(), so $this->settings stays empty and $this->enabled
	 * inherits the schema's 'no' default. Real WC populates these from the
	 * gateway's option row; this helper mirrors that for tests.
	 */
	private function setGatewaySettings( WC_Gateway_Spart $gateway, string $enabled, string $api_key ): void {
		$gateway->enabled  = $enabled;
		$gateway->settings = array(
			'enabled' => $enabled,
			'api_key' => $api_key,
		);
	}

	/**
	 * Construct an EligibilityChecker subclass that returns a fixed verdict
	 * without consulting WP transients or the SDK. Used to inject deterministic
	 * eligibility outcomes through Plugin::set_eligibility_checker_for_tests().
	 */
	private function makeFixedVerdictChecker( bool $verdict ): EligibilityChecker {
		return new class( $verdict ) extends EligibilityChecker {
			public function __construct( private readonly bool $verdict ) {
				// Skip parent constructor — we never call the factory.
			}
			public function is_eligible(): bool {
				return $this->verdict;
			}
		};
	}

	/**
	 * Construct an EligibilityChecker subclass whose is_eligible() will fail the
	 * test if called — used to assert short-circuit gates (disabled, no key).
	 */
	private function makeFailingChecker( string $message ): EligibilityChecker {
		$test = $this;
		return new class( $test, $message ) extends EligibilityChecker {
			public function __construct(
				private readonly TestCase $test,
				private readonly string $message,
			) {}
			public function is_eligible(): bool {
				$this->test->fail( $this->message );
			}
		};
	}
}
