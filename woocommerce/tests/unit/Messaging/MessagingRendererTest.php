<?php
/**
 * Unit tests for the shared messaging renderer.
 *
 * @package Spart\WooCommerce\Tests\Unit\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Messaging;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Messaging\MessagingRenderer;

final class MessagingRendererTest extends TestCase {

	public function test_render_produces_two_line_div_with_bem_modifier(): void {
		$html = MessagingRenderer::render( 'cart', 'Line A', 'Line B' );

		$this->assertStringContainsString( 'spart-messaging spart-messaging--cart', $html );
		$this->assertStringContainsString( '<p class="spart-messaging__line">Line A</p>', $html );
		$this->assertStringContainsString( '<p class="spart-messaging__line">Line B</p>', $html );
	}

	public function test_render_supports_product_context(): void {
		$html = MessagingRenderer::render( 'product', 'Foo', 'Bar' );

		$this->assertStringContainsString( 'spart-messaging--product', $html );
		$this->assertStringNotContainsString( 'spart-messaging--cart', $html );
	}

	public function test_render_omits_aria_live_when_not_provided(): void {
		$html = MessagingRenderer::render( 'product', 'L1', 'L2' );

		$this->assertStringNotContainsString( 'aria-live', $html );
	}

	public function test_render_includes_aria_live_when_provided(): void {
		$html = MessagingRenderer::render( 'cart', 'L1', 'L2', 'polite' );

		$this->assertStringContainsString( 'aria-live="polite"', $html );
	}

	public function test_render_returns_a_single_root_div(): void {
		$html = MessagingRenderer::render( 'cart', 'X', 'Y' );

		// One opening <div ...> tag and one closing </div>.
		$this->assertSame( 1, substr_count( $html, '<div' ) );
		$this->assertSame( 1, substr_count( $html, '</div>' ) );
	}

	public function test_render_strips_invalid_class_characters_from_context(): void {
		$html = MessagingRenderer::render( '"><script>alert(1)</script>', 'L1', 'L2' );

		// No raw script tag should be emitted, and no encoded attribute
		// break-out sequence should leak into the class attribute either.
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '&quot;&gt;', $html );

		// The class attribute value must contain only HTML-class-valid
		// characters [A-Za-z0-9_-\s] between its opening and closing
		// quotes — i.e. attribute injection past the BEM modifier is
		// impossible.
		$this->assertMatchesRegularExpression(
			'/class="spart-messaging spart-messaging--[A-Za-z0-9_-]+"/',
			$html
		);
	}

	public function test_render_falls_back_to_cart_when_context_is_all_invalid_characters(): void {
		$html = MessagingRenderer::render( '!!!', 'L1', 'L2' );

		$this->assertStringContainsString( 'spart-messaging--cart', $html );
	}

	/**
	 * @dataProvider provide_allowed_aria_live_values
	 */
	public function test_render_emits_aria_live_for_each_allowed_value( string $value ): void {
		$html = MessagingRenderer::render( 'cart', 'L1', 'L2', $value );

		$this->assertStringContainsString( 'aria-live="' . $value . '"', $html );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_allowed_aria_live_values(): array {
		return array(
			'off'       => array( 'off' ),
			'polite'    => array( 'polite' ),
			'assertive' => array( 'assertive' ),
		);
	}

	public function test_render_omits_aria_live_when_value_not_in_allowlist(): void {
		$html = MessagingRenderer::render( 'cart', 'L1', 'L2', 'rude' );

		$this->assertStringNotContainsString( 'aria-live', $html );
	}

	public function test_render_omits_aria_live_when_caller_attempts_attribute_injection(): void {
		$html = MessagingRenderer::render( 'cart', 'L1', 'L2', '"><script>alert(1)</script>' );

		// Hostile value is not in the allowlist, so aria-live is omitted
		// entirely — no escaped variant leaks into the output either.
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'aria-live', $html );
	}
}
