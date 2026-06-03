<?php
/**
 * Unit tests for WooCommerceCompat.
 *
 * @package Spart\WooCommerce\Tests\Unit\Compat
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Compat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Compat\WooCommerceCompat;
use Spart\WooCommerce\Plugin;

/**
 * Tests for the WooCommerceCompat class.
 *
 * @covers \Spart\WooCommerce\Compat\WooCommerceCompat
 */
final class WooCommerceCompatTest extends TestCase {

	/**
	 * Set up Brain Monkey and boot Plugin before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Plugin::reset_for_tests();

		// Messaging registrars in Plugin::boot consult get_option; default off.
		Functions\when( 'get_option' )->justReturn( array() );

		Plugin::boot( '/tmp/spart-woocommerce/spart-woocommerce.php' );
	}

	/**
	 * Tear down Brain Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		$this->clean_up_features_util_stub();
		parent::tearDown();
	}

	/**
	 * WooCommerceCompat::declare() calls FeaturesUtil for HPOS and Blocks with the plugin file.
	 *
	 * @return void
	 */
	public function test_declare_calls_features_util_for_hpos_and_blocks(): void {
		$calls = $this->install_features_util_stub();

		WooCommerceCompat::declare();

		$this->assertSame(
			array(
				array( 'custom_order_tables', '/tmp/spart-woocommerce/spart-woocommerce.php', true ),
				array( 'cart_checkout_blocks', '/tmp/spart-woocommerce/spart-woocommerce.php', true ),
			),
			$calls->received
		);
	}

	/**
	 * WooCommerceCompat::declare() is a no-op when FeaturesUtil class is not present.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_declare_is_a_noop_when_features_util_missing(): void {
		// No stub installed => class_exists returns false => must not throw.
		WooCommerceCompat::declare();
		$this->expectNotToPerformAssertions();
	}

	/**
	 * Installs a fake FeaturesUtil class that records declare_compatibility calls.
	 *
	 * @return object The recorder; check ->received for logged calls.
	 */
	private function install_features_util_stub(): object {
		$recorder = (object) array( 'received' => array() );

		if ( ! class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			FeaturesUtilStubRegistry::$recorder = $recorder;
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval(
				'namespace Automattic\\WooCommerce\\Utilities;
				class FeaturesUtil {
					public static function declare_compatibility( $f, $file, $support ) {
						\\Spart\\WooCommerce\\Tests\\Unit\\Compat\\FeaturesUtilStubRegistry::$recorder->received[]
							= array( $f, $file, $support );
					}
				}'
			);
		} else {
			FeaturesUtilStubRegistry::$recorder = $recorder;
		}

		return $recorder;
	}

	/**
	 * Resets the stub recorder so tests do not bleed into each other.
	 *
	 * @return void
	 */
	private function clean_up_features_util_stub(): void {
		if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil', false ) ) {
			FeaturesUtilStubRegistry::$recorder = null;
		}
	}
}
