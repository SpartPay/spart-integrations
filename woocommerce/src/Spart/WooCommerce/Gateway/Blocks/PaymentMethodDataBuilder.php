<?php
/**
 * Pure mapper that converts the WC settings array into the payload shape
 * exposed to the Blocks-checkout JS via wc.wcSettings.getSetting('spart_data').
 *
 * @package Spart\WooCommerce\Gateway\Blocks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Gateway\Blocks;

/**
 * Stateless builder. No WP function calls, no globals — fully unit-testable
 * without booting WordPress or WooCommerce.
 */
class PaymentMethodDataBuilder {

	/**
	 * Compose the payload that WC Blocks injects into wc.wcSettings under
	 * the 'spart_data' key. The shape is append-only across versions —
	 * keys may be added in later PRs but never removed or retyped.
	 *
	 * @param array<string, mixed> $settings  The merchant's saved
	 *                                        woocommerce_spart_settings option.
	 * @param string               $assets_url URL prefix for assets/. A
	 *                                        trailing slash is added if
	 *                                        missing.
	 * @return array{title:string,description:string,logoUrl:string,supports:list<string>}
	 */
	public function build( array $settings, string $assets_url ): array {
		return array(
			'title'       => (string) ( $settings['title'] ?? '' ),
			'description' => (string) ( $settings['description'] ?? '' ),
			'logoUrl'     => rtrim( $assets_url, '/' ) . '/images/spart-logo.svg',
			'supports'    => array( 'products' ),
		);
	}
}
