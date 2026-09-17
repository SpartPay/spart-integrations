<?php
/**
 * Unit tests for the messaging block editor payload builder.
 *
 * @package Spart\WooCommerce\Tests\Unit\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Messaging;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Constants;
use Spart\WooCommerce\I18n\Strings;
use Spart\WooCommerce\Messaging\MessagingEditorPayload;

/**
 * The unit-test bootstrap defines a pass-through `__()` global at file scope,
 * which is loaded before Patchwork — so Brain\Monkey cannot redefine it here.
 * Instead, each preview assertion calls the same `__()` the production code
 * calls and compares against `MessagingEditorPayload::build()`, which proves
 * the payload routes through `__()` without depending on what `__()` returns
 * in any given environment.
 *
 * Translation behaviour (codes → canonical English, .mo overrides, etc.) is
 * exercised by `GettextFilterTest` and the wp-env integration tier.
 */
final class MessagingEditorPayloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		\Spart\WooCommerce\Plugin::set_plugin_file_for_tests( '/plugin/spart-woocommerce.php' );
		\Brain\Monkey\Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://shop.example/' . $path );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_editor_payload_includes_official_artwork_urls(): void {
		\Spart\WooCommerce\Plugin::set_plugin_file_for_tests( '/plugin/spart-woocommerce.php' );
		\Brain\Monkey\Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://shop.example/' . $path );
		$payload = MessagingEditorPayload::build();
		$this->assertSame( 'https://shop.example/assets/images/spart-logo.svg', $payload['logoUrl'] ?? null );
		$this->assertSame( 'https://shop.example/assets/images/spart-symbol.svg', $payload['symbolUrl'] ?? null );
	}

	public function test_build_returns_codes_and_previews_arrays(): void {
		$payload = MessagingEditorPayload::build();

		$this->assertArrayHasKey( 'codes', $payload );
		$this->assertArrayHasKey( 'previews', $payload );
	}

	public function test_codes_array_carries_the_four_screaming_snake_codes(): void {
		$payload = MessagingEditorPayload::build();

		$this->assertSame(
			array(
				'productLine1' => Constants::MSG_CODE_PRODUCT_LINE_1,
				'productLine2' => Constants::MSG_CODE_PRODUCT_LINE_2,
				'cartLine1'    => Constants::MSG_CODE_CART_LINE_1,
				'cartLine2'    => Constants::MSG_CODE_CART_LINE_2,
			),
			$payload['codes']
		);
	}

	public function test_previews_array_routes_codes_through_translator(): void {
		$payload = MessagingEditorPayload::build();

		$this->assertSame(
			\__( Constants::MSG_CODE_PRODUCT_LINE_1, Strings::TEXT_DOMAIN ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
			$payload['previews']['productLine1']
		);
		$this->assertSame(
			\__( Constants::MSG_CODE_PRODUCT_LINE_2, Strings::TEXT_DOMAIN ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
			$payload['previews']['productLine2']
		);
		$this->assertSame(
			\__( Constants::MSG_CODE_CART_LINE_1, Strings::TEXT_DOMAIN ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
			$payload['previews']['cartLine1']
		);
		$this->assertSame(
			\__( Constants::MSG_CODE_CART_LINE_2, Strings::TEXT_DOMAIN ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.NonSingularStringLiteralText
			$payload['previews']['cartLine2']
		);
	}

	public function test_previews_array_carries_string_values(): void {
		$payload = MessagingEditorPayload::build();

		$this->assertIsString( $payload['previews']['productLine1'] );
		$this->assertIsString( $payload['previews']['productLine2'] );
		$this->assertIsString( $payload['previews']['cartLine1'] );
		$this->assertIsString( $payload['previews']['cartLine2'] );
		$this->assertNotEmpty( $payload['previews']['productLine1'] );
		$this->assertNotEmpty( $payload['previews']['productLine2'] );
		$this->assertNotEmpty( $payload['previews']['cartLine1'] );
		$this->assertNotEmpty( $payload['previews']['cartLine2'] );
	}
}
