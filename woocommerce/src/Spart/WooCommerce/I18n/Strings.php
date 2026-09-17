<?php
/**
 * Symbolic translation codes.
 *
 * @package Spart\WooCommerce\I18n
 */

declare(strict_types=1);

namespace Spart\WooCommerce\I18n;

/**
 * Symbolic translation codes for the Spart WooCommerce plugin.
 *
 * Source files reference user-visible copy by SCREAMING_SNAKE codes
 * (e.g. `SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1`). At runtime the
 * GettextFilter substitutes the English display copy from this map
 * whenever no real translation has been registered for a code.
 *
 * This indirection lets the plugin work on any WordPress install
 * regardless of WooCommerce store-locale state, since we never rely
 * on a `.mo` file being loaded for the default presentation.
 */
final class Strings {

	public const TEXT_DOMAIN = 'spart-woocommerce';

	/**
	 * Symbolic code to English display copy map.
	 *
	 * @var array<string, string>
	 */
	public const CODES = array(
		'SPART_SETTINGS_LOADING_TITLE'           => 'Checkout loading screen',
		'SPART_SETTINGS_LOADING_ENABLED'         => 'Enable checkout loading screen',
		'SPART_SETTINGS_LOADING_ENABLED_HELP'    => 'Turn off if your theme already shows loading feedback when placing an order.',
		'SPART_SETTINGS_LOADING_COLOR'           => 'Backdrop color (six-digit hex)',
		'SPART_SETTINGS_LOADING_OPACITY'         => 'Backdrop opacity (%)',
		'SPART_SETTINGS_LOADING_IMAGE'           => 'Loading image',
		'SPART_SETTINGS_LOADING_IMAGE_HELP'      => 'Choose a raster image from the Media Library. Animated images are supported; SVG is not. Clear to use the built-in indicator (ID 0). Customers who prefer reduced motion always see the static built-in indicator.',
		'SPART_LOADING_TITLE'                    => 'Connecting to Spart...',
		'SPART_LOADING_DESCRIPTION'              => 'Please wait while we prepare your checkout.',
		'SPART_LOADING_CHOOSE_IMAGE'             => 'Choose image',
		'SPART_LOADING_CLEAR_IMAGE'              => 'Clear image',
		'SPART_LOADING_PREVIEW'                  => 'Preview loading screen',
		'SPART_LOADING_CLOSE'                    => 'Close preview',
		'SPART_LOADING_INVALID_IMAGE'            => 'Choose a supported raster image, not SVG.',

		// Settings — Product messaging toggle.
		'SPART_SETTINGS_MESSAGING_PRODUCT_TITLE' => 'Product page messaging',
		'SPART_SETTINGS_MESSAGING_PRODUCT_LABEL' => 'Show Spart messaging on single product pages',

		// Settings — Cart messaging toggle.
		'SPART_SETTINGS_MESSAGING_CART_TITLE'    => 'Cart page messaging',
		'SPART_SETTINGS_MESSAGING_CART_LABEL'    => 'Show Spart messaging on the cart page',

		// Product page messaging copy.
		'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_1'  => 'Pay in 3 interest-free installments with Spart.',
		'SPART_MSG_PRODUCT_BEFORE_PRICE_LINE_2'  => 'Split the payment with your friends!',

		// Cart page messaging copy.
		'SPART_MSG_CART_BEFORE_TOTALS_LINE_1'    => 'Spart it: pay in installments with friends.',
		'SPART_MSG_CART_BEFORE_TOTALS_LINE_2'    => 'Choose Spart at checkout to split this order.',
	);
}
