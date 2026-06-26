<?php
/**
 * Per-order meta box listing recent Spart webhook deliveries.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

use Spart\WooCommerce\Checkout\CheckoutSession;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Webhooks\OrderSync;
use Spart\WooCommerce\Webhooks\WebhookReceiver;

/**
 * Renders a per-order meta box listing recent webhook deliveries for that
 * order. HPOS-aware: registers on both the legacy `shop_order` post screen
 * and the HPOS `wc-orders` admin page screen.
 */
final class OrderWebhookDeliveriesMetaBox {

	private const META_BOX_ID = 'spart_webhook_deliveries';
	private const MAX_ROWS    = 50;
	private const CAPABILITY  = 'edit_shop_orders';

	/**
	 * Construct the meta box with its delivery repository dependency.
	 *
	 * @param DeliveryRepository $repository Repository used to read deliveries for the rendered order.
	 */
	public function __construct( private readonly DeliveryRepository $repository ) {
	}

	/**
	 * Wire the WordPress hook that registers this meta box.
	 */
	public function register(): void {
		\add_action( 'add_meta_boxes', array( $this, 'maybe_add' ), 10, 2 );
	}

	/**
	 * Conditionally register the meta box for the current order screen.
	 *
	 * Fires on both the legacy `shop_order` post screen and the HPOS
	 * `wc-orders` admin page screen. Only attaches when the order's payment
	 * method is the Spart gateway.
	 *
	 * @param string $screen_id Either 'shop_order' (legacy posts) or the HPOS screen ID.
	 * @param mixed  $order     Either a WP_Post (legacy) or a WC_Order (HPOS).
	 */
	public function maybe_add( string $screen_id, $order ): void {
		$allowed = array( 'shop_order' );
		if ( \function_exists( '\\wc_get_page_screen_id' ) ) {
			$hpos_screen = \wc_get_page_screen_id( 'shop-order' );
			if ( is_string( $hpos_screen ) && $hpos_screen !== '' ) {
				$allowed[] = $hpos_screen;
			}
		}
		if ( ! in_array( $screen_id, $allowed, true ) ) {
			return;
		}

		if ( ! \function_exists( '\\wc_get_order' ) ) {
			return;
		}

		$order = \wc_get_order( $order );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}

		if ( $order->get_payment_method() !== WC_Gateway_Spart::GATEWAY_ID ) {
			return;
		}

		\add_meta_box(
			self::META_BOX_ID,
			\esc_html__( 'Spart Info', 'spart-woocommerce' ),
			array( $this, 'render' ),
			$screen_id,
			'normal',
			'low'
		);
	}

	/**
	 * Render the meta box content for a given order.
	 *
	 * @param mixed $order Either a WP_Post (legacy) or a WC_Order (HPOS).
	 */
	public function render( $order ): void {
		if ( ! \current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! \function_exists( '\\wc_get_order' ) ) {
			return;
		}

		$order = \wc_get_order( $order );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}

		$rows = $this->repository->list_for_order( $order->get_id(), self::MAX_ROWS );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static <style> literal.
		echo StateBadge::style_block();

		$this->render_identifiers( $order );

		if ( $rows === array() ) {
			echo '<p>' . \esc_html__( 'No webhook deliveries for this order.', 'spart-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . \esc_html__( 'Delivery ID', 'spart-woocommerce' ) . '</th>';
		echo '<th>' . \esc_html__( 'Event type', 'spart-woocommerce' ) . '</th>';
		echo '<th>' . \esc_html__( 'State', 'spart-woocommerce' ) . '</th>';
		echo '<th>' . \esc_html__( 'Attempts', 'spart-woocommerce' ) . '</th>';
		echo '<th>' . \esc_html__( 'Received', 'spart-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$detail_url = \admin_url( 'admin.php?page=spart-webhook-deliveries&view=' . rawurlencode( $row->delivery_id ) );
			echo '<tr>';
			echo '<td><a href="' . \esc_url( $detail_url ) . '">' . \esc_html( $row->delivery_id ) . '</a></td>';
			echo '<td>' . \esc_html( $row->event_type ) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StateBadge::markup() returns pre-escaped HTML (sanitize_html_class + esc_html).
			echo '<td>' . StateBadge::markup( $row->state ) . '</td>';
			echo '<td>' . \esc_html( (string) $row->attempt_count ) . '</td>';
			echo '<td>' . \esc_html( Timestamp::format( $row->received_at ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the read-only identifier block (order short id, correlation id,
	 * intent short id, last delivery id) above the deliveries table.
	 *
	 * The Spart order short ID is the merchant-facing order identifier and is
	 * listed first. Each identifier is rendered only when present so
	 * non-Spart-touched fields don't surface as empty rows.
	 *
	 * @param \WC_Order $order Resolved order being rendered.
	 */
	private function render_identifiers( \WC_Order $order ): void {
		$order_short_id   = (string) $order->get_meta( OrderSync::META_ORDER_SHORT_ID, true );
		$correlation_id   = (string) $order->get_meta( CheckoutSession::META_CORRELATION_ID, true );
		$intent_short_id  = (string) $order->get_meta( CheckoutSession::META_INTENT_SHORT_ID, true );
		$last_delivery_id = (string) $order->get_meta( WebhookReceiver::ORDER_DEDUPE_META_KEY, true );

		if ( $order_short_id === '' && $correlation_id === '' && $intent_short_id === '' && $last_delivery_id === '' ) {
			return;
		}

		echo '<table class="widefat spart-webhook-deliveries__identifiers"><tbody>';
		if ( $order_short_id !== '' ) {
			echo '<tr><th scope="row">'
				. \esc_html__( 'Order short ID', 'spart-woocommerce' )
				. '</th><td><code>' . \esc_html( $order_short_id ) . '</code></td></tr>';
		}
		if ( $correlation_id !== '' ) {
			echo '<tr><th scope="row">'
				. \esc_html__( 'Correlation ID', 'spart-woocommerce' )
				. '</th><td><code>' . \esc_html( $correlation_id ) . '</code></td></tr>';
		}
		if ( $intent_short_id !== '' ) {
			echo '<tr><th scope="row">'
				. \esc_html__( 'Intent short ID', 'spart-woocommerce' )
				. '</th><td><code>' . \esc_html( $intent_short_id ) . '</code></td></tr>';
		}
		if ( $last_delivery_id !== '' ) {
			echo '<tr><th scope="row">'
				. \esc_html__( 'Last delivery ID', 'spart-woocommerce' )
				. '</th><td><code>' . \esc_html( $last_delivery_id ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}
}
