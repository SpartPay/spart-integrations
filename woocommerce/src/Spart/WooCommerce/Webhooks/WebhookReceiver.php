<?php
/**
 * Webhooks\WebhookReceiver — REST endpoint orchestrator for inbound Spart webhooks.
 *
 * @package Spart\WooCommerce\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Webhooks;

use Spart\Sdk\Exceptions\SpartValidationException;
use Spart\Sdk\Webhooks\Event;
use Spart\Sdk\Webhooks\SignatureVerifier;
use Spart\WooCommerce\Logging\ErrorSanitizer;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * Orchestrates the inbound webhook pipeline.
 *
 * See PR3 design spec, "Data flow" section
 * (the webhook receiver design).
 *
 * Pipeline (PR3 + PR6 amendment):
 *  1. Read raw body + headers from the WP_REST_Request.
 *  2. Reject empty delivery-id header (400) — defense in depth.
 *  3. Verify HMAC signature + parse envelope (401 on failure, no row).
 *  4. Idempotent-replay check: applied/skipped/errored → 200 {deduped:true}.
 *  5. Resolve event → WC_Order or ResolverResult.
 *  6. PR6: NO_SESSION_ID / MALFORMED_SESSION / SIBLING_SITE → 400
 *     {error:<reason>}, no dedupe row. ORDER_NOT_FOUND → 404 same shape.
 *  7. Existing-row branch resolution (apply-time TOCTOU close, #227):
 *       - attempt > 1  → dispatcher-driven sequential retry; trust the
 *                        signed header and bump attempt_count via
 *                        increment_attempt(). Crash-recovery flow.
 *       - attempt == 1 → concurrent receiver; atomic claim_for_retry()
 *                        with an idle threshold. If another worker is
 *                        mid-apply (row received_at is fresh) the claim
 *                        returns false → log webhook.race_lost
 *                        (reason=in_progress) and return 200 {deduped:true}.
 *     No existing row → insert_received(). If the unique-key write
 *     collides (another worker won the race) → log webhook.race_lost
 *     (reason=insert_collision) and return 200 {deduped:true}.
 *     On the happy path, log `webhook.received` (info).
 *  8. Wrap the rest in try/catch:
 *       On Throwable → log `webhook.handler_exception` (error, sanitized).
 *       The dedupe row is left in 'received' so the next retry re-attempts.
 *       Return 500 {error:handler_exception}.
 *  9. ResolverResult branches that did get a row:
 *       REASON_TEST_EVENT      → mark_applied; 204; log `webhook.applied`.
 *       REASON_UNKNOWN_EVENT   → mark_skipped; 200 {skipped:reason};
 *                                log `webhook.unknown_event_type` (warning).
 *       REASON_ORDER_TRASHED   → mark_skipped; 200 {skipped:reason};
 *                                log `webhook.order_trashed` (warning).
 * 10. WC_Order branch:
 *       Defensive idempotency: order's `_spart_last_delivery_id` meta ==
 *       incoming delivery_id → mark_skipped(already_applied), 200 deduped.
 *       OrderSync::apply → mutate.
 *       update_meta_data + save → record delivery on order.
 *       mark_applied(delivery_id, order_id); log `webhook.applied`; 204.
 */
class WebhookReceiver {

	/** Order meta key recording the last successfully-applied delivery_id. */
	public const ORDER_DEDUPE_META_KEY = '_spart_last_delivery_id';

	/** HTTP header carrying the Spart delivery UUID. */
	public const HEADER_DELIVERY_ID = 'X-Spart-Delivery-Id';

	/** HTTP header carrying the HMAC signature. */
	public const HEADER_SIGNATURE = 'X-Spart-Signature';

	/** HTTP header carrying the delivery attempt number (1-based). */
	public const HEADER_ATTEMPT = 'X-Spart-Webhook-Attempt';

	/**
	 * Minimum idle seconds before `claim_for_retry()` will reclaim a
	 * non-terminal `received` row on the concurrent (attempt=1) path.
	 *
	 * Within this window we treat an existing `received` row as
	 * "another worker is actively applying" and short-circuit with 200
	 * deduped. Outside the window we assume the previous worker crashed
	 * and reclaim. 30s is well above typical apply duration (< 1s) and
	 * well below typical dispatcher retry intervals (minutes), so the
	 * heuristic gives the right answer in both cases.
	 *
	 * Sequential retries (attempt>1) bypass this entirely and use the
	 * straight `increment_attempt()` path because the dispatcher's own
	 * retry cadence already disambiguates "concurrent" from "retry".
	 */
	public const DELIVERY_RETRY_IDLE_SECONDS = 30;

	/**
	 * Wire the receiver with its collaborators.
	 *
	 * @param SignatureVerifier    $verifier   HMAC verifier pre-configured with the signing secret.
	 * @param DeliveryRepository   $deliveries Dedupe table read/write.
	 * @param OrderSync            $order_sync Applies a verified event to a WC_Order.
	 * @param WpOrderResolver      $resolver   Maps a verified event to a WC_Order.
	 * @param SpartLoggerInterface $logger     Logger sink.
	 */
	public function __construct(
		private readonly SignatureVerifier $verifier,
		private readonly DeliveryRepository $deliveries,
		private readonly OrderSync $order_sync,
		private readonly WpOrderResolver $resolver,
		private readonly SpartLoggerInterface $logger
	) {
	}

	/**
	 * Handle a POST /wp-json/spart/v1/webhook request.
	 *
	 * Pipeline (PR3 + PR6 amendment):
	 *  1. Read body + headers.
	 *  2. Reject empty delivery_id (400, no row).
	 *  3. Verify HMAC signature (401 on failure, no row).
	 *  4. Idempotent-replay check: applied/skipped/errored → 200 deduped.
	 *  5. Resolve event → WC_Order | ResolverResult.
	 *  6. 4xx reasons (NO_SESSION_ID, MALFORMED_SESSION, SIBLING_SITE,
	 *     ORDER_NOT_FOUND) return early WITHOUT writing the dedupe row.
	 *  7. Insert/increment the dedupe row (for TEST_EVENT, UNKNOWN_EVENT,
	 *     ORDER_TRASHED, or a real WC_Order).
	 *  8. Apply the event (in a try/catch — 500 leaves row 'received').
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response Response to send to the caller.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$raw_body    = (string) $request->get_body();
		$delivery_id = (string) $request->get_header( self::HEADER_DELIVERY_ID );
		$signature   = (string) $request->get_header( self::HEADER_SIGNATURE );
		$attempt     = max( 1, (int) $request->get_header( self::HEADER_ATTEMPT ) );

		if ( '' === $delivery_id ) {
			return new \WP_REST_Response( array( 'error' => 'missing_delivery_id' ), 400 );
		}

		try {
			$event = $this->verifier->verifyAndParse( $raw_body, $signature, $delivery_id, $attempt );
		} catch ( SpartValidationException $e ) {
			$this->logger->warning(
				'webhook.signature_invalid',
				array( 'delivery_id' => $delivery_id )
			);
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$existing = $this->deliveries->find( $delivery_id );
		if ( null !== $existing && in_array( $existing->state, array( 'applied', 'skipped', 'errored' ), true ) ) {
			return new \WP_REST_Response( array( 'deduped' => true ), 200 );
		}

		$resolved = $this->resolver->resolve( $event );

		if ( $resolved instanceof ResolverResult && $this->is_reject_reason( $resolved->reason ) ) {
			return $this->reject( $resolved, $event, $delivery_id );
		}

		try {
			if ( null !== $existing ) {
				if ( $attempt > 1 ) {
					// Dispatcher-driven sequential retry. The signed
					// X-Spart-Webhook-Attempt header (HMAC-verified
					// above) tells us this is NOT a concurrent receiver
					// — the dispatcher's retry intervals are minutes,
					// far larger than any apply-time race window. Trust
					// the header and bump the counter unconditionally
					// so the crash-recovery flow (received → retry →
					// applied) records attempt_count=2 as expected.
					$this->deliveries->increment_attempt( $delivery_id );
				} else {
					// Concurrent receiver, attempt=1 + existing
					// `received` row. The other worker either (a) is
					// still mid-apply or (b) crashed and left the row
					// stranded. Atomic claim_for_retry() picks the
					// right branch via an idle-threshold check:
					// - Row younger than DELIVERY_RETRY_IDLE_SECONDS
					// → returns false → we short-circuit 200
					// deduped; the other worker finishes the work.
					// - Row older than that → returns true → we
					// reclaim and proceed (crash recovery).
					$claimed = $this->deliveries->claim_for_retry(
						$delivery_id,
						self::DELIVERY_RETRY_IDLE_SECONDS
					);
					if ( ! $claimed ) {
						$this->logger->info(
							'webhook.race_lost',
							array(
								'delivery_id' => $delivery_id,
								'event_type'  => $event->type,
								'reason'      => 'in_progress',
							)
						);
						return new \WP_REST_Response( array( 'deduped' => true ), 200 );
					}
				}//end if
			} else {
				$inserted = $this->deliveries->insert_received( $delivery_id, $event->type, null );
				if ( ! $inserted ) {
					// Concurrent delivery with the same delivery_id raced
					// us to the unique index and won. Bow out with the
					// same deduped reply the dispatcher would see on a
					// sequential retry — the winner finishes the work.
					// We log this as a single structured entry instead
					// of letting wpdb::print_error spam error_log
					// (suppressed in DeliveryRepository::insert_received).
					$this->logger->info(
						'webhook.race_lost',
						array(
							'delivery_id' => $delivery_id,
							'event_type'  => $event->type,
							'reason'      => 'insert_collision',
						)
					);
					return new \WP_REST_Response( array( 'deduped' => true ), 200 );
				}
			}//end if

			$this->logger->info(
				'webhook.received',
				array(
					'delivery_id' => $delivery_id,
					'event_type'  => $event->type,
					'attempt'     => $attempt,
				)
			);

			if ( $resolved instanceof ResolverResult ) {
				return $this->handle_test_or_unknown( $resolved, $event, $delivery_id );
			}
			return $this->apply_to_order( $resolved, $event, $delivery_id );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'webhook.handler_exception',
				array(
					'delivery_id' => $delivery_id,
					'event_type'  => $event->type,
					'error'       => ErrorSanitizer::sanitize( $e ),
				)
			);
			return new \WP_REST_Response( array( 'error' => 'handler_exception' ), 500 );
		}//end try
	}

	/**
	 * Whether the given resolver reason maps to a 4xx response with no
	 * dedupe row (PR6 amendment).
	 *
	 * @param string $reason A {@see ResolverResult} REASON_* constant value.
	 * @return bool True when the reason is one of the four "invalid request"
	 *              outcomes; false for TEST_EVENT and UNKNOWN_EVENT.
	 */
	private function is_reject_reason( string $reason ): bool {
		return in_array(
			$reason,
			array(
				ResolverResult::REASON_NO_SESSION_ID,
				ResolverResult::REASON_MALFORMED_SESSION,
				ResolverResult::REASON_SIBLING_SITE,
				ResolverResult::REASON_ORDER_NOT_FOUND,
			),
			true
		);
	}

	/**
	 * Build the 4xx response for an unmappable verified envelope and log it.
	 *
	 * No dedupe row is written so every retry gets a fresh 4xx and Spart's
	 * dispatcher dead-letters cleanly after MaxAttempts.
	 *
	 * @param ResolverResult $result      The reject outcome from the resolver.
	 * @param Event          $event       The verified event envelope.
	 * @param string         $delivery_id The X-Spart-Delivery-Id header value.
	 * @return \WP_REST_Response 400 for sibling/no-session/malformed, 404 for order-not-found.
	 */
	private function reject( ResolverResult $result, Event $event, string $delivery_id ): \WP_REST_Response {
		$status = ResolverResult::REASON_ORDER_NOT_FOUND === $result->reason ? 404 : 400;

		$log_key = ResolverResult::REASON_ORDER_NOT_FOUND === $result->reason
			? 'webhook.order_not_found'
			: 'webhook.rejected';
		$this->logger->warning(
			$log_key,
			array(
				'delivery_id' => $delivery_id,
				'event_type'  => $event->type,
				'reason'      => $result->reason,
			)
		);

		return new \WP_REST_Response( array( 'error' => $result->reason ), $status );
	}

	/**
	 * Handle resolver outcomes that DO write a dedupe row but do not mutate
	 * a WC order: webhook.test (success 204) and unknown event types
	 * (200 + skipped, forward-compat).
	 *
	 * @param ResolverResult $result      Either REASON_TEST_EVENT or REASON_UNKNOWN_EVENT.
	 * @param Event          $event       The verified event envelope.
	 * @param string         $delivery_id The X-Spart-Delivery-Id header value.
	 * @return \WP_REST_Response 204 for test events, 200+skipped for unknown.
	 */
	private function handle_test_or_unknown( ResolverResult $result, Event $event, string $delivery_id ): \WP_REST_Response {
		if ( ResolverResult::REASON_TEST_EVENT === $result->reason ) {
			$this->deliveries->mark_applied( $delivery_id, null );
			$this->logger->info(
				'webhook.applied',
				array(
					'delivery_id' => $delivery_id,
					'event_type'  => $event->type,
					'wc_order_id' => null,
				)
			);
			return new \WP_REST_Response( null, 204 );
		}

		$log_key = ResolverResult::REASON_ORDER_TRASHED === $result->reason
			? 'webhook.order_trashed'
			: 'webhook.unknown_event_type';

		$this->deliveries->mark_skipped( $delivery_id, $result->reason );
		$this->logger->warning(
			$log_key,
			array(
				'delivery_id' => $delivery_id,
				'type'        => $event->type,
			)
		);
		return new \WP_REST_Response( array( 'skipped' => $result->reason ), 200 );
	}

	/**
	 * Apply the verified event to its resolved WC order, with one-shot
	 * idempotency guard against double-application within a single dedupe row.
	 *
	 * @param \WC_Order $resolved    The WC order resolved by the resolver.
	 * @param Event     $event       The verified event envelope.
	 * @param string    $delivery_id The X-Spart-Delivery-Id header value.
	 * @return \WP_REST_Response 200 deduped on already-applied; 204 on first apply.
	 */
	private function apply_to_order( \WC_Order $resolved, Event $event, string $delivery_id ): \WP_REST_Response {
		if ( $delivery_id === (string) $resolved->get_meta( self::ORDER_DEDUPE_META_KEY ) ) {
			$this->deliveries->mark_skipped( $delivery_id, 'already_applied' );
			return new \WP_REST_Response( array( 'deduped' => true ), 200 );
		}

		$this->order_sync->apply( $resolved, $event );

		$resolved->update_meta_data( self::ORDER_DEDUPE_META_KEY, $delivery_id );
		$resolved->save();

		$this->deliveries->mark_applied( $delivery_id, $resolved->get_id() );
		$this->logger->info(
			'webhook.applied',
			array(
				'delivery_id' => $delivery_id,
				'event_type'  => $event->type,
				'wc_order_id' => $resolved->get_id(),
			)
		);
		return new \WP_REST_Response( null, 204 );
	}
}
