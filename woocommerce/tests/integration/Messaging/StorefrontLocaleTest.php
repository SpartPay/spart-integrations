<?php
/**
 * Storefront rendering against WordPress translation and checkout APIs.
 *
 * @package Spart\WooCommerce\Tests\Integration\Messaging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Messaging;

use Spart\WooCommerce\Gateway\Blocks\PaymentMethodDataBuilder;
use Spart\WooCommerce\Gateway\Blocks\SpartBlocksSupport;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\I18n\GettextFilter;
use Spart\WooCommerce\Logging\SpartLoggerInterface;
use Spart\WooCommerce\Messaging\CartMessaging;
use Spart\WooCommerce\Messaging\ProductPageMessaging;
use Spart\WooCommerce\Messaging\StorefrontDialog;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

final class StorefrontLocaleTest extends WC_Spart_IntegrationTestCase {
	public function test_first_italian_render_translates_every_surface_and_preserves_external_overrides(): void {
		$gateway = new WC_Gateway_Spart();
		$blocks  = new SpartBlocksSupport( new PaymentMethodDataBuilder(), plugins_url( 'assets/', Plugin::plugin_file() ), Plugin::VERSION );
		$blocks->initialize();
		$locale = static fn() => 'it_IT';
		add_filter( 'determine_locale', $locale );
		unload_textdomain( 'spart-woocommerce', true );
		load_plugin_textdomain( 'spart-woocommerce', false, dirname( plugin_basename( Plugin::plugin_file() ) ) . '/languages' );
		update_option(
			'woocommerce_spart_settings',
			array(
				'messaging_enabled_product' => 'yes',
				'messaging_enabled_cart'    => 'yes',
			)
		);
		$logger = $this->createMock( SpartLoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' )->with(
			'spart.i18n.unexpected_translation',
			$this->callback( static fn( $context ) => 'SPART_CHECKOUT_TITLE' === $context['code'] && 'Titolo personalizzato' === $context['observed_excerpt'] )
		);
		Plugin::set_logger_for_tests( $logger );
		GettextFilter::reset_warned_for_tests();
		try {
			$this->assertStringContainsString( 'Condividi e dividi il pagamento.', ProductPageMessaging::render() );
			$this->assertStringContainsString( 'Nessun anticipo.', ProductPageMessaging::render() );
			$this->assertStringContainsString( 'Condividi la tua spesa.', CartMessaging::render() );
			$this->assertStringContainsString( 'Condividi la tua spesa senza anticipare', $gateway->get_title() );
			$this->assertSame( 'Condividi la tua spesa senza anticipare', $blocks->get_payment_method_data()['title'] );
			$dialog = html_entity_decode( StorefrontDialog::render(), ENT_QUOTES, 'UTF-8' );
			$this->assertStringContainsString( 'Acquista insieme.<br>Ognuno paga la sua parte.', $dialog );
			$this->assertStringContainsString( '<strong>non ti verrà addebitato nulla sulla tua carta</strong>', $dialog );
			$this->assertStringNotContainsString( 'a partecipante', $dialog );
			$this->assertStringNotContainsString( 'Il costo è di', $dialog );
			$override = static fn( $translation, $text, $domain ) => 'spart-woocommerce' === $domain && 'SPART_CHECKOUT_TITLE' === $text ? 'Titolo personalizzato' : $translation;
			add_filter( 'gettext', $override, 10, 3 );
			try {
				$this->assertStringContainsString( 'Titolo personalizzato', $gateway->get_title() );
				$this->assertSame( 'Titolo personalizzato', $blocks->get_payment_method_data()['title'] );
			} finally {
				remove_filter( 'gettext', $override, 10 );
			}
		} finally {
			Plugin::set_logger_for_tests( null );
			GettextFilter::reset_warned_for_tests();
			remove_filter( 'determine_locale', $locale );
			unload_textdomain( 'spart-woocommerce', true );
		}
	}

	public function test_classic_checkout_keeps_native_unselected_radio_without_description_or_help(): void {
		$gateway         = new WC_Gateway_Spart();
		$gateway->chosen = false;
		ob_start();
		wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'name="payment_method"', $html );
		$this->assertStringContainsString( 'Share your purchase without paying upfront', $html );
		$this->assertStringContainsString( 'spart-logo.svg', $html );
		$this->assertStringNotContainsString( 'checked=', $html );
		$this->assertStringNotContainsString( 'payment_box', $html );
		$this->assertStringNotContainsString( 'data-spart-dialog-open', $html );
	}

	public function test_gateway_title_persists_as_plain_text_in_orders(): void {
		$order = wc_create_order();
		$order->set_payment_method( new WC_Gateway_Spart() );
		$order->save();
		$stored = wc_get_order( $order->get_id() );
		$this->assertSame( 'Share your purchase without paying upfront', $stored->get_payment_method_title() );
	}

	public function test_public_gateway_filters_customize_classic_and_blocks_consistently(): void {
		$title_filter = static fn( $title, $id ) => 'spart' === $id ? 'Custom ' . $title : $title;
		$icon_filter  = static fn( $icon, $id ) => 'spart' === $id ? '<span>Custom icon</span>' . $icon : $icon;
		add_filter( 'woocommerce_gateway_title', $title_filter, 10, 2 );
		add_filter( 'woocommerce_gateway_icon', $icon_filter, 10, 2 );
		try {
			$gateway = new WC_Gateway_Spart();
			$blocks  = new SpartBlocksSupport( new PaymentMethodDataBuilder(), plugins_url( 'assets/', Plugin::plugin_file() ), Plugin::VERSION );
			$blocks->initialize();
			$this->assertSame( 'Custom Share your purchase without paying upfront', $gateway->get_title() );
			$this->assertSame( $gateway->get_title(), $blocks->get_payment_method_data()['title'] );
			$this->assertStringStartsWith( '<span>Custom icon</span>', $gateway->get_icon() );
			$this->assertStringContainsString( 'spart-logo.svg', $gateway->get_icon() );
			$this->assertSame( '', $gateway->get_description() );
		} finally {
			remove_filter( 'woocommerce_gateway_title', $title_filter, 10 );
			remove_filter( 'woocommerce_gateway_icon', $icon_filter, 10 );
		}
	}
}
