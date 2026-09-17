<?php
/**
 * Shared product/cart explainer.
 *
 * @package Spart\WooCommerce\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Messaging;

use Spart\WooCommerce\Constants;
use Spart\WooCommerce\Plugin;

/** Shared storefront dialog. */
final class StorefrontDialog {

	/** Register the shared footer. */
	public static function register(): void {
		add_action( 'wp_footer', array( self::class, 'render_footer' ) );
	}

	/** Exclude checkout and admin screens. */
	public static function enqueue(): void {
		if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || is_admin() ) {
			return;
		}
		wp_enqueue_style( Constants::STYLE_HANDLE_MESSAGING, plugins_url( 'assets/css/spart.css', Plugin::plugin_file() ), array(), Plugin::VERSION );
		wp_enqueue_script( 'spart-storefront-dialog', plugins_url( 'assets/js/storefront-dialog.js', Plugin::plugin_file() ), array(), Plugin::VERSION, true );
	}

	/** Keep the dialog outside replaceable cart markup. */
	public static function render_footer(): void {
		if ( wp_script_is( 'spart-storefront-dialog', 'enqueued' ) && ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the renderer.
		}
	}

	/** Build the closed dialog. */
	public static function render(): string {
		$steps    = array(
			array( 'person-add', 'SPART_DIALOG_INVITE', 'SPART_DIALOG_INVITE_BODY' ),
			array( 'people-check', 'SPART_DIALOG_PAY', 'SPART_DIALOG_PAY_BODY' ),
			array( 'card-lock', 'SPART_DIALOG_UNLOCK', 'SPART_DIALOG_UNLOCK_BODY' ),
		);
		$benefits = array(
			'people'    => 'SPART_DIALOG_NO_UPFRONT',
			'card'      => 'SPART_DIALOG_SECURE',
			'banknotes' => 'SPART_DIALOG_NO_REPAYMENTS',
			'box'       => 'SPART_DIALOG_ONE_ORDER',
		);
		$html     = '<dialog id="spart-explainer" class="spart-explainer" role="dialog" aria-modal="true" aria-labelledby="spart-explainer-title" tabindex="-1">'
			. '<button type="button" class="spart-explainer__close" data-spart-dialog-close aria-label="' . esc_attr__( 'SPART_DIALOG_CLOSE', 'spart-woocommerce' ) . '">' . self::icon( 'close' ) . '</button>'
			. '<img class="spart-wordmark" src="' . esc_url( plugins_url( 'assets/images/spart-logo.svg', Plugin::plugin_file() ) ) . '" alt="SPART!" width="1044" height="205">'
			. '<h2 id="spart-explainer-title">' . self::text( 'SPART_DIALOG_TITLE_1' ) . '<br>' . self::text( 'SPART_DIALOG_TITLE_2' ) . '</h2>'
			. '<p class="spart-explainer__intro">' . self::text( 'SPART_DIALOG_INTRO' ) . '</p>'
			. '<h3>' . self::text( 'SPART_DIALOG_HOW' ) . '</h3><ol class="spart-explainer__steps">';
		foreach ( $steps as $index => $step ) {
			$body = self::text( $step[2] );
			if ( 2 === $index ) {
				$body = sprintf( $body, '<strong>' . self::text( 'SPART_DIALOG_NO_CHARGE' ) . '</strong>' );
			}
			$html .= '<li><span class="spart-explainer__number" aria-hidden="true">' . ( $index + 1 ) . '</span>'
				. self::icon( $step[0] ) . '<div><strong>' . self::text( $step[1] ) . '</strong><p>' . $body . '</p></div></li>';
		}
		$html .= '</ol><section class="spart-explainer__benefits"><h3>' . self::text( 'SPART_DIALOG_WHY' ) . '</h3><ul>';
		foreach ( $benefits as $icon => $code ) {
			$html .= '<li>' . self::icon( $icon ) . self::text( $code ) . '</li>';
		}
		return $html . '</ul></section><p class="spart-explainer__footer">' . self::text( 'SPART_DIALOG_FOOTER' ) . '</p></dialog>';
	}

	/**
	 * @param string $code Symbolic gettext key.
	 */
	private static function text( string $code ): string {
		return esc_html__( $code, 'spart-woocommerce' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	/**
	 * Render a decorative icon.
	 *
	 * @param string $name Icon name.
	 */
	public static function icon( string $name ): string {
		$paths = array(
			'person-add'   => '<circle cx="12" cy="8" r="5"/><path d="M2 29v-3c0-11 20-11 20 0v3M26 12v12M20 18h12"/>',
			'people-check' => '<circle cx="10" cy="8" r="4"/><circle cx="23" cy="11" r="4"/><path d="M1 25v-2c0-9 18-9 18 0M19 18c5-3 11 0 11 5"/><circle class="spart-icon__accent" cx="24" cy="25" r="6"/><path class="spart-icon__accent" d="m21 25 2 2 4-4"/>',
			'card-lock'    => '<path d="M19 25H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h23a2 2 0 0 1 2 2v10M2 11h27M6 17h3M6 21h6"/><rect class="spart-icon__accent" x="21" y="22" width="10" height="9" rx="1"/><path class="spart-icon__accent" d="M23 22v-4a3 3 0 0 1 6 0v4M26 26v2"/>',
			'people'       => '<circle cx="12" cy="9" r="4"/><path d="M3 28v-4c0-8 18-8 18 0v4M23 7a4 4 0 0 1 0 8M24 20c4 0 6 2 6 6"/>',
			'card'         => '<rect x="3" y="6" width="26" height="20" rx="3"/><path d="M3 13h26M8 21h5M18 21h3"/>',
			'banknotes'    => '<rect x="2" y="3" width="18" height="12" rx="2"/><rect x="12" y="17" width="18" height="12" rx="2"/><path d="M11 6v6M13 7H9v2h4v2H9M21 20v6M23 21h-4v2h4v2h-4M5 19v6h4M27 13V7h-4"/>',
			'box'          => '<path d="m16 3 12 6v14l-12 6L4 23V9l12-6Zm0 12v14M4 9l12 6 12-6M10 6l12 6v7"/>',
			'help'         => '<circle cx="16" cy="16" r="12"/><path d="M12 12a4 4 0 1 1 6 3.5c-2 1-2 2-2 3M16 23v.2"/>',
			'close'        => '<path d="m9 9 14 14M23 9 9 23"/>',
		);
		return '<svg class="spart-icon" viewBox="0 0 32 32" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
