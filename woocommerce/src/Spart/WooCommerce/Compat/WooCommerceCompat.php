<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package Spart\WooCommerce\Compat
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Compat;

use Spart\WooCommerce\Plugin;

/**
 * Declares WooCommerce feature compatibility (HPOS, Cart/Checkout Blocks).
 */
final class WooCommerceCompat {

	/** Feature ID for High-Performance Order Storage (HPOS). */
	const FEATURE_HPOS = 'custom_order_tables';

	/** Feature ID for Cart/Checkout Blocks. */
	const FEATURE_BLOCKS = 'cart_checkout_blocks';

	/**
	 * Declare WooCommerce feature compatibility.
	 *
	 * Fired on the `before_woocommerce_init` action. Guards on class existence
	 * so the plugin does not fatal when WooCommerce is inactive.
	 *
	 * @return void
	 */
	public static function declare(): void {
		$features_util = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
		if ( ! class_exists( $features_util ) ) {
			return;
		}

		$plugin_file = Plugin::plugin_file();

		$features_util::declare_compatibility( self::FEATURE_HPOS, $plugin_file, true );
		$features_util::declare_compatibility( self::FEATURE_BLOCKS, $plugin_file, true );
	}
}
