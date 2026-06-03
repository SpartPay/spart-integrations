<?php
/**
 * Product-page messaging renderer.
 *
 * @package Spart\WooCommerce\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Messaging;

use Spart\WooCommerce\Constants;
use Spart\WooCommerce\I18n\Strings;

/**
 * Renders the Spart "pay in parts" message on single product pages.
 *
 * Hooked at `woocommerce_single_product_summary` (priority 11, just
 * after the product price) when the merchant has enabled product-page
 * messaging in the plugin settings.
 */
final class ProductPageMessaging {

	/**
	 * Register an init-time closure that reads the toggle and (if on) wires
	 * the WooCommerce action. Reading the option inside an init closure
	 * (rather than at Plugin::boot time) ensures option filters registered
	 * during plugins_loaded — and WC's own init_settings defaulting — have
	 * already run when the decision is made.
	 *
	 * If `init` has already fired by the time register() is called (e.g. in
	 * integration tests that re-boot the plugin post-init), the closure is
	 * invoked inline rather than scheduled.
	 */
	public static function register(): void {
		$callback = static function (): void {
			if ( ! self::is_enabled() ) {
				return;
			}

			add_action( 'woocommerce_single_product_summary', array( self::class, 'render_action' ), 11 );
		};

		if ( did_action( 'init' ) ) {
			$callback();
			return;
		}

		add_action( 'init', $callback );
	}

	/**
	 * Echo the messaging HTML (WordPress action callback).
	 */
	public static function render_action(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::render();
	}

	/**
	 * Build and return the messaging HTML string.
	 *
	 * @return string HTML markup.
	 */
	public static function render(): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$line1 = esc_html__( Constants::MSG_CODE_PRODUCT_LINE_1, Strings::TEXT_DOMAIN ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
		$line2 = esc_html__( Constants::MSG_CODE_PRODUCT_LINE_2, Strings::TEXT_DOMAIN ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText

		return MessagingRenderer::render( 'product', $line1, $line2 );
	}

	/**
	 * Whether product-page messaging is enabled in settings.
	 */
	public static function is_enabled(): bool {
		$options = (array) get_option( Constants::OPTION_KEY, array() );

		return 'yes' === ( $options[ Constants::TOGGLE_MESSAGING_PRODUCT ] ?? 'no' );
	}
}
