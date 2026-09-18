<?php
/**
 * Unit tests for the shared messaging renderer.
 *
 * @package Spart\WooCommerce\Tests\Unit\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Messaging;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Spart\WooCommerce\Messaging\MessagingRenderer;

final class MessagingRendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\Spart\WooCommerce\Plugin::set_plugin_file_for_tests( '/plugin/spart-woocommerce.php' );
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://shop.example/' . $path );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_product_has_branded_help_button_and_emphasized_headline(): void {
		$html = MessagingRenderer::render( 'product', 'Share and split the payment.', 'No upfront payment.' );
		$this->assertStringContainsString( '<strong>Share and split the payment.</strong>', $html );
		$this->assertStringContainsString( 'spart-symbol.svg', $html );
		$this->assertStringContainsString( 'spart-logo.svg', $html );
		$this->assertStringContainsString( 'type="button"', $html );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
		$this->assertStringContainsString( 'aria-controls="spart-explainer"', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_render_produces_two_line_div_with_bem_modifier(): void {
		$html = MessagingRenderer::render( 'cart', 'Line A', 'Line B' );

		$this->assertStringContainsString( 'spart-messaging spart-messaging--cart', $html );
		$this->assertStringContainsString( '<p class="spart-messaging__line"><strong>Line A</strong></p>', $html );
		$this->assertStringContainsString( '<p class="spart-messaging__line">Line B</p>', $html );
	}

	public function test_empty_subtitle_has_no_empty_paragraph(): void {
		$html = MessagingRenderer::render( 'cart', 'Share your purchase.', '' );
		$this->assertSame( 1, substr_count( $html, '<p ' ) );
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

		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$this->assertSame( 1, $document->getElementsByTagName( 'body' )->item( 0 )->childNodes->length );
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
