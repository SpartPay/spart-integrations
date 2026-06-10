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
use Spart\Sdk\Webhooks\OrderEnvelopeData;
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
	 * Order meta key holding the redacted payment-parts (payees) snapshot as
	 * a versioned JSON document (`{"v":1,"parts":[...]}`). Populated from any
	 * `order.*` event that carries a non-empty `paymentParts` collection and
	 * read by the Spart payees meta box. No PII is stored: the payee email is
	 * never persisted and the payee name is sanitised so the snapshot can
	 * never contain an email address.
	 */
	public const META_PAYMENT_PARTS = '_spart_payment_parts';

	/**
	 * Companion meta key recording the `createdAt` of the event that produced
	 * the current snapshot. Used to ignore out-of-order / replayed deliveries
	 * so an older event can never overwrite a snapshot written by a newer one.
	 */
	public const META_PAYMENT_PARTS_EVENT_AT = '_spart_payment_parts_event_at';

	/**
	 * Schema version stamped into the stored snapshot so the reader can
	 * evolve the shape later without misreading documents written today.
	 */
	private const SNAPSHOT_VERSION = 1;

	/** Placeholder used whenever a payee label would otherwise carry PII. */
	private const REDACTED_PLACEHOLDER = '•••';

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

		if ( $event->data instanceof OrderEnvelopeData ) {
			$this->maybe_store_payment_parts( $order, $event );
		}

		match ( $event->knownType ) {
			EventType::IntentCreated     => $this->on_intent_created( $order, $event ),
			EventType::OrderCreated      => $this->on_order_created( $order, $event ),
			EventType::PaymentAuthorized => $this->on_payment_authorized( $order, $event ),
			EventType::OrderCompleted    => $this->on_order_completed( $order, $event ),
			EventType::OrderCanceled     => $this->on_order_canceled( $order ),
			EventType::OrderExpired      => $this->on_order_expired( $order ),
			EventType::WebhookTest       => $this->on_unexpected_test_event( $order, $event ),
			null                         => $this->on_unknown_event_type( $order, $event ),
		};
	}

	/**
	 * Persist the redacted payment-parts snapshot to order meta.
	 *
	 * Called for every `order.*` event so the payees list stays current
	 * regardless of which lifecycle event delivered it. Three rules keep the
	 * snapshot correct and PII-free:
	 *
	 *  1. An empty collection is ignored so a later event lacking parts never
	 *     erases a snapshot a prior event already stored.
	 *  2. Out-of-order / replayed deliveries are ignored: an event whose
	 *     `createdAt` is not newer than the one that wrote the current
	 *     snapshot is skipped, so a late `order.created` cannot clobber the
	 *     newer statuses from an `order.completed` that arrived first.
	 *  3. No PII is stored — the payee email is dropped and the payee name is
	 *     sanitised so the document can never contain an email address.
	 *
	 * The receiver saves the order after {@see apply()} returns, so no
	 * explicit save is performed here.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event (data: OrderEnvelopeData).
	 */
	private function maybe_store_payment_parts( \WC_Order $order, Event $event ): void {
		$data = $event->data;
		if ( ! $data instanceof OrderEnvelopeData ) {
			return;
		}
		if ( array() === $data->paymentParts ) {
			return;
		}

		if ( $this->snapshot_is_stale( $order, $event ) ) {
			return;
		}

		$parts = array();
		foreach ( $data->paymentParts as $part ) {
			$parts[] = array(
				'id'           => $part->id,
				'amount'       => $part->amount,
				'amountType'   => $part->amountType,
				'status'       => $part->status,
				'isSparter'    => $part->isSparter,
				'payeeName'    => $this->sanitise_payee_name( $part->payee->fullName ),
				'net'          => array(
					'amount'   => $part->payeeCharge->net->amount,
					'currency' => $part->payeeCharge->net->currency,
				),
				'total'        => array(
					'amount'   => $part->payeeCharge->total->amount,
					'currency' => $part->payeeCharge->total->currency,
				),
				'fees'         => $part->payeeCharge->fees,
				'authorizedAt' => $part->authorizedAt,
				'capturedAt'   => $part->capturedAt,
				'releasedAt'   => $part->releasedAt,
			);
		}//end foreach

		$json = wp_json_encode(
			array(
				'v'     => self::SNAPSHOT_VERSION,
				'parts' => $parts,
			)
		);
		if ( false === $json ) {
			$this->logger->warning(
				'webhook.order.payment_parts.encode_failed',
				$this->with_correlation(
					$order,
					array(
						'wc_order_id' => $order->get_id(),
						'event_id'    => $event->id,
						'delivery_id' => $event->deliveryId,
					)
				)
			);
			return;
		}

		$order->update_meta_data( self::META_PAYMENT_PARTS, $json );
		$order->update_meta_data( self::META_PAYMENT_PARTS_EVENT_AT, $event->createdAt );
	}

	/**
	 * Decide whether the incoming event is older than (or a replay of) the one
	 * that produced the snapshot currently on the order.
	 *
	 * The comparison keys off the event emission time ({@see Event::$createdAt},
	 * the top-level webhook `createdAt`) — NOT the order's own `createdAt`,
	 * which is constant across every `order.*` lifecycle event for the same
	 * order and would freeze the snapshot at first arrival.
	 *
	 * Failure modes:
	 *  - No prior snapshot: never stale (first write always wins).
	 *  - A prior snapshot exists but either timestamp is unparseable: treated
	 *    as stale (fail-closed) and logged, so a corrupt/hand-edited companion
	 *    meta or a malformed incoming event cannot silently disable the gate
	 *    and overwrite a known-good snapshot.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event.
	 */
	private function snapshot_is_stale( \WC_Order $order, Event $event ): bool {
		$stored = (string) $order->get_meta( self::META_PAYMENT_PARTS_EVENT_AT, true );
		if ( '' === $stored ) {
			return false;
		}

		$stored_ts   = strtotime( $stored );
		$incoming_ts = strtotime( $event->createdAt );
		if ( false === $stored_ts || false === $incoming_ts ) {
			$this->logger->warning(
				'webhook.order.payment_parts.timestamp_unparseable',
				$this->with_correlation(
					$order,
					array(
						'wc_order_id' => $order->get_id(),
						'event_id'    => $event->id,
						'delivery_id' => $event->deliveryId,
						'stored_at'   => $stored,
						'incoming_at' => $event->createdAt,
					)
				)
			);
			return true;
		}

		return $incoming_ts <= $stored_ts;
	}

	/**
	 * Sanitise the payee display name so the stored snapshot can never carry
	 * real identity. The backend masks payee identity to the redaction
	 * placeholder before transmission, so the ONLY value we ever expect here
	 * is that placeholder. This gate is a defence-in-depth measure at the
	 * storage boundary: it whitelists the expected mask and redacts anything
	 * else, so a backend regression that emits a real name (with or without an
	 * "@") can never land PII in the WordPress database or the admin UI. This
	 * mirrors the email handling, which is dropped entirely.
	 *
	 * @param string $name The payee name as received from the webhook.
	 */
	private function sanitise_payee_name( string $name ): string {
		return self::REDACTED_PLACEHOLDER === $name ? $name : self::REDACTED_PLACEHOLDER;
	}

	/**
	 * Log order creation without applying any WC status transition.
	 *
	 * The order already exists in WC (created during checkout); the payees
	 * snapshot is persisted centrally by {@see maybe_store_payment_parts()}.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event.
	 */
	private function on_order_created( \WC_Order $order, Event $event ): void {
		$this->logger->info(
			'webhook.order.created',
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
