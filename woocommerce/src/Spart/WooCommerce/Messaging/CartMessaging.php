<?php
/**
 * Cart-page messaging renderer.
 *
 * @package Spart\WooCommerce\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Messaging;

use Spart\WooCommerce\Constants;
use Spart\WooCommerce\I18n\Strings;

/**
 * Renders the Spart "pay in parts" message on the WooCommerce cart page.
 *
 * Hooked at `woocommerce_before_cart_totals` (priority 10, immediately
 * before the cart totals block) when the merchant has enabled cart-page
 * messaging in the plugin settings.
 */
final class CartMessaging {

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

			add_action( 'woocommerce_before_cart_totals', array( self::class, 'render_action' ), 10 );
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

		$line1 = esc_html__( Constants::MSG_CODE_CART_LINE_1, Strings::TEXT_DOMAIN ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
		$line2 = esc_html__( Constants::MSG_CODE_CART_LINE_2, Strings::TEXT_DOMAIN ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText

		return MessagingRenderer::render( 'cart', $line1, $line2, 'polite' );
	}

	/**
	 * Whether cart-page messaging is enabled in settings.
	 */
	public static function is_enabled(): bool {
		$options = (array) get_option( Constants::OPTION_KEY, array() );

		return 'yes' === ( $options[ Constants::TOGGLE_MESSAGING_CART ] ?? 'no' );
	}
}
