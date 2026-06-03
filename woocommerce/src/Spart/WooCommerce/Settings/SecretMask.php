<?php
/**
 * Partial-reveal masking helper for stored secrets.
 *
 * @package Spart\WooCommerce\Settings
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Settings;

/**
 * Renders a fixed-shape sentinel for stored API keys / webhook secrets
 * shown in the admin settings UI. Shape is `XXXX••••••••XXXX` for
 * values long enough to partial-reveal, otherwise a row of bullets.
 *
 * The sentinel is rendered back into the form's `value` attribute, so
 * a merchant who leaves the field untouched POSTs the sentinel verbatim
 * — the gateway's `validate_password_field()` detects this and keeps
 * the existing stored value rather than clobbering it with the mask.
 */
final class SecretMask {

	/** Bullet character U+2022 (3 bytes UTF-8). */
	public const BULLET = "\xe2\x80\xa2";

	/** Number of bullets in the masked middle. Fixed so stored length is not disclosed. */
	public const BULLET_COUNT = 8;

	/** Number of visible chars from the start of the secret. */
	public const VISIBLE_PREFIX = 4;

	/** Number of visible chars from the end of the secret. */
	public const VISIBLE_SUFFIX = 4;

	/**
	 * Minimum stored length for partial reveal. Below this threshold the
	 * value is fully masked so a short secret cannot be reconstructed from
	 * the visible prefix + suffix alone (the hidden middle would be < 4 chars).
	 */
	public const MIN_PARTIAL_LEN = self::VISIBLE_PREFIX + self::VISIBLE_SUFFIX + 4;

	/**
	 * Render the partial-reveal sentinel for a stored secret.
	 *
	 * @param string $stored Raw stored secret value.
	 * @return string Masked sentinel suitable for rendering in the form's `value` attribute.
	 */
	public static function mask( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		$bullets = str_repeat( self::BULLET, self::BULLET_COUNT );

		// Byte-based length/substring is sufficient: Spart secrets are
		// opaque ASCII tokens (Stripe-style `sk_…`, `whsec_…`) so the
		// byte count equals the character count. Avoiding mb_*
		// keeps the plugin functional on WordPress hosts that ship
		// PHP without the (highly-recommended but optional) mbstring
		// extension.
		if ( strlen( $stored ) < self::MIN_PARTIAL_LEN ) {
			return $bullets;
		}

		return substr( $stored, 0, self::VISIBLE_PREFIX )
			. $bullets
			. substr( $stored, -self::VISIBLE_SUFFIX );
	}
}
