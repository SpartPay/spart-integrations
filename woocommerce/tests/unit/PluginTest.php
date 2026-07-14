<?php
/**
 * Unit tests for Plugin bootstrap.
 *
 * @package Spart\WooCommerce\Tests\Unit
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Eligibility\EligibilityChecker;
use Spart\WooCommerce\Webhooks\CleanupCron;
use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Gateway\Blocks\SpartBlocksSupport;
use Spart\WooCommerce\Webhooks\WebhookReceiver;

/**
 * Tests for the Plugin bootstrap class.
 *
 * @covers \Spart\WooCommerce\Plugin
 */
final class PluginTest extends TestCase {

	/**
	 * Set up Brain Monkey and reset Plugin state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Plugin::reset_for_tests();

		// Messaging registrars (wired into Plugin::boot) call get_option to
		// decide whether to attach hooks; default to "messaging disabled" in
		// all PluginTest cases unless a test explicitly overrides this.
		Functions\when( 'get_option' )->justReturn( array() );

		// Plugin::logger() now unconditionally calls wc_get_logger() — wrap
		// it as a stdClass so WcLoggerAdapter has something to hold. The
		// adapter's call() guards with method_exists(), so this no-op stub
		// is sufficient for tests that don't assert on logging behaviour.
		Functions\when( 'wc_get_logger' )->justReturn( new \stdClass() );
	}

	/**
	 * Tear down Brain Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Plugin::boot() registers expected hooks and stores the plugin file path.
	 *
	 * @return void
	 */
	public function test_boot_registers_compatibility_hook(): void {
		Actions\expectAdded( 'before_woocommerce_init' )->once();
		Actions\expectAdded( 'plugins_loaded' )->once();

		Plugin::boot( '/tmp/spart-woocommerce/spart-woocommerce.php' );

		$this->assertSame( '/tmp/spart-woocommerce/spart-woocommerce.php', Plugin::plugin_file() );
	}

	/**
	 * Plugin::boot() is idempotent — repeated calls do not register duplicate hooks.
	 *
	 * @return void
	 */
	public function test_boot_is_idempotent(): void {
		Actions\expectAdded( 'before_woocommerce_init' )->once();
		Actions\expectAdded( 'plugins_loaded' )->once();

		Plugin::boot( '/tmp/spart-woocommerce/spart-woocommerce.php' );
		Plugin::boot( '/tmp/spart-woocommerce/spart-woocommerce.php' );

		$this->assertSame( '/tmp/spart-woocommerce/spart-woocommerce.php', Plugin::plugin_file() );
	}

	/**
	 * Plugin::webhook_receiver() returns a WebhookReceiver instance memoized for the request.
	 *
	 * @return void
	 */
	public function test_webhook_receiver_returns_memoized_singleton(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'woocommerce_spart_settings' === $name ) {
					return array( 'webhook_secret' => 'test_secret' );
				}
				if ( 'spart_site_token' === $name ) {
					return 'abc12345';
				}
				return $default;
			}
		);

		$first  = Plugin::webhook_receiver();
		$second = Plugin::webhook_receiver();

		$this->assertInstanceOf( WebhookReceiver::class, $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * Plugin::webhook_receiver() propagates the SDK's blank-secret guard.
	 *
	 * SignatureVerifier rejects an empty signingSecret at construction. We do
	 * NOT swallow this — t4-plugin-bootstrap is responsible for not calling
	 * webhook_receiver() until the merchant has configured webhook_secret in
	 * the gateway settings. Asserting the propagation here pins the contract.
	 *
	 * @return void
	 */
	public function test_webhook_receiver_propagates_blank_secret_error(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'woocommerce_spart_settings' === $name ) {
					return array();
				}
				return $default;
			}
		);

		$this->expectException( \InvalidArgumentException::class );

		Plugin::webhook_receiver();
	}

	public function test_checkout_and_eligibility_factories_accept_filtered_logger_wiring(): void {
		$this->assertInstanceOf(
			\Spart\WooCommerce\Checkout\CheckoutSession::class,
			Plugin::checkout_session()
		);
		$this->assertInstanceOf(
			EligibilityChecker::class,
			Plugin::eligibility_checker()
		);
	}

	/**
	 * Plugin::webhook_cleanup() returns a CleanupCron instance memoized for the request.
	 *
	 * @return void
	 */
	public function test_webhook_cleanup_returns_memoized_singleton(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'woocommerce_spart_settings' === $name ) {
					return array();
				}
				return $default;
			}
		);

		$first  = Plugin::webhook_cleanup();
		$second = Plugin::webhook_cleanup();

		$this->assertInstanceOf( CleanupCron::class, $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * Plugin::on_plugins_loaded() registers the webhook REST and cron hooks.
	 *
	 * @return void
	 */
	public function test_on_plugins_loaded_registers_rest_and_cron_actions(): void {
		Actions\expectAdded( 'rest_api_init' )->once();
		Actions\expectAdded( CleanupCron::HOOK )->once();

		Plugin::on_plugins_loaded();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The rest_api_init callback is a no-op when webhook_secret is blank.
	 *
	 * Otherwise SignatureVerifier would throw on every REST request and
	 * break the entire WP REST API for the site.
	 *
	 * @return void
	 */
	public function test_rest_api_init_callback_skips_route_registration_when_webhook_secret_blank(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'woocommerce_spart_settings' === $name ) {
					return array();
				}
				return $default;
			}
		);
		Functions\expect( 'register_rest_route' )->never();

		$captured = null;
		Actions\expectAdded( 'rest_api_init' )
			->once()
			->whenHappen(
				static function ( $callback ) use ( &$captured ): void {
					$captured = $callback;
				}
			);
		Actions\expectAdded( CleanupCron::HOOK )->once();

		Plugin::on_plugins_loaded();

		$this->assertIsCallable( $captured );
		$captured();
	}

	/**
	 * The rest_api_init callback registers the route when webhook_secret is present.
	 *
	 * @return void
	 */
	public function test_rest_api_init_callback_registers_route_when_webhook_secret_present(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'woocommerce_spart_settings' === $name ) {
					return array( 'webhook_secret' => 'test_secret' );
				}
				if ( 'spart_site_token' === $name ) {
					return 'abc12345';
				}
				return $default;
			}
		);
		Functions\expect( 'register_rest_route' )
			->once()
			->with( 'spart/v1', '/webhook', \Mockery::type( 'array' ) )
			->andReturn( true );

		$captured = null;
		Actions\expectAdded( 'rest_api_init' )
			->once()
			->whenHappen(
				static function ( $callback ) use ( &$captured ): void {
					$captured = $callback;
				}
			);
		Actions\expectAdded( CleanupCron::HOOK )->once();

		Plugin::on_plugins_loaded();

		$this->assertIsCallable( $captured );
		$captured();
	}

	/**
	 * The cleanup-cron callback delegates to webhook_cleanup()->run() when invoked.
	 *
	 * @return void
	 */
	public function test_cleanup_cron_callback_invokes_webhook_cleanup_run(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( 'woocommerce_spart_settings' === $name ) {
					return array();
				}
				return $default;
			}
		);

		$captured = null;
		Actions\expectAdded( 'rest_api_init' )->once();
		Actions\expectAdded( CleanupCron::HOOK )
			->once()
			->whenHappen(
				static function ( $callback ) use ( &$captured ): void {
					$captured = $callback;
				}
			);

		Plugin::on_plugins_loaded();

		$this->assertIsCallable( $captured );

		$repo = \Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'cleanup_older_than' )->once()->with( 30 )->andReturn( 0 );
		$cleanup = new CleanupCron( $repo, new \Spart\WooCommerce\Logging\NullSpartLogger() );

		$reflection = new \ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'webhook_cleanup' );
		$property->setAccessible( true );
		$property->setValue( null, $cleanup );

		$captured();
	}

	/**
	 * Plugin::blocks_support() returns a SpartBlocksSupport instance memoized
	 * for the request.
	 *
	 * @return void
	 */
	public function test_blocks_support_returns_memoized_singleton(): void {
		Plugin::boot( '/tmp/spart-woocommerce/spart-woocommerce.php' );
		Functions\when( 'plugins_url' )->returnArg( 1 );
		Functions\when( 'trailingslashit' )->alias(
			static fn ( string $value ): string => rtrim( $value, '/' ) . '/'
		);

		$first  = Plugin::blocks_support();
		$second = Plugin::blocks_support();

		$this->assertInstanceOf( SpartBlocksSupport::class, $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * Plugin::reset_for_tests() clears the memoized blocks_support singleton.
	 *
	 * @return void
	 */
	public function test_reset_for_tests_clears_blocks_support(): void {
		Plugin::boot( '/tmp/spart-woocommerce/spart-woocommerce.php' );
		Functions\when( 'plugins_url' )->returnArg( 1 );
		Functions\when( 'trailingslashit' )->alias(
			static fn ( string $value ): string => rtrim( $value, '/' ) . '/'
		);

		$first = Plugin::blocks_support();
		Plugin::reset_for_tests();
		Plugin::boot( '/tmp/spart-woocommerce/spart-woocommerce.php' );
		$second = Plugin::blocks_support();

		$this->assertNotSame( $first, $second );
	}

	/**
	 * Plugin::on_plugins_loaded() registers the WC Blocks payment-method
	 * type registration action.
	 *
	 * @return void
	 */
	public function test_on_plugins_loaded_registers_blocks_payment_method_action(): void {
		Actions\expectAdded( 'woocommerce_blocks_payment_method_type_registration' )->once();

		Plugin::on_plugins_loaded();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The closure registered on woocommerce_blocks_payment_method_type_registration
	 * invokes $registry->register() with the SpartBlocksSupport singleton.
	 *
	 * @return void
	 */
	public function test_blocks_payment_method_callback_invokes_registry_register(): void {
		Plugin::boot( '/tmp/spart-woocommerce/spart-woocommerce.php' );
		Functions\when( 'plugins_url' )->returnArg( 1 );
		Functions\when( 'trailingslashit' )->alias(
			static fn ( string $value ): string => rtrim( $value, '/' ) . '/'
		);

		$captured = null;
		Actions\expectAdded( 'woocommerce_blocks_payment_method_type_registration' )
			->once()
			->whenHappen(
				static function ( $callback ) use ( &$captured ): void {
					$captured = $callback;
				}
			);

		Plugin::on_plugins_loaded();

		$this->assertIsCallable( $captured );

		$registry = \Mockery::mock();
		$registry->shouldReceive( 'register' )
			->once()
			->with( \Mockery::type( SpartBlocksSupport::class ) );

		$captured( $registry );
	}

	/**
	 * Plugin::on_plugins_loaded() registers the thank-you page action for Spart orders.
	 *
	 * @return void
	 */
	public function test_on_plugins_loaded_registers_thankyou_action(): void {
		Actions\expectAdded( 'woocommerce_thankyou_spart' )->once();

		Plugin::on_plugins_loaded();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The closure registered on woocommerce_thankyou_spart instantiates a
	 * ThankYouRenderer and invokes render() with the order ID cast to int.
	 *
	 * @return void
	 */
	public function test_thankyou_callback_invokes_renderer(): void {
		Functions\when( 'esc_html__' )->returnArg( 1 );

		$order = \Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_status' )->andReturn( 'pending' );
		Functions\expect( 'wc_get_order' )->once()->with( 5 )->andReturn( $order );

		$captured = null;
		Actions\expectAdded( 'woocommerce_thankyou_spart' )
			->once()
			->whenHappen(
				static function ( $callback ) use ( &$captured ): void {
					$captured = $callback;
				}
			);

		Plugin::on_plugins_loaded();

		$this->assertIsCallable( $captured );

		ob_start();
		$captured( '5' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'spart-thankyou-pending', $output );
	}

	/**
	 * Plugin::eligibility_checker() returns a memoized EligibilityChecker
	 * singleton — same instance on subsequent calls so the checker's
	 * transient-backed cache and any per-request memoization carry over
	 * between checkout renders without rebuild churn.
	 *
	 * @return void
	 */
	public function test_eligibility_checker_returns_memoized_singleton(): void {
		$first  = Plugin::eligibility_checker();
		$second = Plugin::eligibility_checker();

		$this->assertInstanceOf( EligibilityChecker::class, $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * Plugin::set_eligibility_checker_for_tests() swaps the singleton for a
	 * caller-supplied instance — the seam that lets the gateway's
	 * is_available() tests inject a deterministic verdict instead of relying
	 * on the production checker's WP-transient lookup.
	 *
	 * @return void
	 */
	public function test_set_eligibility_checker_for_tests_overrides_singleton(): void {
		$injected = new EligibilityChecker( new \Spart\WooCommerce\Checkout\WpSpartClientFactory() );

		Plugin::set_eligibility_checker_for_tests( $injected );

		$this->assertSame( $injected, Plugin::eligibility_checker() );
	}

	/**
	 * Plugin::reset_for_tests() drops the memoized eligibility-checker
	 * singleton so the next caller in a sibling test gets a fresh instance.
	 *
	 * @return void
	 */
	public function test_reset_for_tests_clears_eligibility_checker(): void {
		$first = Plugin::eligibility_checker();
		Plugin::reset_for_tests();
		Functions\when( 'wc_get_logger' )->justReturn( new \stdClass() );
		Functions\when( 'get_option' )->justReturn( array() );
		$second = Plugin::eligibility_checker();

		$this->assertNotSame( $first, $second );
	}
}
