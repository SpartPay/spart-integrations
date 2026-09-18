<?php
/**
 * Messaging Gutenberg blocks registrar.
 *
 * @package Spart\WooCommerce\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Messaging;

use Spart\WooCommerce\Constants;
use Spart\WooCommerce\Plugin;

/**
 * Registers the spart/product-messaging and spart/cart-messaging server-side
 * rendered blocks on `init`, enqueues the shared spart-messaging stylesheet
 * on `wp_enqueue_scripts` when at least one toggle is enabled and the request
 * is for a product or cart page, and localises the editor preview payload on
 * `enqueue_block_editor_assets` so the four `__()` calls only run when the
 * block editor is actually being rendered.
 */
final class MessagingBlocksRegistrar {

	/**
	 * Register the init, wp_enqueue_scripts, and enqueue_block_editor_assets
	 * action hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_on_init' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_front_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Register the shared style + editor script handles, then both SSR
	 * Gutenberg blocks. The handles must be registered BEFORE the block
	 * types so block.json's `style` and `editorScript` fields can resolve
	 * them by name.
	 *
	 * The editor preview payload is NOT built here — it ships on the
	 * `enqueue_block_editor_assets` hook instead, so the four `__()` calls
	 * inside MessagingEditorPayload::build() only fire on block editor
	 * screens (not on every front-end pageview, REST request, AJAX call,
	 * or cron tick).
	 *
	 * @return void
	 */
	public static function register_on_init(): void {
		$base     = plugin_dir_path( Plugin::plugin_file() );
		$base_url = plugins_url( '', Plugin::plugin_file() );

		wp_register_style(
			Constants::STYLE_HANDLE_MESSAGING,
			$base_url . '/assets/css/spart.css',
			array(),
			Plugin::VERSION
		);

		wp_register_script(
			Constants::SCRIPT_HANDLE_MESSAGING_EDITOR,
			$base_url . '/assets/js/messaging-blocks.js',
			array( 'wp-blocks', 'wp-element' ),
			Plugin::VERSION,
			true
		);

		register_block_type( $base . 'blocks/product-messaging' );
		register_block_type( $base . 'blocks/cart-messaging' );
	}

	/**
	 * Localise the editor preview payload onto the messaging editor script
	 * handle. Fires only on block editor screens, so the `__()` calls in
	 * `MessagingEditorPayload::build()` are skipped on front-end pageviews,
	 * REST requests, AJAX calls, and cron ticks.
	 *
	 * @return void
	 */
	public static function enqueue_block_editor_assets(): void {
		wp_localize_script(
			Constants::SCRIPT_HANDLE_MESSAGING_EDITOR,
			Constants::SCRIPT_DATA_VAR_MESSAGING_EDITOR,
			MessagingEditorPayload::build()
		);
	}

	/**
	 * Enqueue checkout CSS and enabled product/cart messaging assets.
	 *
	 * @return void
	 */
	public static function enqueue_front_styles(): void {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			wp_enqueue_style( Constants::STYLE_HANDLE_MESSAGING, plugins_url( 'assets/css/spart.css', Plugin::plugin_file() ), array(), Plugin::VERSION );
			return;
		}
		if ( ( function_exists( 'is_product' ) && is_product() && ProductPageMessaging::is_enabled() )
			|| ( function_exists( 'is_cart' ) && is_cart() && CartMessaging::is_enabled() ) ) {
			StorefrontDialog::enqueue();
		}
	}
}
