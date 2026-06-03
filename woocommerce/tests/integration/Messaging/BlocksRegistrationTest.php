<?php
/**
 * Integration tests asserting the two messaging blocks register and render.
 *
 * @package Spart\WooCommerce\Tests\Integration\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Messaging;

use Spart\WooCommerce\Constants;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use WP_Block_Type_Registry;

/**
 * Confirms both server-side rendered blocks are visible in the global
 * WP_Block_Type_Registry after Plugin::boot fires the init action, and that
 * each render callback emits HTML containing the expected BEM modifier
 * class.
 */
final class BlocksRegistrationTest extends WC_Spart_IntegrationTestCase {

	public function test_product_messaging_block_is_registered(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$block    = $registry->get_registered( Constants::BLOCK_TYPE_PRODUCT_MESSAGING );

		$this->assertNotNull( $block, 'Block spart/product-messaging is not registered.' );
		$this->assertSame( Constants::BLOCK_TYPE_PRODUCT_MESSAGING, $block->name );
	}

	public function test_cart_messaging_block_is_registered(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$block    = $registry->get_registered( Constants::BLOCK_TYPE_CART_MESSAGING );

		$this->assertNotNull( $block, 'Block spart/cart-messaging is not registered.' );
		$this->assertSame( Constants::BLOCK_TYPE_CART_MESSAGING, $block->name );
	}

	public function test_product_messaging_block_render_callback_emits_html(): void {
		$this->enable_messaging( Constants::TOGGLE_MESSAGING_PRODUCT );

		$output = render_block(
			array(
				'blockName'    => Constants::BLOCK_TYPE_PRODUCT_MESSAGING,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		$this->assertStringContainsString( 'spart-messaging--product', $output );
	}

	public function test_cart_messaging_block_render_callback_emits_html(): void {
		$this->enable_messaging( Constants::TOGGLE_MESSAGING_CART );

		$output = render_block(
			array(
				'blockName'    => Constants::BLOCK_TYPE_CART_MESSAGING,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		$this->assertStringContainsString( 'spart-messaging--cart', $output );
	}

	private function enable_messaging( string $toggle_key ): void {
		$settings                = (array) get_option( Constants::OPTION_KEY, array() );
		$settings[ $toggle_key ] = 'yes';
		update_option( Constants::OPTION_KEY, $settings );
	}
}
