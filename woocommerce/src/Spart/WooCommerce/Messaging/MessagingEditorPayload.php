<?php
/**
 * Builder for the wp_localize_script payload shipped to the messaging
 * block editor.
 *
 * @package Spart\WooCommerce\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Messaging;

use Spart\WooCommerce\Constants;
use Spart\WooCommerce\I18n\Strings;

/**
 * Builds the `spartMessaging` payload that ships preview text to the
 * block editor. Running the SCREAMING_SNAKE codes through `__()` (with
 * the gettext filter active) gives the editor the same canonical (or
 * translator-supplied) text that the storefront renders.
 *
 * The payload also includes the SCREAMING_SNAKE codes themselves so the
 * JS can fall back to a stable identifier if the localised payload is
 * stripped (very-old WP, cache plugins that mangle script data, etc.).
 *
 * Shape:
 *   array{
 *     codes:    array{productLine1: string, productLine2: string, cartLine1: string, cartLine2: string},
 *     previews: array{productLine1: string, productLine2: string, cartLine1: string, cartLine2: string},
 *   }
 */
final class MessagingEditorPayload {

	/**
	 * Build the editor payload.
	 *
	 * @return array{
	 *     codes: array<string, string>,
	 *     previews: array<string, string>,
	 * }
	 */
	public static function build(): array {
		return array(
			'codes'    => array(
				'productLine1' => Constants::MSG_CODE_PRODUCT_LINE_1,
				'productLine2' => Constants::MSG_CODE_PRODUCT_LINE_2,
				'cartLine1'    => Constants::MSG_CODE_CART_LINE_1,
				'cartLine2'    => Constants::MSG_CODE_CART_LINE_2,
			),
			'previews' => array(
				'productLine1' => \__( Constants::MSG_CODE_PRODUCT_LINE_1, Strings::TEXT_DOMAIN ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
				'productLine2' => \__( Constants::MSG_CODE_PRODUCT_LINE_2, Strings::TEXT_DOMAIN ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
				'cartLine1'    => \__( Constants::MSG_CODE_CART_LINE_1, Strings::TEXT_DOMAIN ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
				'cartLine2'    => \__( Constants::MSG_CODE_CART_LINE_2, Strings::TEXT_DOMAIN ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
			),
		);
	}
}
