<?php
/**
 * Unit tests for Gateway\Blocks\PaymentMethodDataBuilder.
 *
 * @package Spart\WooCommerce\Tests\Unit\Gateway\Blocks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Gateway\Blocks;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Gateway\Blocks\PaymentMethodDataBuilder;

/**
 * @covers \Spart\WooCommerce\Gateway\Blocks\PaymentMethodDataBuilder
 */
final class PaymentMethodDataBuilderTest extends TestCase {

	private const ASSETS_URL = 'https://example.com/wp-content/plugins/spart-woocommerce/assets/';

	public function test_build_with_empty_settings_returns_empty_strings_for_text_keys(): void {
		$payload = ( new PaymentMethodDataBuilder() )->build( array(), self::ASSETS_URL );

		$this->assertSame( '', $payload['title'] );
		$this->assertSame( '', $payload['description'] );
		$this->assertSame(
			self::ASSETS_URL . 'images/spart-logo.svg',
			$payload['logoUrl']
		);
		$this->assertSame( array( 'products' ), $payload['supports'] );
	}

	public function test_build_threads_merchant_overrides_verbatim(): void {
		$settings = array(
			'title'       => 'Custom Title',
			'description' => 'Custom Desc',
		);

		$payload = ( new PaymentMethodDataBuilder() )->build( $settings, self::ASSETS_URL );

		$this->assertSame( 'Custom Title', $payload['title'] );
		$this->assertSame( 'Custom Desc', $payload['description'] );
	}

	public function test_build_does_not_decode_html_entities(): void {
		$settings = array(
			'title'       => 'A &amp; B',
			'description' => 'X &lt; Y',
		);

		$payload = ( new PaymentMethodDataBuilder() )->build( $settings, self::ASSETS_URL );

		$this->assertSame( 'A &amp; B', $payload['title'] );
		$this->assertSame( 'X &lt; Y', $payload['description'] );
	}

	public function test_build_composes_logo_url_from_assets_url(): void {
		$payload = ( new PaymentMethodDataBuilder() )->build( array(), self::ASSETS_URL );

		$this->assertSame(
			'https://example.com/wp-content/plugins/spart-woocommerce/assets/images/spart-logo.svg',
			$payload['logoUrl']
		);
	}

	public function test_build_supports_is_products_only_regardless_of_settings(): void {
		$settings = array(
			'supports' => array( 'subscriptions', 'pre-orders' ),
		);

		$payload = ( new PaymentMethodDataBuilder() )->build( $settings, self::ASSETS_URL );

		$this->assertSame( array( 'products' ), $payload['supports'] );
	}

	public function test_build_normalises_assets_url_missing_trailing_slash(): void {
		$payload = ( new PaymentMethodDataBuilder() )->build(
			array(),
			'https://example.com/wp-content/plugins/spart-woocommerce/assets'
		);

		$this->assertSame(
			'https://example.com/wp-content/plugins/spart-woocommerce/assets/images/spart-logo.svg',
			$payload['logoUrl']
		);
	}
}
