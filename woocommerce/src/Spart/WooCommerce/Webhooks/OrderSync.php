<?php
/**
 * Webhooks\OrderSync — translates a verified Spart event into a WC mutation.
 *
 * @package Spart\WooCommerce\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Webhooks;

use Spart\Sdk\Webhooks\Event;
use Spart\Sdk\Webhooks\EventType;
use Spart\WooCommerce\Checkout\CheckoutSession;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * Applies a verified webhook event to a WC_Order.
 *
 * Pure mutation logic; the receiver is responsible for verification,
 * dedupe, resolver, and persisting the dedupe row. apply() always fires
 * the 'spart_webhook_before_apply' action first so integration tests
 * can inject crashes via add_action() (the only non-business hook the
 * plugin adds).
 */
class OrderSync {

	/**
	 * Wire OrderSync with its logger.
	 *
	 * @param SpartLoggerInterface $logger Logger sink for info/warning entries.
	 */
	public function __construct(
		private readonly SpartLoggerInterface $logger,
	) {
	}

	/**
	 * Apply a verified webhook event to the resolved WC order.
	 *
	 * Fires `spart_webhook_before_apply` before any WC mutation so
	 * integration tests can inject failures.
	 *
	 * @param \WC_Order $order The order resolved by WpOrderResolver.
	 * @param Event     $event The verified SDK event.
	 */
	public function apply( \WC_Order $order, Event $event ): void {
		do_action( 'spart_webhook_before_apply', $order, $event );

		match ( $event->knownType ) {
			EventType::IntentCreated     => $this->on_intent_created( $order, $event ),
			EventType::PaymentAuthorized => $this->on_payment_authorized( $order, $event ),
			EventType::OrderCompleted    => $this->on_order_completed( $order, $event ),
			EventType::OrderCanceled     => $this->on_order_canceled( $order ),
			EventType::OrderExpired      => $this->on_order_expired( $order ),
			EventType::WebhookTest       => $this->on_unexpected_test_event( $order, $event ),
			null                         => $this->on_unknown_event_type( $order, $event ),
		};
	}

	/**
	 * Read the correlation_id stamped on the order by
	 * {@see CheckoutSession::checkout()} at successful intent creation,
	 * so webhook log lines can link back to the original checkout
	 * attempt. Returns null when the meta is missing (e.g. the
	 * IntentCreated webhook races ahead of the order->save() call, or
	 * the meta was wiped by some out-of-band order edit).
	 *
	 * @param \WC_Order $order The resolved WC order.
	 */
	private function correlation_id_for( \WC_Order $order ): ?string {
		$value = (string) $order->get_meta( CheckoutSession::META_CORRELATION_ID );
		return '' !== $value ? $value : null;
	}

	/**
	 * Build a base log context with `correlation_id` attached when present.
	 * Use this in every log/warning emission so dashboards can join
	 * webhook events to their original checkout attempts.
	 *
	 * @param \WC_Order            $order   The resolved WC order.
	 * @param array<string, mixed> $context Per-call context (event_id, etc.).
	 * @return array<string, mixed>
	 */
	private function with_correlation( \WC_Order $order, array $context ): array {
		$correlation_id = $this->correlation_id_for( $order );
		if ( null !== $correlation_id ) {
			$context['correlation_id'] = $correlation_id;
		}
		return $context;
	}

	/**
	 * Log intent creation without applying any WC mutation.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event.
	 */
	private function on_intent_created( \WC_Order $order, Event $event ): void {
		$this->logger->info(
			'webhook.intent.created',
			$this->with_correlation(
				$order,
				array(
					'wc_order_id' => $order->get_id(),
					'event_id'    => $event->id,
				)
			)
		);
	}

	/**
	 * Add a note to the order when a payment part is authorized.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event (data: PaymentEnvelopeData).
	 */
	private function on_payment_authorized( \WC_Order $order, Event $event ): void {
		/**
		 * PHPStan: narrow the event data to its concrete type.
		 *
		 * @var \Spart\Sdk\Webhooks\PaymentEnvelopeData $data
		 */
		$data = $event->data;
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment part ID, 2: formatted amount. */
				__( 'Spart authorized payment %1$s for %2$s', 'spart-woocommerce' ),
				$data->paymentPartId,
				wc_price(
					(float) $data->amountAuthorized->amount,
					array( 'currency' => $data->amountAuthorized->currency )
				)
			)
		);
	}

	/**
	 * Mark the WC order as complete when Spart reports full payment.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event (data: OrderEnvelopeData).
	 */
	private function on_order_completed( \WC_Order $order, Event $event ): void {
		/**
		 * PHPStan: narrow the event data to its concrete type.
		 *
		 * @var \Spart\Sdk\Webhooks\OrderEnvelopeData $data
		 */
		$data = $event->data;
		$order->payment_complete( $data->shortId );
	}

	/**
	 * Transition the WC order to cancelled when Spart cancels it.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 */
	private function on_order_canceled( \WC_Order $order ): void {
		$order->update_status( 'cancelled', __( 'Cancelled in Spart', 'spart-woocommerce' ) );
		$this->restore_managed_stock( $order );
	}

	/**
	 * Transition the WC order to failed when the Spart intent expires.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 */
	private function on_order_expired( \WC_Order $order ): void {
		$order->update_status( 'failed', __( 'Spart intent expired', 'spart-woocommerce' ) );
		$this->restore_managed_stock( $order );
	}

	/**
	 * Restore managed stock for each line item with a `_reduced_stock` meta.
	 *
	 * WC normally handles this via the woocommerce_order_status_cancelled /
	 * woocommerce_order_status_failed action chain, but the chain is gated
	 * on the order's `_order_stock_reduced` flag and we have observed it
	 * not firing reliably across REST workers in the integration test
	 * environment. We restore stock directly using each line item's
	 * `_reduced_stock` meta as the source of truth — the same field WC's
	 * own `wc_increase_stock_levels()` consults — and clear the meta after
	 * a successful restore so a second invocation (whether ours or WC's
	 * action chain) is a no-op.
	 *
	 * @param \WC_Order $order The order whose line items to inspect.
	 */
	private function restore_managed_stock( \WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$reduced = (int) $item->get_meta( '_reduced_stock', true );
			if ( $reduced <= 0 ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}
			wc_update_product_stock( $product, $reduced, 'increase' );
			$item->delete_meta_data( '_reduced_stock' );
			$item->save();
		}
	}

	/**
	 * Warn when a test event arrives outside the expected onboarding flow.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event.
	 */
	private function on_unexpected_test_event( \WC_Order $order, Event $event ): void {
		$this->logger->warning(
			'webhook.ordersync.unexpected_test_event',
			$this->with_correlation( $order, array( 'event_id' => $event->id ) )
		);
	}

	/**
	 * Warn when the event type is not recognised by this plugin version.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event.
	 */
	private function on_unknown_event_type( \WC_Order $order, Event $event ): void {
		$this->logger->warning(
			'webhook.ordersync.unknown_event_type',
			$this->with_correlation(
				$order,
				array(
					'event_id' => $event->id,
					'type'     => $event->type,
				)
			)
		);
	}
}
