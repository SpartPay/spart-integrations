<?php
/**
 * Pinning tests for the Spart\WooCommerce\Constants class.
 *
 * @package Spart\WooCommerce\Tests\Unit
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Constants;

/**
 * The Constants class is the single source of truth for option keys, toggle
 * keys, asset handles, block names, and copy codes used across the plugin.
 *
 * These pinning tests fail loudly if any value silently changes — every
 * call site that depends on the literal value would also need to migrate,
 * so we treat each constant as a public contract.
 */
final class ConstantsTest extends TestCase {

	public function test_option_key_is_woocommerce_spart_settings(): void {
		$this->assertSame( 'woocommerce_spart_settings', Constants::OPTION_KEY );
	}

	public function test_messaging_toggle_keys(): void {
		$this->assertSame( 'messaging_enabled_product', Constants::TOGGLE_MESSAGING_PRODUCT );
		$this->assertSame( 'messaging_enabled_cart', Constants::TOGGLE_MESSAGING_CART );
	}

	public function test_messaging_asset_handles(): void {
		$this->assertSame( 'spart-messaging', Constants::STYLE_HANDLE_MESSAGING );
		$this->assertSame( 'spart-messaging-blocks-editor', Constants::SCRIPT_HANDLE_MESSAGING_EDITOR );
		$this->assertSame( 'spartMessaging', Constants::SCRIPT_DATA_VAR_MESSAGING_EDITOR );
	}

	public function test_wc_version_floor_for_blocks(): void {
		$this->assertSame( '8.0', Constants::WC_VERSION_FLOOR_FOR_BLOCKS );
	}

	public function test_block_type_names(): void {
		$this->assertSame( 'spart/product-messaging', Constants::BLOCK_TYPE_PRODUCT_MESSAGING );
		$this->assertSame( 'spart/cart-messaging', Constants::BLOCK_TYPE_CART_MESSAGING );
	}

	public function test_messaging_copy_codes(): void {
		$this->assertSame( 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1', Constants::MSG_CODE_PRODUCT_LINE_1 );
		$this->assertSame( 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_2', Constants::MSG_CODE_PRODUCT_LINE_2 );
		$this->assertSame( 'SPART_MSG_CART_BEFORE_TOTALS_LINE_1', Constants::MSG_CODE_CART_LINE_1 );
		$this->assertSame( 'SPART_MSG_CART_BEFORE_TOTALS_LINE_2', Constants::MSG_CODE_CART_LINE_2 );
	}
}
