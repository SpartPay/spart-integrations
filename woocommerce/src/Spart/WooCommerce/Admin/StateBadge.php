<?php
/**
 * Renders state badges for webhook delivery rows.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

/**
 * Renders a CSS-styled state badge for a webhook delivery state, plus the
 * (once-per-request) inline <style> block.
 */
final class StateBadge {

	private const ALLOWED = array( 'received', 'applied', 'skipped', 'errored' );

	/**
	 * Render the inline HTML markup for a single state badge.
	 *
	 * Unrecognized states fall back to 'received'. The returned string is
	 * already escaped (sanitize_html_class + esc_html on the visible label);
	 * callers should echo it directly with a phpcs:ignore annotation for
	 * WordPress.Security.EscapeOutput.OutputNotEscaped.
	 *
	 * @param string $state Delivery state from the DB.
	 * @return string Escaped HTML markup.
	 */
	public static function markup( string $state ): string {
		$safe = in_array( $state, self::ALLOWED, true ) ? $state : 'received';

		return sprintf(
			'<span class="spart-state-badge %s">%s</span>',
			\sanitize_html_class( 'spart-state-' . $safe ),
			\esc_html( $safe )
		);
	}

	/**
	 * Tracks whether the inline style block has already been emitted in this
	 * request. Promoted from a method-local `static` so tests can reset it
	 * between cases via reset_style_block_for_tests().
	 *
	 * @var bool
	 */
	private static bool $style_block_emitted = false;

	/**
	 * Reset the per-request style-block emission flag. Test-only helper.
	 *
	 * @internal
	 */
	public static function reset_style_block_for_tests(): void {
		self::$style_block_emitted = false;
	}

	/**
	 * Return the inline <style> block for state badges (idempotent).
	 *
	 * Returns the markup on the first call and an empty string on every
	 * subsequent call within the same request, so multiple surfaces
	 * (list table, detail view, meta box) can call this defensively.
	 *
	 * The returned string is a static HTML literal — callers should echo
	 * it directly with a phpcs:ignore annotation for
	 * WordPress.Security.EscapeOutput.OutputNotEscaped.
	 *
	 * @return string Inline <style> block, or '' if already emitted this request.
	 */
	public static function style_block(): string {
		if ( self::$style_block_emitted ) {
			return '';
		}
		self::$style_block_emitted = true;

		return '<style>'
			. '.spart-state-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }'
			. '.spart-state-received { background: #e5f5fa; color: #007cba; }'
			. '.spart-state-applied  { background: #edfaef; color: #008a20; }'
			. '.spart-state-skipped  { background: #f0f0f1; color: #50575e; }'
			. '.spart-state-errored  { background: #fcf0f1; color: #b32d2e; }'
			. '</style>';
	}
}
