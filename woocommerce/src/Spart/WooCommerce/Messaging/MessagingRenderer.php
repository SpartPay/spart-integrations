<?php
/**
 * Shared HTML renderer for the messaging blocks / classic hooks.
 *
 * @package Spart\WooCommerce\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Messaging;

/**
 * Builds the two-line messaging shell used by both cart and product
 * messaging surfaces. Centralising the markup keeps the BEM class
 * structure consistent across surfaces and lets future schema changes
 * (e.g. an icon slot, a CTA link) ship in one place.
 *
 * Callers are responsible for pre-escaping the line strings — pass the
 * output of `esc_html__()` (or equivalent) into `$line1` / `$line2`.
 * The renderer treats them as trusted HTML fragments.
 *
 * The `$context` and `$aria_live` parameters are constrained by the
 * renderer itself (allowlist / class sanitisation) and additionally
 * passed through `esc_attr()`, so callers may pass plain dynamic
 * values without pre-escaping.
 */
final class MessagingRenderer {

	/**
	 * Valid `aria-live` attribute values per the WAI-ARIA spec.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_ARIA_LIVE = array( 'off', 'polite', 'assertive' );

	/**
	 * Fallback BEM modifier used when `$context` sanitises to an empty
	 * string (e.g. caller passed only invalid characters).
	 */
	private const CONTEXT_FALLBACK = 'cart';

	/**
	 * Render the two-line messaging shell.
	 *
	 * @param string      $context   BEM modifier — typically `'cart'` or
	 *                               `'product'`. Run through
	 *                               `sanitize_html_class()` so only chars
	 *                               valid in an HTML class attribute
	 *                               survive; falls back to `'cart'` if the
	 *                               sanitised result is empty.
	 * @param string      $line1     Pre-escaped first line of copy.
	 * @param string      $line2     Pre-escaped second line of copy.
	 * @param string|null $aria_live Optional aria-live value. Must be one of
	 *                               `off`, `polite`, or `assertive` (the
	 *                               WAI-ARIA-defined values). Any other
	 *                               value — or `null` (default) — causes
	 *                               the attribute to be omitted entirely.
	 *
	 * @return string HTML fragment.
	 */
	public static function render( string $context, string $line1, string $line2, ?string $aria_live = null ): string {
		$context_attr = esc_attr( sanitize_html_class( $context, self::CONTEXT_FALLBACK ) );

		$aria_attr = '';
		if ( null !== $aria_live && in_array( $aria_live, self::ALLOWED_ARIA_LIVE, true ) ) {
			$aria_attr = ' aria-live="' . $aria_live . '"';
		}

		return '<div class="spart-messaging spart-messaging--' . $context_attr . '"' . $aria_attr . '>'
			. '<p class="spart-messaging__line">' . $line1 . '</p>'
			. '<p class="spart-messaging__line">' . $line2 . '</p>'
			. '</div>';
	}
}
