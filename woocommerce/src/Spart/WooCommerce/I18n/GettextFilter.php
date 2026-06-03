<?php
/**
 * Gettext filter for symbolic-code substitution.
 *
 * @package Spart\WooCommerce\I18n
 */

declare(strict_types=1);

namespace Spart\WooCommerce\I18n;

use Spart\WooCommerce\Plugin;

/**
 * WordPress gettext filter that substitutes English display copy for
 * symbolic codes when no real translation has been loaded.
 *
 * This filter is the runtime half of the symbolic-codes i18n strategy:
 * source files write `__('SPART_MSG_FOO', 'spart-woocommerce')`; if a
 * locale-specific .mo file is loaded that translates SPART_MSG_FOO,
 * WordPress returns the translation unchanged. Otherwise WordPress
 * returns the code itself (since no translation exists), and this
 * filter swaps in the English copy from Strings::CODES.
 *
 * The filter ALSO emits a one-shot diagnostic warning whenever a known
 * SPART_* code has been translated by a third party (i.e. neither the
 * untranslated code nor the canonical English we ship). This makes
 * unexpected translation overrides visible in the WC log without
 * changing user-facing output — translators supplying authoritative
 * per-locale text continue to win, as expected.
 */
final class GettextFilter {

	/**
	 * Per-request memoisation of which codes have already emitted a
	 * diagnostic warning. Keyed by the symbolic code (the `$text` arg).
	 *
	 * @var array<string, true>
	 */
	private static array $warned = array();

	/**
	 * Registers the gettext filter with WordPress.
	 *
	 * Registered at PHP_INT_MAX - 100 so other gettext filters that respect
	 * reasonable priority levels have already executed by the time we see
	 * the translated value. The headroom of 100 leaves space for callers
	 * that genuinely need to win against us (translators supplying
	 * authoritative per-locale text via the standard add_filter API).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'gettext', array( self::class, 'filter' ), PHP_INT_MAX - 100, 3 );
	}

	/**
	 * Substitutes English display copy for untranslated symbolic codes
	 * and emits a one-shot diagnostic when an unexpected third-party
	 * translation is observed.
	 *
	 * Semantics:
	 *  - $translation === $text                   → no upstream translation; substitute canonical English.
	 *  - $translation === Strings::CODES[$text]   → another filter already substituted; pass through.
	 *  - $translation !== $text, !== canonical    → third party translated; warn (once) AND pass through
	 *                                                so legitimate per-locale translations keep working.
	 *
	 * @param string $translation The (already-translated, or untranslated) string returned by core gettext.
	 * @param string $text        The original string passed to __().
	 * @param string $domain      The text domain.
	 *
	 * @return string
	 */
	public static function filter( string $translation, string $text, string $domain ): string {
		if ( Strings::TEXT_DOMAIN !== $domain ) {
			return $translation;
		}

		$canonical = Strings::CODES[ $text ] ?? null;
		if ( null === $canonical ) {
			return $translation;
		}

		if ( $translation === $text ) {
			return $canonical;
		}

		if ( $translation !== $canonical && ! isset( self::$warned[ $text ] ) ) {
			self::$warned[ $text ] = true;
			Plugin::logger()->warning(
				'spart.i18n.unexpected_translation',
				array(
					'code'             => $text,
					'expected'         => $canonical,
					'observed_excerpt' => substr( $translation, 0, 80 ),
					'domain'           => $domain,
				)
			);
		}

		return $translation;
	}

	/**
	 * Reset the per-request warned cache. Test-only seam.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_warned_for_tests(): void {
		self::$warned = array();
	}
}
