<?php
// tests/unit/I18n/StringsTest.php
declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\I18n\Strings;

final class StringsTest extends TestCase {

	public function test_text_domain_constant_matches_plugin(): void {
		$this->assertSame( 'spart-woocommerce', Strings::TEXT_DOMAIN );
	}

	public function test_codes_constant_is_associative_array(): void {
		$this->assertIsArray( Strings::CODES );
		$this->assertNotEmpty( Strings::CODES );
		foreach ( Strings::CODES as $code => $copy ) {
			$this->assertIsString( $code );
			$this->assertIsString( $copy );
			$this->assertNotSame( '', trim( $copy ) );
		}
	}

	public function test_codes_include_all_expected_messaging_keys(): void {
		$expected = array(
			'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1',
			'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_2',
			'SPART_MSG_CART_BEFORE_TOTALS_LINE_1',
			'SPART_MSG_CART_BEFORE_TOTALS_LINE_2',
			'SPART_SETTINGS_MESSAGING_PRODUCT_TITLE',
			'SPART_SETTINGS_MESSAGING_PRODUCT_LABEL',
			'SPART_SETTINGS_MESSAGING_CART_TITLE',
			'SPART_SETTINGS_MESSAGING_CART_LABEL',
		);
		foreach ( $expected as $code ) {
			$this->assertArrayHasKey( $code, Strings::CODES, "Missing code: $code" );
		}
	}

	public function test_codes_use_screaming_snake_with_spart_prefix(): void {
		foreach ( array_keys( Strings::CODES ) as $code ) {
			$this->assertMatchesRegularExpression(
				'/^SPART_[A-Z0-9_]+$/',
				$code,
				"Code does not match SPART_... SCREAMING_SNAKE convention: $code"
			);
		}
	}
}
