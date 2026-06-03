<?php
/**
 * Unit tests for SecretMask.
 *
 * @package Spart\WooCommerce\Tests\Unit\Settings
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Settings\SecretMask;

/**
 * Tests for SecretMask::mask().
 */
final class SecretMaskTest extends TestCase {

	/**
	 * Empty stored value renders empty (so the form input shows nothing
	 * and the merchant has not yet entered an API key).
	 */
	public function test_mask_returns_empty_string_for_empty_input(): void {
		$this->assertSame( '', SecretMask::mask( '' ) );
	}

	/**
	 * Strings shorter than the partial-reveal minimum (12 chars) are
	 * fully masked, never partially revealed — leaking a 4-char prefix
	 * of an 8-char secret would reveal half the secret.
	 */
	public function test_mask_fully_masks_short_string(): void {
		$masked = SecretMask::mask( 'short' );
		$this->assertSame( str_repeat( SecretMask::BULLET, SecretMask::BULLET_COUNT ), $masked );
	}

	/**
	 * Boundary: 11 chars is still below the partial threshold.
	 */
	public function test_mask_fully_masks_at_boundary_below_partial(): void {
		$masked = SecretMask::mask( '12345678901' );
		$this->assertSame( str_repeat( SecretMask::BULLET, SecretMask::BULLET_COUNT ), $masked );
	}

	/**
	 * Boundary: 12 chars is the minimum length that allows partial
	 * reveal (4 prefix + 4 suffix + 4 hidden middle).
	 */
	public function test_mask_partial_reveals_at_min_partial_length(): void {
		$masked = SecretMask::mask( '123456789012' );
		$this->assertSame( '1234' . str_repeat( SecretMask::BULLET, SecretMask::BULLET_COUNT ) . '9012', $masked );
	}

	/**
	 * Typical Stripe/Spart-style API key — partial reveal shows the
	 * obvious environment prefix and the last four chars so merchants
	 * can audit which key is stored.
	 */
	public function test_mask_partial_reveals_typical_api_key(): void {
		$masked = SecretMask::mask( 'sk_live_abcdef1234567890' );
		$this->assertSame( 'sk_l' . str_repeat( SecretMask::BULLET, SecretMask::BULLET_COUNT ) . '7890', $masked );
	}

	/**
	 * Stored length must not be inferable from the rendered sentinel
	 * (both a 12-char input and a 64-char input render the same number
	 * of visible glyphs).
	 */
	public function test_mask_does_not_disclose_stored_length(): void {
		$short = SecretMask::mask( '123456789012' );
		$long  = SecretMask::mask( str_repeat( 'A', 64 ) );
		$this->assertSame( strlen( $short ), strlen( $long ) );
		$this->assertSame(
			SecretMask::VISIBLE_PREFIX + ( SecretMask::BULLET_COUNT * strlen( SecretMask::BULLET ) ) + SecretMask::VISIBLE_SUFFIX,
			strlen( $short )
		);
	}

	/**
	 * BULLET is the exact three-byte UTF-8 encoding of U+2022 BULLET.
	 * WC_Gateway_Spart::validate_password_field() relies on str_contains()
	 * with this constant for mask-edit rejection; any change to the byte
	 * sequence would silently break that guard.
	 */
	public function test_bullet_constant_is_utf8_u2022(): void {
		$this->assertSame( "\xe2\x80\xa2", SecretMask::BULLET );
		$this->assertSame( 3, strlen( SecretMask::BULLET ) );
	}

	/**
	 * The middle segment of a partial mask is always bullet glyphs; no
	 * character from the stored secret appears between the visible prefix
	 * and visible suffix.
	 */
	public function test_mask_never_leaks_middle_of_secret(): void {
		$secret = 'sk_live_super_secret_value_1234';
		$masked = SecretMask::mask( $secret );

		$prefix = substr( $masked, 0, SecretMask::VISIBLE_PREFIX );
		$suffix = substr( $masked, -SecretMask::VISIBLE_SUFFIX );
		$middle = substr( $masked, SecretMask::VISIBLE_PREFIX, -SecretMask::VISIBLE_SUFFIX );

		$this->assertSame( str_repeat( SecretMask::BULLET, SecretMask::BULLET_COUNT ), $middle );
		$this->assertStringNotContainsString( 'secret', $prefix . $middle . $suffix );
	}
}
