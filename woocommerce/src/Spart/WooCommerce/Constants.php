<?php
/**
 * Plugin-wide constants. Single source of truth for option keys, toggle
 * keys, asset handles, block names, and symbolic copy codes.
 *
 * @package Spart\WooCommerce
 */

declare(strict_types=1);

namespace Spart\WooCommerce;

/**
 * Centralised constants. Imported wherever the plugin needs to read or
 * write the WC settings option, register/look up an asset handle, register
 * a block type, or reference the symbolic codes that the i18n gettext
 * filter substitutes into customer-facing copy.
 *
 * No methods; this class is purely a container for `const` members.
 */
final class Constants {

	/**
	 * Single option key that stores ALL gateway settings (WC convention:
	 * `woocommerce_{gateway_id}_settings`).
	 *
	 * Read by every settings consumer (gateway, messaging toggles, webhook
	 * secret, debug logging gate). Migrating this requires updating every
	 * such site — treat it as a stable public contract.
	 */
	public const OPTION_KEY = 'woocommerce_spart_settings';

	/**
	 * Settings field key controlling whether the product-page messaging
	 * block / classic hook renders.
	 */
	public const TOGGLE_MESSAGING_PRODUCT = 'messaging_enabled_product';

	/**
	 * Settings field key controlling whether the cart-page messaging
	 * block / classic hook renders.
	 */
	public const TOGGLE_MESSAGING_CART = 'messaging_enabled_cart';

	/**
	 * `wp_register_style` handle for the shared messaging stylesheet.
	 */
	public const STYLE_HANDLE_MESSAGING = 'spart-messaging';

	/**
	 * `wp_register_script` handle for the messaging blocks' editor script.
	 */
	public const SCRIPT_HANDLE_MESSAGING_EDITOR = 'spart-messaging-blocks-editor';

	/**
	 * Global JS variable name set via `wp_localize_script` carrying the
	 * preview-text payload consumed by the block editor.
	 */
	public const SCRIPT_DATA_VAR_MESSAGING_EDITOR = 'spartMessaging';

	/**
	 * Minimum WooCommerce version required to register block types via
	 * `register_block_type` with a render_callback. Below this the
	 * registrar bails and the admin notice surfaces.
	 */
	public const WC_VERSION_FLOOR_FOR_BLOCKS = '8.0';

	/**
	 * Block type name for the product-page messaging block.
	 */
	public const BLOCK_TYPE_PRODUCT_MESSAGING = 'spart/product-messaging';

	/**
	 * Block type name for the cart-page messaging block.
	 */
	public const BLOCK_TYPE_CART_MESSAGING = 'spart/cart-messaging';

	/**
	 * Symbolic code for the first line of product-page messaging copy.
	 *
	 * Passed to `__()` so the gettext filter can swap in the canonical
	 * English text and translators can override per-locale via .mo files.
	 */
	public const MSG_CODE_PRODUCT_LINE_1 = 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1';

	/** Symbolic code for the second line of product-page messaging copy. */
	public const MSG_CODE_PRODUCT_LINE_2 = 'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_2';

	/** Symbolic code for the first line of cart-page messaging copy. */
	public const MSG_CODE_CART_LINE_1 = 'SPART_MSG_CART_BEFORE_TOTALS_LINE_1';

	/** Symbolic code for the second line of cart-page messaging copy. */
	public const MSG_CODE_CART_LINE_2 = 'SPART_MSG_CART_BEFORE_TOTALS_LINE_2';
}
