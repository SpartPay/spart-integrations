<?php
/**
 * Loading screen settings and checkout asset boundaries.
 *
 * @package Spart\WooCommerce\Tests\Unit
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Checkout\LoadingScreen;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Gateway\Blocks\SpartBlocksSupport;
use Spart\WooCommerce\Gateway\Blocks\PaymentMethodDataBuilder;
use Spart\WooCommerce\I18n\GettextFilter;
use Spart\WooCommerce\Settings\Schema;

final class LoadingScreenTest extends TestCase {

	private array $emitted_config = array();
	private string $inline_config = '';

	protected function setUp(): void {
		Monkey\setUp();
		Schema::reset_for_tests();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'rest_url' )->justReturn( 'https://shop.test/wp-json/spart/v1/webhook' );
		Functions\when( 'wp_attachment_is_image' )->alias( static fn ( $id ) => in_array( $id, array( 12, 13, 14 ), true ) );
		Functions\when( 'get_post_mime_type' )->alias(
			static fn ( $id ) => array(
				12 => 'image/gif',
				13 => 'image/svg+xml',
				14 => 'image/webp',
			)[ $id ] ?? 'application/pdf'
		);
		Functions\when( 'wp_get_attachment_image_url' )->alias( static fn ( $id ) => 12 === $id ? 'https://shop.test/uploads/loading.gif' : false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'wp_scripts' )->justReturn( (object) array( 'registered' => array() ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Implement the WP JSON boundary without loading WordPress.
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value, $flags = 0 ) => json_encode( $value, $flags ) );
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $name, $values ) {
				if ( 'spartCheckoutLoadingConfig' === $name ) {
						// WP_Scripts::localize casts top-level scalar values to strings.
						$this->emitted_config = array_map( static fn ( $value ) => is_scalar( $value ) ? (string) $value : $value, $values );
				}
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $data, $position ) {
				$this->assertSame( 'spart-checkout-loading', $handle );
				$this->assertSame( 'before', $position );
				$this->inline_config  = $data;
				$this->emitted_config = json_decode( substr( $data, strlen( 'window.spartCheckoutLoadingConfig = ' ), -1 ), true, 512, JSON_THROW_ON_ERROR );
			}
		);
	}

	protected function tearDown(): void {
		Schema::reset_for_tests();
		Monkey\tearDown();
	}

	private function screen(): LoadingScreen {
		$this->assertTrue( class_exists( LoadingScreen::class ), 'Loading screen asset integration must exist.' );
		return new LoadingScreen( 'https://shop.test/assets/', 'test-version' );
	}

	public function test_fresh_and_upgrade_defaults_are_on_and_preserve_saved_off(): void {
		$gateway = new WC_Gateway_Spart();
		$this->assertSame( 'yes', $gateway->get_option( 'loading_screen_enabled' ) );
		$gateway->settings = array( 'enabled' => 'yes' );
		$this->assertSame( 'yes', $gateway->get_option( 'loading_screen_enabled' ) );
		$gateway->settings['loading_screen_enabled'] = 'no';
		$this->assertSame( 'no', $gateway->get_option( 'loading_screen_enabled' ) );
		$this->assertSame( 'yes', LoadingScreen::sanitize( array() )['loading_screen_enabled'] );
		$this->assertSame( 'no', LoadingScreen::sanitize( array( 'loading_screen_enabled' => 'no' ) )['loading_screen_enabled'] );
		$this->assertSame( '#192a23', $gateway->get_option( 'loading_screen_backdrop_color' ) );
		$this->assertSame( 55, $gateway->get_option( 'loading_screen_backdrop_opacity' ) );
		$this->assertSame( 0, $gateway->get_option( 'loading_screen_image_id' ) );
		$this->assertSame( 'title', $gateway->form_fields['loading_screen']['type'] );
		$this->assertSame( 'debug_api_endpoint', array_key_last( $gateway->form_fields ) );
	}

	/** @dataProvider settings_cases */
	public function test_gateway_save_sanitizes_loading_settings( array $input, array $expected ): void {
		$gateway = new WC_Gateway_Spart();
		$saved   = $gateway->enforce_schema_invariants( array_merge( Schema::defaults(), $input ) );
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $saved[ $key ] ?? null, $key );
		}
		$this->assertArrayNotHasKey( 'loading_screen', $saved );
	}

	public static function settings_cases(): array {
		return array(
			'valid custom raster'            => array(
				array(
					'loading_screen_enabled'          => 'yes',
					'loading_screen_backdrop_color'   => '#AbC123',
					'loading_screen_backdrop_opacity' => '100',
					'loading_screen_image_id'         => '12',
				),
				array(
					'loading_screen_enabled'          => 'yes',
					'loading_screen_backdrop_color'   => '#abc123',
					'loading_screen_backdrop_opacity' => 100,
					'loading_screen_image_id'         => 12,
				),
			),
			'zero opacity and cleared image' => array(
				array(
					'loading_screen_backdrop_opacity' => '0',
					'loading_screen_image_id'         => '0',
				),
				array(
					'loading_screen_backdrop_opacity' => 0,
					'loading_screen_image_id'         => 0,
				),
			),
			'unsafe values'                  => array(
				array(
					'loading_screen_backdrop_color'   => 'red; background:url(evil)',
					'loading_screen_backdrop_opacity' => '101',
					'loading_screen_image_id'         => '13',
				),
				array(
					'loading_screen_backdrop_color'   => '#192a23',
					'loading_screen_backdrop_opacity' => 55,
					'loading_screen_image_id'         => 0,
				),
			),
			'negative'                       => array(
				array(
					'loading_screen_backdrop_opacity' => '-1',
					'loading_screen_image_id'         => '-12',
				),
				array(
					'loading_screen_backdrop_opacity' => 55,
					'loading_screen_image_id'         => 0,
				),
			),
			'fractional'                     => array(
				array(
					'loading_screen_backdrop_opacity' => '5.5',
					'loading_screen_image_id'         => '12.5',
				),
				array(
					'loading_screen_backdrop_opacity' => 55,
					'loading_screen_image_id'         => 0,
				),
			),
			'arrays'                         => array(
				array(
					'loading_screen_backdrop_color'   => array(),
					'loading_screen_backdrop_opacity' => array(),
					'loading_screen_image_id'         => array(),
				),
				array(
					'loading_screen_backdrop_color'   => '#192a23',
					'loading_screen_backdrop_opacity' => 55,
					'loading_screen_image_id'         => 0,
				),
			),
			'nonimage'                       => array( array( 'loading_screen_image_id' => '99' ), array( 'loading_screen_image_id' => 0 ) ),
			'missing attachment URL'         => array( array( 'loading_screen_image_id' => '14' ), array( 'loading_screen_image_id' => 0 ) ),
		);
	}

	/** @dataProvider raw_field_cases */
	public function test_loading_fields_are_validated_before_wc_text_coercion( string $key, mixed $input, string $expected ): void {
		$gateway = new WC_Gateway_Spart();
		$this->assertTrue( method_exists( $gateway, 'validate_text_field' ), 'Loading fields need validation before WC applies stripslashes to raw POST values.' );
		$this->assertSame( $expected, $gateway->validate_text_field( $key, $input ) );
	}

	public static function raw_field_cases(): array {
		return array(
			array( 'loading_screen_backdrop_color', array( '#ffffff' ), '#192a23' ),
			array( 'loading_screen_backdrop_opacity', array( '50' ), '55' ),
			array( 'loading_screen_image_id', array( '12' ), '0' ),
			array( 'loading_screen_backdrop_color', '#ABCDEF', '#abcdef' ),
			array( 'loading_screen_backdrop_opacity', '12.5', '55' ),
			array( 'loading_screen_backdrop_opacity', '0', '0' ),
			array( 'loading_screen_image_id', '12.5', '0' ),
			array( 'loading_screen_image_id', '12', '12' ),
		);
	}

	private function enable(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'enabled'                => 'yes',
				'loading_screen_enabled' => 'yes',
			)
		);
	}

	/** @dataProvider disabled_cases */
	public function test_no_assets_outside_enabled_checkout( array $settings, bool $checkout, bool $endpoint, bool $admin ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
		Functions\when( 'is_checkout' )->justReturn( $checkout );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( $endpoint );
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\expect( 'wp_register_script' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_localize_script' )->never();
		$this->screen()->enqueue();
	}

	public static function disabled_cases(): array {
		$on = array(
			'enabled'                => 'yes',
			'loading_screen_enabled' => 'yes',
		);
		return array(
			'fresh'          => array( array(), true, false, false ),
			'explicit off'   => array(
				array(
					'enabled'                => 'yes',
					'loading_screen_enabled' => 'no',
				),
				true,
				false,
				false,
			),
			'gateway off'    => array(
				array(
					'enabled'                => 'no',
					'loading_screen_enabled' => 'yes',
				),
				true,
				false,
				false,
			),
			'non checkout'   => array( $on, false, false, false ),
			'order endpoint' => array( $on, true, true, false ),
			'admin'          => array( $on, true, false, true ),
		);
	}

	/** @dataProvider checkout_types */
	public function test_checkout_enqueues_shared_config_and_only_classic_adapter_when_needed( bool $blocks, array $settings ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
		Functions\when( 'has_block' )->alias( static fn ( $name ) => 'woocommerce/checkout' === $name && $blocks );
		Functions\expect( 'wp_register_script' )->once()->with( 'spart-checkout-loading', 'https://shop.test/assets/js/checkout-loading.js', array(), 'test-version', true );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'spart-checkout-loading', 'https://shop.test/assets/css/checkout-loading.css', array(), 'test-version' );
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'spart-checkout-loading' );
		if ( ! $blocks ) {
			Functions\expect( 'wp_enqueue_script' )->once()->with( 'spart-classic-checkout-loading', 'https://shop.test/assets/js/classic-checkout-loading.js', array( 'jquery', 'wc-checkout', 'spart-checkout-loading' ), 'test-version', true );
		}
		$this->screen()->enqueue();
		$this->assertSame(
			array(
				'backdropColor'   => '#192a23',
				'backdropOpacity' => 55,
				'imageUrl'        => '',
				'title'           => 'SPART_LOADING_TITLE',
				'description'     => 'SPART_LOADING_DESCRIPTION',
			),
			$this->emitted_config
		);
	}

	public static function checkout_types(): array {
		return array(
			'classic missing preference' => array( false, array( 'enabled' => 'yes' ) ),
			'blocks missing preference'  => array( true, array( 'enabled' => 'yes' ) ),
			'classic explicit on'        => array(
				false,
				array(
					'enabled'                => 'yes',
					'loading_screen_enabled' => 'yes',
				),
			),
			'blocks explicit on'         => array(
				true,
				array(
					'enabled'                => 'yes',
					'loading_screen_enabled' => 'yes',
				),
			),
		);
	}

	/** @dataProvider blocks_preferences */
	public function test_blocks_dependency_respects_missing_and_explicit_preferences( array $settings, bool $expected ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
		$registered = array();
		Functions\when( 'wp_register_script' )->alias(
			static function ( $handle, $url, $deps ) use ( &$registered ) {
				$registered[ $handle ] = $deps;
			}
		);
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
		$support = new SpartBlocksSupport( new PaymentMethodDataBuilder(), 'https://shop.test/assets/', 'test-version' );
		$support->initialize();
		$this->assertSame( array( 'spart-blocks-checkout' ), $support->get_payment_method_script_handles() );
		$this->assertSame( $expected, isset( $registered['spart-checkout-loading'] ) );
		$this->assertSame( $expected, in_array( 'spart-checkout-loading', $registered['spart-blocks-checkout'], true ) );
	}

	public static function blocks_preferences(): array {
		return array(
			'missing' => array( array( 'enabled' => 'yes' ), true ),
			'on'      => array(
				array(
					'enabled'                => 'yes',
					'loading_screen_enabled' => 'yes',
				),
				true,
			),
			'off'     => array(
				array(
					'enabled'                => 'yes',
					'loading_screen_enabled' => 'no',
				),
				false,
			),
		);
	}

	/** @dataProvider blocks_preferences */
	public function test_early_blocks_registration_gets_checkout_dependency_when_enqueued( array $settings, bool $expected ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'has_block' )->justReturn( true );
		$scripts = (object) array( 'registered' => array() );
		Functions\when( 'wp_scripts' )->justReturn( $scripts );
		Functions\when( 'wp_register_script' )->alias(
			static function ( $handle, $url, $deps ) use ( $scripts ) {
				// WordPress keeps the first registration, including its dependencies.
				$scripts->registered[ $handle ] ??= (object) array( 'deps' => $deps );
			}
		);
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		$support = new SpartBlocksSupport( new PaymentMethodDataBuilder(), 'https://shop.test/assets/', 'test-version' );
		$support->initialize();
		$support->get_payment_method_script_handles();
		$base_deps = $scripts->registered['spart-blocks-checkout']->deps;
		$this->assertNotContains( 'spart-checkout-loading', $base_deps );

		Functions\when( 'is_checkout' )->justReturn( true );
		$support->get_payment_method_script_handles();
		$this->screen()->enqueue();
		$this->screen()->enqueue();
		$this->assertSame(
			$expected ? array_merge( $base_deps, array( 'spart-checkout-loading' ) ) : $base_deps,
			$scripts->registered['spart-blocks-checkout']->deps
		);
		$this->assertSame( $expected, isset( $scripts->registered['spart-checkout-loading'] ) );
	}

	public function test_custom_config_resolves_attachment_url_and_revalidates_stored_values(): void {
		$screen = $this->screen();
		$config = $screen->config(
			array(
				'loading_screen_image_id'         => 12,
				'loading_screen_backdrop_color'   => '#FEDCBA',
				'loading_screen_backdrop_opacity' => 0,
			)
		);
		$this->assertSame( 'https://shop.test/uploads/loading.gif', $config['imageUrl'] );
		$this->assertSame( '#fedcba', $config['backdropColor'] );
		$this->assertSame( 0, $config['backdropOpacity'] );
		$this->assertSame( '', $screen->config( array( 'loading_screen_image_id' => 13 ) )['imageUrl'] );
	}
	public function test_admin_assets_only_load_on_authorized_spart_settings(): void {
		$screen = $this->screen();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_enqueue_media' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		$_GET = array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'spart',
		);
		$screen->enqueue_admin( 'woocommerce_page_wc-settings' );
		Functions\when( 'current_user_can' )->justReturn( true );
		$screen->enqueue_admin( 'dashboard' );
		$_GET['section'] = 'other';
		$screen->enqueue_admin( 'woocommerce_page_wc-settings' );
		$_GET['section'] = 'spart';
		$_GET['tab']     = 'general';
		$screen->enqueue_admin( 'woocommerce_page_wc-settings' );
		$_GET = array();
	}

	public function test_authorized_admin_can_preview_even_when_toggle_is_off(): void {
		$screen = $this->screen();
		$_GET   = array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'spart',
		);
		Functions\expect( 'current_user_can' )->with( 'manage_woocommerce' )->andReturn( true );
		Functions\expect( 'wp_enqueue_media' )->once();
		Functions\expect( 'wp_register_script' )->once()->with( 'spart-checkout-loading', 'https://shop.test/assets/js/checkout-loading.js', array(), 'test-version', true );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'spart-checkout-loading', 'https://shop.test/assets/css/checkout-loading.css', array(), 'test-version' );
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'spart-checkout-loading' );
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'spart-loading-screen-admin', 'https://shop.test/assets/js/loading-screen-admin.js', array( 'jquery', 'media-editor', 'spart-checkout-loading' ), 'test-version', true );
		$configs = array();
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $config ) use ( &$configs ) {
				$configs[ $name ] = $config;
			}
		);
		$screen->enqueue_admin( 'woocommerce_page_wc-settings' );
		$this->assertSame( 'SPART_LOADING_CLOSE', $configs['spartLoadingScreenAdminConfig']['closeLabel'] );
		$this->assertSame( 'SPART_LOADING_CHOOSE_IMAGE', $configs['spartLoadingScreenAdminConfig']['chooseImage'] );
		$this->assertSame( 'SPART_LOADING_CLEAR_IMAGE', $configs['spartLoadingScreenAdminConfig']['clearImage'] );
		$this->assertSame( 'SPART_LOADING_PREVIEW', $configs['spartLoadingScreenAdminConfig']['preview'] );
		$this->assertArrayHasKey( 'invalidImage', $configs['spartLoadingScreenAdminConfig'] );
		$this->assertSame( '', $this->emitted_config['imageUrl'] );
		$_GET = array();
	}

	public function test_loading_copy_resolves_through_php_translation_dictionary(): void {
		$this->assertSame( 'Connecting to Spart...', GettextFilter::filter( 'SPART_LOADING_TITLE', 'SPART_LOADING_TITLE', 'spart-woocommerce' ) );
		$this->assertSame( 'Please wait while we prepare your checkout.', GettextFilter::filter( 'SPART_LOADING_DESCRIPTION', 'SPART_LOADING_DESCRIPTION', 'spart-woocommerce' ) );
	}
	public function test_plugin_boot_wires_loading_assets_at_request_time_only_once(): void {
		\Spart\WooCommerce\Plugin::reset_for_tests();
		$this->enable();
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[ $hook ][] = $callback;
			}
		);
		Functions\when( 'plugins_url' )->justReturn( 'https://shop.test/assets/' );
		$enqueued = array();
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);
		\Spart\WooCommerce\Plugin::boot( '/tmp/spart/spart-woocommerce.php' );
		\Spart\WooCommerce\Plugin::boot( '/tmp/spart/spart-woocommerce.php' );
		$this->assertSame( array(), $enqueued );
		foreach ( $hooks['wp_enqueue_scripts'] ?? array() as $callback ) {
			$callback();
		}
		$this->assertSame( array( 'spart-checkout-loading', 'spart-classic-checkout-loading' ), $enqueued );
		$this->assertCount( 1, $hooks['admin_enqueue_scripts'] ?? array() );
		\Spart\WooCommerce\Plugin::reset_for_tests();
	}
	public function test_shared_config_json_cannot_break_out_of_script_element(): void {
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://shop.test/</script><script>alert(1)</script>' );
		$this->screen()->register_shared( array( 'loading_screen_image_id' => 12 ) );
		$this->assertNotSame( '', $this->inline_config );
		$this->assertStringNotContainsString( '</script>', $this->inline_config );
		$this->assertSame( 'https://shop.test/</script><script>alert(1)</script>', $this->emitted_config['imageUrl'] );
	}

	public function test_svg_with_a_resolvable_attachment_url_is_still_rejected(): void {
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://shop.test/loading.svg' );
		$saved = ( new WC_Gateway_Spart() )->enforce_schema_invariants( array_merge( Schema::defaults(), array( 'loading_screen_image_id' => '13' ) ) );
		$this->assertSame( 0, $saved['loading_screen_image_id'] );
	}
}
