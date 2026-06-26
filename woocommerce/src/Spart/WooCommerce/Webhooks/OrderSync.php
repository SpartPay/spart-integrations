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
use Spart\Sdk\Webhooks\PaymentEnvelopeData;
use Spart\Sdk\Webhooks\PaymentPartReleasedEnvelopeData;
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
	 * Order meta key holding the payment-parts (payees) snapshot as
	 * a versioned JSON document (`{"v":1,"parts":[...]}`). Populated from any
	 * `order.*` event that carries a non-empty `paymentParts` collection and
	 * read by the Spart payees meta box. The payee name and email are stored
	 * as received from the Spart server, which owns any redaction policy.
	 */
	public const META_PAYMENT_PARTS = '_spart_payment_parts';

	/**
	 * Order meta key holding the Spart order short ID (a Base58 rendering of the
	 * order GUID). Stamped write-once from any `order.*` event
	 * (`OrderEnvelopeData->shortId`) and from `payment.authorized`
	 * (`PaymentEnvelopeData->orderShortId`). The short ID is immutable per order,
	 * so the first non-empty value wins and is never overwritten, which also
	 * makes the stamp resilient to out-of-order or replayed deliveries. Read by
	 * the Spart Info meta box to surface the merchant-facing order identifier.
	 */
	public const META_ORDER_SHORT_ID = '_spart_order_short_id';

	/**
	 * Schema version stamped into the stored snapshot so the reader can
	 * evolve the shape later without misreading documents written today.
	 */
	private const SNAPSHOT_VERSION = 1;

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

		$this->maybe_store_short_id( $order, $event );

		match ( $event->knownType ) {
			EventType::IntentCreated     => $this->on_intent_created( $order, $event ),
			EventType::OrderCreated      => $this->on_order_created( $order, $event ),
			EventType::PaymentAuthorized => $this->on_payment_authorized( $order, $event ),
			EventType::PaymentPartReleased => $this->on_payment_part_released( $order, $event ),
			EventType::OrderCompleted    => $this->on_order_completed( $order, $event ),
			EventType::OrderCanceled     => $this->on_order_canceled( $order ),
			EventType::OrderExpired      => $this->on_order_expired( $order ),
			EventType::WebhookTest       => $this->on_unexpected_test_event( $order, $event ),
			null                         => $this->on_unknown_event_type( $order, $event ),
		};
	}

	/**
	 * Persist the payment-parts snapshot to order meta.
	 *
	 * Called for every `order.*` event so the payees list stays current
	 * regardless of which lifecycle event delivered it. The snapshot merges
	 * with any already-stored parts rather than replacing them:
	 *
	 *  1. An empty collection is ignored so a later event lacking parts never
	 *     erases a snapshot a prior event already stored.
	 *  2. Per-part lifecycle timestamps are coalesced (an incoming timestamp
	 *     is only ever set, never cleared) and the status is derived from those
	 *     timestamps. This makes the snapshot order-independent: a late event
	 *     can never downgrade a part whose status has already advanced, so no
	 *     recency watermark is needed.
	 *  3. The payee name and email are stored as received from the Spart
	 *     server, which owns any redaction policy.
	 *
	 * Concurrency: this is a read-modify-write on order meta and therefore
	 * last-writer-wins, the same as before. Any transient lost update self-heals
	 * on the next full snapshot; no locking is added.
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

		$current = $this->read_parts_map( $order );

		foreach ( $data->paymentParts as $part ) {
			$existing      = $current[ $part->id ] ?? null;
			$authorized_at = $this->coalesce_ts( $existing['authorizedAt'] ?? null, $part->authorizedAt );
			$captured_at   = $this->coalesce_ts( $existing['capturedAt'] ?? null, $part->capturedAt );
			$released_at   = $this->coalesce_ts( $existing['releasedAt'] ?? null, $part->releasedAt );

			$current[ $part->id ] = array(
				'id'           => $part->id,
				'amount'       => $part->amount,
				'amountType'   => $part->amountType,
				'status'       => $this->derive_status( $authorized_at, $captured_at, $released_at ),
				'isSparter'    => $part->isSparter,
				'payeeName'    => $part->payee->fullName,
				'payeeEmail'   => $part->payee->email,
				'net'          => array(
					'amount'   => $part->payeeCharge->net->amount,
					'currency' => $part->payeeCharge->net->currency,
				),
				'total'        => array(
					'amount'   => $part->payeeCharge->total->amount,
					'currency' => $part->payeeCharge->total->currency,
				),
				'fees'         => $part->payeeCharge->fees,
				'authorizedAt' => $authorized_at,
				'capturedAt'   => $captured_at,
				'releasedAt'   => $released_at,
			);
		}//end foreach

		$this->write_parts_map( $order, $event, $current );
	}

	/**
	 * Stamp the Spart order short ID into a dedicated, labelled order meta key.
	 *
	 * Called for every event; extracts the short ID from the two envelope types
	 * that carry it — `OrderEnvelopeData->shortId` (any `order.*`) and
	 * `PaymentEnvelopeData->orderShortId` (`payment.authorized`) — and ignores
	 * all others. Write-once: the short ID is immutable per order, so a non-empty
	 * stored value is never overwritten, which also makes the stamp resilient to
	 * out-of-order or replayed deliveries. An empty/missing value is a no-op.
	 *
	 * Does not call {@see \WC_Order::save()} — the receiver persists the order
	 * after {@see apply()} returns (same contract as maybe_store_payment_parts).
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event.
	 */
	private function maybe_store_short_id( \WC_Order $order, Event $event ): void {
		$data     = $event->data;
		$short_id = '';
		if ( $data instanceof OrderEnvelopeData ) {
			$short_id = $data->shortId;
		} elseif ( $data instanceof PaymentEnvelopeData ) {
			$short_id = $data->orderShortId;
		}

		if ( '' === $short_id ) {
			return;
		}

		$existing = (string) $order->get_meta( self::META_ORDER_SHORT_ID, true );
		if ( '' !== $existing ) {
			return;
		}

		$order->update_meta_data( self::META_ORDER_SHORT_ID, $short_id );
	}

	/**
	 * Derive the canonical part status from its lifecycle timestamps.
	 * Precedence: captured > released > authorized > none. Deriving from
	 * timestamps (not the wire status string) makes the snapshot
	 * order-independent: merges only ever set timestamps and never clear
	 * them, so the derived status can advance but never regress. This
	 * reproduces the backend PaymentPart.Statuses enum exactly.
	 *
	 * @param string|null $authorized_at ISO 8601 or null.
	 * @param string|null $captured_at   ISO 8601 or null.
	 * @param string|null $released_at   ISO 8601 or null.
	 */
	private function derive_status( ?string $authorized_at, ?string $captured_at, ?string $released_at ): string {
		if ( null !== $captured_at && '' !== $captured_at ) {
			return 'captured';
		}
		if ( null !== $released_at && '' !== $released_at ) {
			return 'released';
		}
		if ( null !== $authorized_at && '' !== $authorized_at ) {
			return 'authorized';
		}
		return 'none';
	}

	/**
	 * Coalesce two nullable timestamps, preferring a non-empty incoming value
	 * but never overwriting an existing value with null/empty. Idempotent on
	 * replay (equal values are a no-op).
	 *
	 * @param string|null $existing Current stored timestamp.
	 * @param string|null $incoming Incoming timestamp.
	 */
	private function coalesce_ts( ?string $existing, ?string $incoming ): ?string {
		if ( null !== $incoming && '' !== $incoming ) {
			return $incoming;
		}
		return ( null !== $existing && '' !== $existing ) ? $existing : null;
	}

	/**
	 * Read the current snapshot parts as an id-keyed map. Tolerates the
	 * versioned document (`{"v":1,"parts":[...]}`) and a legacy bare list.
	 * Returns an empty map when no/invalid snapshot exists.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @return array<string, array<string, mixed>>
	 */
	private function read_parts_map( \WC_Order $order ): array {
		$raw = $order->get_meta( self::META_PAYMENT_PARTS, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$list = array_is_list( $decoded )
			? $decoded
			: ( ( isset( $decoded['parts'] ) && is_array( $decoded['parts'] ) ) ? $decoded['parts'] : array() );

		$map = array();
		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) && is_string( $entry['id'] ) ) {
				$map[ $entry['id'] ] = $entry;
			}
		}
		return $map;
	}

	/**
	 * Encode and persist the merged parts map as the versioned snapshot.
	 * Logs and skips on json-encode failure (never writes a partial doc).
	 *
	 * @param \WC_Order                           $order The resolved WC order.
	 * @param Event                               $event The verified SDK event.
	 * @param array<string, array<string, mixed>> $map   Merged parts by id.
	 */
	private function write_parts_map( \WC_Order $order, Event $event, array $map ): void {
		$json = wp_json_encode(
			array(
				'v'     => self::SNAPSHOT_VERSION,
				'parts' => array_values( $map ),
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
	}

	/**
	 * Patch a single stored part's lifecycle timestamp by id and re-derive its
	 * status. Matches purely by payment-part id — the event's payee is not read
	 * here; identity is carried by the full order.* snapshot. No-op (debug log)
	 * when no snapshot exists yet or the id is unknown: a patch lacks the
	 * payee-charge/split data needed to synthesise a renderable row, and the
	 * eventual full snapshot will carry correct state.
	 *
	 * @param \WC_Order $order    The resolved WC order.
	 * @param Event     $event    The verified SDK event.
	 * @param string    $part_id  The payment-part id to patch.
	 * @param string    $ts_field One of 'authorizedAt' | 'releasedAt'.
	 * @param string    $ts_value The ISO 8601 timestamp from the event.
	 */
	private function patch_part_timestamp( \WC_Order $order, Event $event, string $part_id, string $ts_field, string $ts_value ): void {
		$map = $this->read_parts_map( $order );
		if ( ! isset( $map[ $part_id ] ) ) {
			$this->logger->debug(
				'webhook.order.payment_parts.patch_no_part',
				$this->with_correlation(
					$order,
					array(
						'wc_order_id' => $order->get_id(),
						'event_id'    => $event->id,
						'part_id'     => $part_id,
					)
				)
			);
			return;
		}

		$part              = $map[ $part_id ];
		$part[ $ts_field ] = $this->coalesce_ts( $part[ $ts_field ] ?? null, $ts_value );
		$part['status']    = $this->derive_status(
			$part['authorizedAt'] ?? null,
			$part['capturedAt'] ?? null,
			$part['releasedAt'] ?? null
		);
		$map[ $part_id ]   = $part;

		$this->write_parts_map( $order, $event, $map );
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
		$data = $event->data;
		if ( ! $data instanceof PaymentEnvelopeData ) {
			return;
		}
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
		$this->patch_part_timestamp( $order, $event, $data->paymentPartId, 'authorizedAt', $data->authorizedAt );
	}

	/**
	 * Mark a payment part as released (authorization voided) when Spart reports
	 * it. Adds an audit note and patches the snapshot. Does not read the event's
	 * payee — identity stays the value already stored from order.created.
	 *
	 * @param \WC_Order $order The resolved WC order.
	 * @param Event     $event The verified SDK event (data: PaymentPartReleasedEnvelopeData).
	 */
	private function on_payment_part_released( \WC_Order $order, Event $event ): void {
		$data = $event->data;
		if ( ! $data instanceof PaymentPartReleasedEnvelopeData ) {
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment part ID, 2: formatted amount. */
				__( 'Spart released payment %1$s for %2$s', 'spart-woocommerce' ),
				$data->paymentPartId,
				wc_price(
					(float) $data->amountReleased->amount,
					array( 'currency' => $data->amountReleased->currency )
				)
			)
		);
		$this->patch_part_timestamp( $order, $event, $data->paymentPartId, 'releasedAt', $data->releasedAt );
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
