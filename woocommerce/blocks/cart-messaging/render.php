<?php
/**
 * Render callback for the spart/cart-messaging block.
 *
 * Delegates to CartMessaging::render() so the block produces
 * identical HTML to the classic-theme do_action hook output.
 *
 * @package Spart\WooCommerce\Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Spart\WooCommerce\Messaging\CartMessaging::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML pre-escaped in render().
