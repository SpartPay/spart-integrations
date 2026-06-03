<?php
/**
 * Renders the Spart thank-you placeholder for orders awaiting payment confirmation.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

/**
 * Renders a status paragraph on the WooCommerce thank-you page for Spart orders
 * that are still awaiting payment confirmation.
 */
final class ThankYouRenderer {

	/**
	 * Order statuses that require the pending-confirmation placeholder.
	 *
	 * @var list<string>
	 */
	private const PENDING_STATUSES = array( 'pending', 'on-hold' );

	/**
	 * Echo the placeholder paragraph if the order is awaiting confirmation.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function render( int $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? \wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! in_array( $order->get_status(), self::PENDING_STATUSES, true ) ) {
			return;
		}

		echo '<p class="spart-thankyou-pending">'
			. \esc_html__(
				"Your payment is being processed by Spart. You'll receive a confirmation email shortly.",
				'spart-woocommerce'
			)
			. '</p>';
	}
}
