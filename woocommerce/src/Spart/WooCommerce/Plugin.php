<?php
/**
 * Plugin bootstrap class.
 *
 * @package Spart\WooCommerce
 */

declare(strict_types=1);

namespace Spart\WooCommerce;

use Spart\Sdk\Webhooks\SignatureVerifier;
use Spart\WooCommerce\Admin\DestroyOrdersUpgradeNotice;
use Spart\WooCommerce\Admin\OrderPayeesMetaBox;
use Spart\WooCommerce\Admin\OrderWebhookDeliveriesMetaBox;
use Spart\WooCommerce\Admin\WcVersionFloorNotice;
use Spart\WooCommerce\Admin\WebhookDeliveriesListPage;
use Spart\WooCommerce\Admin\WebhookUrlMigrationNotice;
use Spart\WooCommerce\Checkout\CheckoutSession;
use Spart\WooCommerce\Checkout\IntentRequestBuilder;
use Spart\WooCommerce\Checkout\OrderDisposer;
use Spart\WooCommerce\Checkout\OrderDisposerInterface;
use Spart\WooCommerce\Checkout\WpSpartClientFactory;
use Spart\WooCommerce\Compat\WooCommerceCompat;
use Spart\WooCommerce\Constants;
use Spart\WooCommerce\Eligibility\EligibilityChecker;
use Spart\WooCommerce\Http\WpHttpClientFactory;
use Spart\WooCommerce\Logging\LevelFilteredLogger;
use Spart\WooCommerce\Logging\NullSpartLogger;
use Spart\WooCommerce\Logging\SpartLoggerInterface;
use Spart\WooCommerce\Logging\WcLoggerAdapter;
use Spart\WooCommerce\Settings\Schema;
use Spart\WooCommerce\Webhooks\CleanupCron;
use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Webhooks\OrderSync;
use Spart\WooCommerce\Webhooks\RestRouteRegistrar;
use Spart\WooCommerce\Webhooks\WebhookReceiver;
use Spart\WooCommerce\Webhooks\WpOrderResolver;

/**
 * Plugin bootstrap. Single entry point invoked from `spart-woocommerce.php`.
 *
 * Responsibilities scoped to PR1 (walking skeleton):
 *  - Remember the plugin file path so other components can resolve it.
 *  - Register WooCommerce feature-compatibility declarations on
 *    `before_woocommerce_init`.
 *  - Register activation hook (table creation lives in Activation).
 *  - Defer further wiring (gateway registration, settings, webhooks) to
 *    `plugins_loaded` so WooCommerce is guaranteed to be loaded.
 *
 * Boot is idempotent: repeated calls keep the same plugin file and do NOT
 * re-register hooks.
 */
final class Plugin {

	public const VERSION = '0.5.0';

	/**
	 * Absolute path to the plugin entry file.
	 *
	 * @var string|null
	 */
	private static ?string $plugin_file = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Lazily-built logger instance.
	 *
	 * @var SpartLoggerInterface|null
	 */
	private static ?SpartLoggerInterface $logger = null;

	/**
	 * Lazily-built checkout orchestrator.
	 *
	 * @var CheckoutSession|null
	 */
	private static ?CheckoutSession $checkout_session = null;

	/**
	 * Lazily-built webhook receiver orchestrator.
	 *
	 * @var WebhookReceiver|null
	 */
	private static ?WebhookReceiver $webhook_receiver = null;

	/**
	 * Lazily-built webhook cleanup cron handler.
	 *
	 * @var CleanupCron|null
	 */
	private static ?CleanupCron $webhook_cleanup = null;

	/**
	 * Lazily-built DeliveryRepository singleton, shared across webhook
	 * receiver, cleanup cron, and admin surfaces so there is one instance
	 * per request.
	 *
	 * @var DeliveryRepository|null
	 */
	private static ?DeliveryRepository $delivery_repository = null;

	/**
	 * Lazily-built Blocks payment-method type integration.
	 *
	 * @var Gateway\Blocks\SpartBlocksSupport|null
	 */
	private static ?Gateway\Blocks\SpartBlocksSupport $blocks_support = null;

	/**
	 * Lazily-built failed-order disposer.
	 *
	 * Typed against the interface so tests can install a hand-rolled spy
	 * via {@see self::set_order_disposer_for_tests()} without having to
	 * mock the `final` production class.
	 *
	 * @var OrderDisposerInterface|null
	 */
	private static ?OrderDisposerInterface $order_disposer = null;

	/**
	 * Lazily-built gateway-eligibility checker.
	 *
	 * Wired into {@see \Spart\WooCommerce\Gateway\WC_Gateway_Spart::is_available()}
	 * to hide the Spart payment method when the merchant cannot yet start
	 * payment intents. Held as a singleton because `is_available()` is called
	 * on every checkout render and the checker carries its own short-lived
	 * transient cache — rebuilding it would waste work without changing
	 * behaviour.
	 *
	 * @var EligibilityChecker|null
	 */
	private static ?EligibilityChecker $eligibility_checker = null;

	/**
	 * Bootstrap the plugin.
	 *
	 * @param string $plugin_file Absolute path to the plugin entry file.
	 * @return void
	 */
	public static function boot( string $plugin_file ): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted      = true;
		self::$plugin_file = $plugin_file;

		// Register the gettext filter FIRST so subsequent __() calls in this
		// boot sequence get the symbolic-codes substitution applied.
		I18n\GettextFilter::register();

		// Load bundled translations from /languages so translator-shipped .mo
		// files take effect alongside (and after) the symbolic-codes filter.
		// WP 4.6+ just-in-time loading only walks WP_LANG_DIR/plugins/, not
		// the plugin's own /languages directory for non-wp.org plugins.
		\add_action(
			'init',
			static function () use ( $plugin_file ): void {
				\load_plugin_textdomain(
					'spart-woocommerce',
					false,
					\dirname( \plugin_basename( $plugin_file ) ) . '/languages'
				);
			},
			1
		);

		// Block registrar handles its own action wiring (init + wp_enqueue_scripts).
		Messaging\MessagingBlocksRegistrar::register();

		// Renderer registrations are no-ops when their respective toggles are off.
		Messaging\ProductPageMessaging::register();
		Messaging\CartMessaging::register();

		\add_action( 'before_woocommerce_init', array( WooCommerceCompat::class, 'declare' ) );
		\add_action( 'plugins_loaded', array( self::class, 'on_plugins_loaded' ) );

		\add_filter(
			'http_request_host_is_external',
			array( WpHttpClientFactory::class, 'filter_host_is_external' ),
			10,
			3
		);

		\register_activation_hook( $plugin_file, array( Activation::class, 'activate' ) );
		\register_deactivation_hook( $plugin_file, array( Deactivation::class, 'deactivate' ) );
	}

	/**
	 * Return the absolute path to the plugin entry file captured at boot.
	 *
	 * @throws \LogicException If boot() has not been called yet.
	 * @return string
	 */
	public static function plugin_file(): string {
		if ( null === self::$plugin_file ) {
			throw new \LogicException( 'Plugin::boot() must be called before Plugin::plugin_file().' );
		}
		return self::$plugin_file;
	}

	/**
	 * Whether boot() has run for the current PHP process.
	 *
	 * Useful for integration tests to verify WP loaded the plugin file (as
	 * opposed to autoloading the class without invoking boot()).
	 *
	 * @return bool
	 */
	public static function is_booted(): bool {
		return self::$booted;
	}

	/**
	 * `plugins_loaded` callback — wires everything that depends on WC being available.
	 *
	 * Webhook wiring intentionally uses closures so the receiver / cleanup
	 * singletons are NOT built at plugins_loaded time:
	 *  - `rest_api_init` only fires for REST requests, not regular page loads.
	 *  - `CleanupCron::HOOK` only fires when WP-Cron triggers the daily job.
	 * The receiver build path also fails closed if the merchant has not yet
	 * filled in `webhook_secret` (the SDK's SignatureVerifier rejects a blank
	 * signing secret), so route registration is gated on the secret being
	 * present — otherwise `Plugin::webhook_receiver()` would throw on every
	 * REST request and break the entire WP REST API for the site.
	 *
	 * @return void
	 */
	public static function on_plugins_loaded(): void {
		\add_filter( 'woocommerce_payment_gateways', array( self::class, 'register_gateway' ) );

		\add_action(
			'rest_api_init',
			static function (): void {
				$settings = (array) \get_option( Constants::OPTION_KEY, array() );
				$secret   = (string) ( $settings['webhook_secret'] ?? '' );
				if ( '' === $secret ) {
					return;
				}

				( new RestRouteRegistrar( self::webhook_receiver() ) )->register();
			}
		);

		\add_action(
			CleanupCron::HOOK,
			static function (): void {
				self::webhook_cleanup()->run();
			}
		);

		( new WebhookUrlMigrationNotice() )->register();
		( new DestroyOrdersUpgradeNotice() )->register();
		( new WcVersionFloorNotice() )->register();
		( new WebhookDeliveriesListPage( self::delivery_repository() ) )->register();
		( new OrderWebhookDeliveriesMetaBox( self::delivery_repository() ) )->register();
		( new OrderPayeesMetaBox() )->register();

		\add_action(
			'woocommerce_blocks_payment_method_type_registration',
			/**
			 * Register Spart's payment-method type with the WC Blocks registry.
			 *
			 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry
			 */
			static function ( $registry ): void {
				$registry->register( self::blocks_support() );
			}
		);

		\add_action(
			'woocommerce_thankyou_spart',
			static function ( $order_id ): void {
				( new Checkout\ThankYouRenderer() )->render( (int) $order_id );
			}
		);
	}

	/**
	 * Append the Spart gateway to WooCommerce's gateway list.
	 *
	 * @param array<int, string> $gateways Registered gateway class names.
	 * @return array<int, string>
	 */
	public static function register_gateway( array $gateways ): array {
		$gateways[] = Gateway\WC_Gateway_Spart::class;
		return $gateways;
	}

	/**
	 * Lazy logger singleton.
	 *
	 * Always returns a logger that forwards WARNING and ERROR to
	 * wc_get_logger() (when WooCommerce is loaded). INFO and DEBUG are
	 * additionally forwarded when the merchant has enabled 'verbose
	 * logging' in the settings. Falls back to NullSpartLogger only when
	 * wc_get_logger() is unavailable (CLI bootstrap, unit tests).
	 *
	 * @return SpartLoggerInterface
	 */
	public static function logger(): SpartLoggerInterface {
		if ( null !== self::$logger ) {
			return self::$logger;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			self::$logger = new NullSpartLogger();
			return self::$logger;
		}

		// Re-read the option on every INFO/DEBUG emit so the singleton
		// reflects admin-side toggles of "Verbose logging" without needing
		// a request restart or logger rebuild.
		$verbose_provider = static function (): bool {
			$settings = (array) \get_option( Constants::OPTION_KEY, array() );
			return ( $settings['debug_logging'] ?? 'no' ) === 'yes';
		};

		self::$logger = new LevelFilteredLogger( new WcLoggerAdapter( \wc_get_logger() ), $verbose_provider );
		return self::$logger;
	}

	/**
	 * Lazy CheckoutSession singleton — wires the WP-side factory, the order-to-request
	 * builder, and the logger. Production-only entry point used by WC_Gateway_Spart.
	 *
	 * @return CheckoutSession
	 */
	public static function checkout_session(): CheckoutSession {
		if ( null !== self::$checkout_session ) {
			return self::$checkout_session;
		}

		$settings = (array) \get_option( Constants::OPTION_KEY, array() );
		$minutes  = (int) ( $settings['default_order_duration_minutes'] ?? Schema::DEFAULT_ORDER_DURATION_MINUTES );

		self::$checkout_session = new CheckoutSession(
			new WpSpartClientFactory( self::logger() ),
			new IntentRequestBuilder( $minutes ),
			self::logger()
		);
		return self::$checkout_session;
	}

	/**
	 * Lazy OrderDisposer singleton — coordinator for unwinding a failed
	 * pending order. Built on first use because dispose() is only called
	 * on checkout failure.
	 *
	 * Returns the interface so tests can substitute a spy via
	 * {@see self::set_order_disposer_for_tests()}.
	 *
	 * @return OrderDisposerInterface
	 */
	public static function order_disposer(): OrderDisposerInterface {
		if ( null !== self::$order_disposer ) {
			return self::$order_disposer;
		}

		self::$order_disposer = new OrderDisposer(
			self::logger(),
			static fn(): string => ( new WpSpartClientFactory() )->api_key()
		);
		return self::$order_disposer;
	}

	/**
	 * Lazy EligibilityChecker singleton — gates {@see \Spart\WooCommerce\Gateway\WC_Gateway_Spart::is_available()}
	 * on the merchant's current `GET /api/merchants/eligibility` verdict.
	 *
	 * Built on first use because `is_available()` is called on every checkout
	 * render but eligibility is only one of several availability inputs — no
	 * value in spinning the checker up at `plugins_loaded` if the gateway is
	 * disabled, missing an API key, or never reached.
	 *
	 * @return EligibilityChecker
	 */
	public static function eligibility_checker(): EligibilityChecker {
		if ( null !== self::$eligibility_checker ) {
			return self::$eligibility_checker;
		}

		self::$eligibility_checker = new EligibilityChecker(
			new WpSpartClientFactory( self::logger() ),
			self::logger()
		);
		return self::$eligibility_checker;
	}

	/**
	 * Lazy WebhookReceiver singleton — orchestrates inbound webhook deliveries.
	 *
	 * Wires the SDK's HMAC signature verifier (seeded with the merchant's
	 * configured webhook_secret), the dedupe-table repository, the order-side-
	 * effect applier, the order resolver (seeded with the site token persisted
	 * at activation), and the shared logger. Built on first use because the
	 * REST route is only invoked on `rest_api_init`, well after `plugins_loaded`.
	 *
	 * @return WebhookReceiver
	 */
	public static function webhook_receiver(): WebhookReceiver {
		if ( null !== self::$webhook_receiver ) {
			return self::$webhook_receiver;
		}

		$settings   = (array) \get_option( Constants::OPTION_KEY, array() );
		$secret     = (string) ( $settings['webhook_secret'] ?? '' );
		$site_token = (string) \get_option( 'spart_site_token', '' );

		self::$webhook_receiver = new WebhookReceiver(
			new SignatureVerifier( $secret ),
			self::delivery_repository(),
			new OrderSync( self::logger() ),
			new WpOrderResolver( $site_token ),
			self::logger()
		);
		return self::$webhook_receiver;
	}

	/**
	 * Lazy CleanupCron singleton — daily housekeeping of the dedupe table.
	 *
	 * Built on first use because the cron action callback only fires when
	 * WP-Cron triggers `CleanupCron::HOOK`, which is hours after `plugins_loaded`.
	 * Shares its repository with no other consumer (cleanup is destructive,
	 * so giving it its own DeliveryRepository keeps the contract obvious).
	 *
	 * @return CleanupCron
	 */
	public static function webhook_cleanup(): CleanupCron {
		if ( null !== self::$webhook_cleanup ) {
			return self::$webhook_cleanup;
		}

		self::$webhook_cleanup = new CleanupCron(
			self::delivery_repository(),
			self::logger()
		);
		return self::$webhook_cleanup;
	}

	/**
	 * Lazy DeliveryRepository singleton — one instance per request, shared
	 * across the webhook receiver, the cleanup cron, and the admin surfaces.
	 *
	 * Holding a single instance also keeps the wpdb dependency in one place;
	 * if a future change needs to inject a different wpdb (e.g. multisite),
	 * this is the single edit point.
	 *
	 * @return DeliveryRepository
	 */
	public static function delivery_repository(): DeliveryRepository {
		if ( null !== self::$delivery_repository ) {
			return self::$delivery_repository;
		}

		global $wpdb;

		self::$delivery_repository = new DeliveryRepository( $wpdb );
		return self::$delivery_repository;
	}

	/**
	 * Lazy SpartBlocksSupport singleton — wires the Blocks payment-method
	 * registry against the merchant's saved gateway settings.
	 *
	 * Built on first registration call (on woocommerce_blocks_payment_method_type_registration),
	 * not at plugins_loaded, because the Blocks integration registry is
	 * only constructed once WC Blocks itself initialises (later in the
	 * boot order than plugins_loaded).
	 *
	 * @return Gateway\Blocks\SpartBlocksSupport
	 */
	public static function blocks_support(): Gateway\Blocks\SpartBlocksSupport {
		if ( null !== self::$blocks_support ) {
			return self::$blocks_support;
		}

		$assets_url = \trailingslashit( \plugins_url( 'assets/', self::plugin_file() ) );

		self::$blocks_support = new Gateway\Blocks\SpartBlocksSupport(
			new Gateway\Blocks\PaymentMethodDataBuilder(),
			$assets_url,
			self::VERSION
		);
		return self::$blocks_support;
	}

	/**
	 * Resolve the Spart API base URL for the given environment.
	 *
	 * @param string $environment Either "live" or "sandbox".
	 * @return string
	 */
	public static function base_url_for( string $environment ): string {
		return WpHttpClientFactory::base_url_for( $environment );
	}

	/**
	 * Hostnames that the WP HTTP API is allowed to reach via Spart's clients.
	 *
	 * @return list<string>
	 */
	public static function allow_spart_hosts(): array {
		return WpHttpClientFactory::allowed_spart_hosts();
	}

	/**
	 * Reset static state between tests. Production code MUST NOT call this.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$booted              = false;
		self::$plugin_file         = null;
		self::$logger              = null;
		self::$checkout_session    = null;
		self::$webhook_receiver    = null;
		self::$webhook_cleanup     = null;
		self::$delivery_repository = null;
		self::$blocks_support      = null;
		self::$order_disposer      = null;
		self::$eligibility_checker = null;
	}

	/**
	 * Set the plugin file path for tests without running the full boot sequence.
	 *
	 * Used by tests that exercise classes which call Plugin::plugin_file()
	 * but don't need the side effects of Plugin::boot() (action registration,
	 * activation/deactivation hooks, etc.).
	 *
	 * @internal Test-only seam.
	 *
	 * @param string $plugin_file Absolute path to the plugin entry file.
	 * @return void
	 */
	public static function set_plugin_file_for_tests( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;
	}

	/**
	 * Replace the lazy OrderDisposer singleton with a test double.
	 *
	 * Pass `null` to clear the override and let the next call to
	 * {@see self::order_disposer()} rebuild the production singleton.
	 * Cleared automatically by {@see self::reset_for_tests()}.
	 *
	 * @internal Test-only seam.
	 *
	 * @param OrderDisposerInterface|null $disposer Test double or null to reset.
	 * @return void
	 */
	public static function set_order_disposer_for_tests( ?OrderDisposerInterface $disposer ): void {
		self::$order_disposer = $disposer;
	}

	/**
	 * Replace the lazy logger singleton with a test double.
	 *
	 * Pass `null` to clear the override and let the next call to
	 * {@see self::logger()} rebuild the production singleton.
	 * Cleared automatically by {@see self::reset_for_tests()}.
	 *
	 * @internal Test-only seam.
	 *
	 * @param SpartLoggerInterface|null $logger Test double or null to reset.
	 * @return void
	 */
	public static function set_logger_for_tests( ?SpartLoggerInterface $logger ): void {
		self::$logger = $logger;
	}

	/**
	 * Replace the lazy EligibilityChecker singleton with a test double.
	 *
	 * Pass `null` to clear the override and let the next call to
	 * {@see self::eligibility_checker()} rebuild the production singleton.
	 * Cleared automatically by {@see self::reset_for_tests()}.
	 *
	 * @internal Test-only seam.
	 *
	 * @param EligibilityChecker|null $checker Test double or null to reset.
	 * @return void
	 */
	public static function set_eligibility_checker_for_tests( ?EligibilityChecker $checker ): void {
		self::$eligibility_checker = $checker;
	}
}
