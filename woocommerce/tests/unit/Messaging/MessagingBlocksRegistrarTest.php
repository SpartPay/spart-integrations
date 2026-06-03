<?php
/**
 * Tests for MessagingBlocksRegistrar.
 *
 * @package Spart\WooCommerce\Tests\Unit\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Messaging;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Constants;
use Spart\WooCommerce\Messaging\MessagingBlocksRegistrar;
use Spart\WooCommerce\Plugin;

final class MessagingBlocksRegistrarTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Plugin::reset_for_tests();
		Plugin::set_plugin_file_for_tests( '/var/www/spart-woocommerce/spart-woocommerce.php' );
	}

	protected function tearDown(): void {
		Plugin::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_init_wp_enqueue_scripts_and_block_editor_assets(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'init', array( MessagingBlocksRegistrar::class, 'register_on_init' ) );
		Functions\expect( 'add_action' )
			->once()
			->with( 'wp_enqueue_scripts', array( MessagingBlocksRegistrar::class, 'enqueue_front_styles' ) );
		Functions\expect( 'add_action' )
			->once()
			->with( 'enqueue_block_editor_assets', array( MessagingBlocksRegistrar::class, 'enqueue_block_editor_assets' ) );

		MessagingBlocksRegistrar::register();
		$this->addToAssertionCount( 1 );
	}

	public function test_register_on_init_registers_handles_and_both_blocks(): void {
		Functions\when( 'plugin_dir_path' )->justReturn( '/var/www/spart-woocommerce/' );
		Functions\when( 'plugins_url' )->alias(
			static fn( string $path, string $file ): string => // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $file kept to match WP signature.
				'https://example.test/wp-content/plugins/spart-woocommerce' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) )
		);
		Functions\expect( 'wp_register_style' )
			->once()
			->with(
				'spart-messaging',
				'https://example.test/wp-content/plugins/spart-woocommerce/assets/css/spart.css',
				array(),
				'0.5.0'
			);
		Functions\expect( 'wp_register_script' )
			->once()
			->with(
				'spart-messaging-blocks-editor',
				'https://example.test/wp-content/plugins/spart-woocommerce/assets/js/messaging-blocks.js',
				array( 'wp-blocks', 'wp-element' ),
				'0.5.0',
				true
			);
		// register_on_init no longer localises — that ships on enqueue_block_editor_assets.
		Functions\expect( 'wp_localize_script' )->never();
		Functions\expect( 'register_block_type' )
			->once()
			->with( '/var/www/spart-woocommerce/blocks/product-messaging' );
		Functions\expect( 'register_block_type' )
			->once()
			->with( '/var/www/spart-woocommerce/blocks/cart-messaging' );

		MessagingBlocksRegistrar::register_on_init();
		$this->addToAssertionCount( 1 );
	}

	public function test_enqueue_block_editor_assets_localizes_editor_payload(): void {
		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				Constants::SCRIPT_HANDLE_MESSAGING_EDITOR,
				Constants::SCRIPT_DATA_VAR_MESSAGING_EDITOR,
				\Mockery::on(
					static function ( $payload ): bool {
						return is_array( $payload )
							&& array_key_exists( 'codes', $payload )
							&& array_key_exists( 'previews', $payload )
							&& isset( $payload['codes']['cartLine1'] )
							&& $payload['codes']['cartLine1'] === Constants::MSG_CODE_CART_LINE_1
							&& isset( $payload['previews']['cartLine1'] )
							&& is_string( $payload['previews']['cartLine1'] );
					}
				)
			);

		MessagingBlocksRegistrar::enqueue_block_editor_assets();
		$this->addToAssertionCount( 1 );
	}

	public function test_enqueue_front_styles_registers_and_enqueues_spart_css_when_messaging_enabled(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'messaging_enabled_product' => 'yes',
				'messaging_enabled_cart'    => 'no',
			)
		);
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/wp-content/plugins/spart-woocommerce/assets/css/spart.css' );
		Functions\when( 'plugin_dir_path' )->justReturn( '/var/www/spart-woocommerce/' );
		Functions\when( 'trailingslashit' )->returnArg( 1 );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				'spart-messaging',
				'https://example.test/wp-content/plugins/spart-woocommerce/assets/css/spart.css',
				array(),
				'0.5.0'
			);

		MessagingBlocksRegistrar::enqueue_front_styles();
		$this->addToAssertionCount( 1 );
	}

	public function test_enqueue_front_styles_does_nothing_when_both_toggles_off(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'messaging_enabled_product' => 'no',
				'messaging_enabled_cart'    => 'no',
			)
		);
		Functions\expect( 'wp_enqueue_style' )->never();

		MessagingBlocksRegistrar::enqueue_front_styles();
		$this->addToAssertionCount( 1 );
	}

	public function test_enqueue_front_styles_does_nothing_off_relevant_pages(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'messaging_enabled_product' => 'yes',
				'messaging_enabled_cart'    => 'yes',
			)
		);
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\expect( 'wp_enqueue_style' )->never();

		MessagingBlocksRegistrar::enqueue_front_styles();
		$this->addToAssertionCount( 1 );
	}
}
