<?php
/**
 * Spart's WC Blocks payment-method integration. Subclass of WC Blocks'
 * AbstractPaymentMethodType — registered with the Blocks payment-method
 * registry on the woocommerce_blocks_payment_method_type_registration
 * action.
 *
 * @package Spart\WooCommerce\Gateway\Blocks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Gateway\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Settings\Schema;

/**
 * Glue between WC Blocks' payment-method registry and the Spart gateway.
 * Reads settings from the same woocommerce_spart_settings option the
 * classic gateway uses (PR2). Ships the front-end registration script
 * via wp_register_script + wp_set_script_translations.
 *
 * Note: $name is `protected` (not `private`) because
 * AbstractPaymentMethodType declares it that way and PHP requires
 * matching visibility in subclasses.
 *
 * Note: $settings is inherited from AbstractPaymentMethodType and
 * intentionally typeless to match the parent.
 */
final class SpartBlocksSupport extends AbstractPaymentMethodType {

	/**
	 * Payment-method id surfaced to WC Blocks via get_name().
	 *
	 * @var string
	 */
	protected $name = WC_Gateway_Spart::GATEWAY_ID;

	/**
	 * Wire SpartBlocksSupport with its payload builder and runtime metadata.
	 *
	 * @param PaymentMethodDataBuilder $builder    Pure mapper from settings → JS payload.
	 * @param string                   $assets_url URL prefix for assets/, with a trailing slash.
	 * @param string                   $version    Plugin VERSION constant; used as the script-handle version.
	 */
	public function __construct(
		private readonly PaymentMethodDataBuilder $builder,
		private readonly string $assets_url,
		private readonly string $version
	) {}

	/**
	 * Hydrate $settings from the woocommerce_spart_settings option,
	 * merging schema defaults under whatever the merchant has saved.
	 *
	 * Called by WC Blocks before is_active() / get_payment_method_data()
	 * are first invoked. The defaults merge mirrors the lazy
	 * `WC_Payment_Gateway::get_option()` fallback the classic gateway
	 * relies on, so Blocks and classic checkout stay in sync even when
	 * the saved option is partial (e.g. populated via WP-CLI or
	 * migration with only `enabled => 'yes'`).
	 */
	public function initialize(): void {
		$saved          = (array) \get_option( 'woocommerce_spart_settings', array() );
		$this->settings = array_merge( Schema::defaults(), $saved );
	}

	/**
	 * Whether the Spart gateway is enabled by the merchant.
	 *
	 * Mirrors the classic gateway's enabled-flag check so a single
	 * settings toggle controls both checkouts.
	 */
	public function is_active(): bool {
		$settings = is_array( $this->settings ) ? $this->settings : array();
		return ( $settings['enabled'] ?? 'no' ) === 'yes';
	}

	/**
	 * Register the Blocks-checkout JS and return its handle.
	 *
	 * Wires script translations under the `spart-woocommerce` text
	 * domain so __()-wrapped strings in blocks-checkout.js pick up the
	 * plugin's .mo files.
	 *
	 * @return list<string>
	 */
	public function get_payment_method_script_handles(): array {
		\wp_register_script(
			'spart-blocks-checkout',
			$this->assets_url . 'js/blocks-checkout.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			$this->version,
			true
		);
		\wp_set_script_translations( 'spart-blocks-checkout', 'spart-woocommerce' );
		return array( 'spart-blocks-checkout' );
	}

	/**
	 * Compose the payload exposed to the front-end via wc.wcSettings.
	 *
	 * Delegates to PaymentMethodDataBuilder. Defensive against being
	 * invoked before initialize(): an empty settings array is passed
	 * in that case.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$settings = is_array( $this->settings ) ? $this->settings : array();
		return $this->builder->build( $settings, $this->assets_url );
	}
}
