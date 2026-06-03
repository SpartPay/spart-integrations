<?php
/**
 * Checkout\IntentRequestBuilder — maps a WC_Order to an SDK CreateIntentRequest.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

use Spart\Sdk\Dtos\CreateIntentRequest;
use Spart\Sdk\Models\Contact;
use Spart\Sdk\Models\LineItem;
use Spart\Sdk\Models\Money;
use Spart\Sdk\Models\OrderOptions;
use Spart\WooCommerce\Settings\Schema;

/**
 * Translates a WC_Order into a CreateIntentRequest.
 *
 * Mapping rules (see PR plan for full table):
 *  - WC currency normalised to upper-case via Money.
 *  - Each WC product line item becomes a SDK LineItem; fees-only orders get a
 *    synthesised single "Order" line so the SDK's non-empty rule is satisfied.
 *  - Zero or negative totals throw FreeOrderException — Spart rejects them.
 *  - Image URIs that aren't absolute http(s) are normalised via home_url()
 *    when they start with "/", or dropped otherwise.
 *  - The session ID is derived from the site token and the WC order ID.
 *  - returnUri := wc_get_endpoint_url('order-received', orderId, '');
 *    cancelUri := wc_get_checkout_url();
 *    maxDuration is taken from $defaultOrderDurationMinutes (injected via
 *    constructor, read from the merchant settings at plugin boot time).
 *    Values below 5 are clamped to 5 defensively.
 */
final class IntentRequestBuilder {

	/**
	 * Constructor.
	 *
	 * @param int $default_order_duration_minutes Merchant-configured default checkout
	 *                                             window. Below-5 values are clamped to
	 *                                             5 defensively in case Schema::sanitize
	 *                                             was bypassed (WP-CLI, migration, raw SQL).
	 */
	public function __construct(
		private readonly int $default_order_duration_minutes,
	) {
	}

	/**
	 * Build the SDK request from a WooCommerce order.
	 *
	 * @param \WC_Order         $order    The WooCommerce order.
	 * @param SessionIdComposer $sessions Composer used to derive the Spart session ID.
	 * @return CreateIntentRequest
	 * @throws FreeOrderException When the order total is zero or negative.
	 */
	public function build( \WC_Order $order, SessionIdComposer $sessions ): CreateIntentRequest {
		$total_lexeme = $this->normalise_amount_lexeme( (string) $order->get_total() );

		if ( ! $this->is_positive( $total_lexeme ) ) {
			throw new FreeOrderException( 'Spart cannot process orders with a zero or negative total.' );
		}

		$total = Money::fromString( $total_lexeme, (string) $order->get_currency() );

		$line_items = $this->map_line_items( $order );
		if ( array() === $line_items ) {
			$line_items = array( new LineItem( 'Order', 1 ) );
		}

		$first_name = (string) $order->get_billing_first_name();
		$last_name  = (string) $order->get_billing_last_name();

		$contact = new Contact(
			(string) $order->get_billing_email(),
			'' !== $first_name ? $first_name : null,
			'' !== $last_name ? $last_name : null,
		);

		$options = new OrderOptions(
			new \DateInterval( 'PT' . max( Schema::MIN_ORDER_DURATION_MINUTES, $this->default_order_duration_minutes ) . 'M' ),
			$this->return_uri_for( $order ),
			$this->cancel_uri(),
		);

		return new CreateIntentRequest(
			total: $total,
			lineItems: $line_items,
			sparter: $contact,
			sessionId: $sessions->compose( (int) $order->get_id() ),
			options: $options,
		);
	}

	/**
	 * Map WC line items to SDK LineItem instances.
	 *
	 * @param \WC_Order $order The order to enumerate.
	 * @return list<LineItem>
	 */
	private function map_line_items( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$name = (string) ( method_exists( $item, 'get_name' ) ? $item->get_name() : '' );
			$qty  = (int) ( method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : 1 );
			if ( '' === $name || $qty < 1 ) {
				continue;
			}

			$image_uri = null;
			if ( method_exists( $item, 'get_product' ) ) {
				$product = $item->get_product();
				if ( null !== $product && method_exists( $product, 'get_image_url' ) ) {
					$image_uri = $this->normalise_image_uri( (string) $product->get_image_url() );
				}
			}

			$out[] = new LineItem( $name, $qty, null, $image_uri );
		}
		return $out;
	}

	/**
	 * Returns absolute http(s) URI, prefixes home_url() for site-relative paths,
	 * or null when the input cannot be reliably absolutised.
	 *
	 * @param string $raw Raw image URI from the product.
	 */
	private function normalise_image_uri( string $raw ): ?string {
		if ( '' === $raw ) {
			return null;
		}
		if ( preg_match( '#^https?://#i', $raw ) ) {
			return $raw;
		}
		if ( function_exists( 'home_url' ) && str_starts_with( $raw, '/' ) ) {
			return rtrim( (string) \home_url(), '/' ) . $raw;
		}
		return null;
	}

	/**
	 * Build the customer return URL for the given order.
	 *
	 * Passes wc_get_checkout_url() as the permalink fallback so wc_get_endpoint_url
	 * always produces an absolute URL — without it, WC falls back to the global
	 * $post permalink which is null in admin/REST/integration contexts and yields
	 * a relative path that fails OrderOptions::isAbsoluteHttpUri().
	 *
	 * @param \WC_Order $order The order to derive the return URL for.
	 */
	private function return_uri_for( \WC_Order $order ): string {
		if ( function_exists( 'wc_get_endpoint_url' ) && function_exists( 'wc_get_checkout_url' ) ) {
			$base = (string) \wc_get_checkout_url();
			if ( '' !== $base ) {
				return (string) \wc_get_endpoint_url( 'order-received', (string) $order->get_id(), $base );
			}
		}
		if ( function_exists( 'home_url' ) ) {
			return rtrim( (string) \home_url(), '/' ) . '/?order-received=' . (string) $order->get_id();
		}
		return '';
	}

	/**
	 * Build the cancel URL the shopper is sent to after aborting at Spart.
	 *
	 * Falls back to home_url() when the WC checkout page is missing so we always
	 * hand OrderOptions an absolute URI.
	 */
	private function cancel_uri(): string {
		if ( function_exists( 'wc_get_checkout_url' ) ) {
			$url = (string) \wc_get_checkout_url();
			if ( '' !== $url ) {
				return $url;
			}
		}
		if ( function_exists( 'home_url' ) ) {
			return rtrim( (string) \home_url(), '/' ) . '/';
		}
		return '';
	}

	/**
	 * Trim and normalise the amount lexeme into the form Money::fromString accepts.
	 *
	 * @param string $raw Raw amount string from WC.
	 */
	private function normalise_amount_lexeme( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '0';
		}
		// WC may emit comma separators in some locales; normalise to dot.
		return str_replace( ',', '.', $raw );
	}

	/**
	 * Whether $lexeme is a syntactically valid decimal that is greater than zero.
	 *
	 * @param string $lexeme Decimal lexeme produced by normalise_amount_lexeme().
	 */
	private function is_positive( string $lexeme ): bool {
		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $lexeme ) ) {
			return false;
		}
		return ( (float) $lexeme ) > 0.0;
	}
}
