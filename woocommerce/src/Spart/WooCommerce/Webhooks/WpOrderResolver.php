<?php
/**
 * Webhooks\WpOrderResolver — maps a verified Spart event to a WC_Order.
 *
 * @package Spart\WooCommerce\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Webhooks;

use Spart\Sdk\Webhooks\EnvelopeData;
use Spart\Sdk\Webhooks\Event;
use Spart\Sdk\Webhooks\EventType;
use Spart\Sdk\Webhooks\IntentEnvelopeData;
use Spart\Sdk\Webhooks\OrderEnvelopeData;
use Spart\Sdk\Webhooks\PaymentEnvelopeData;
use Spart\Sdk\Webhooks\PaymentPartReleasedEnvelopeData;
use Spart\WooCommerce\Checkout\SessionIdComposer;

/**
 * Encapsulates the "extract a WC_Order from a verified Spart Event" branch.
 *
 * Branches (in order — do not reorder; the receiver depends on this priority):
 *   1. webhook.test event → ResolverResult::REASON_TEST_EVENT
 *      (a valid no-op the receiver maps to 204 + markApplied)
 *   2. Unknown event type (Event::$knownType === null) → REASON_UNKNOWN_EVENT
 *   3. Envelope sessionId missing/empty → REASON_NO_SESSION_ID
 *   4. sessionId carries a different site_token → REASON_SIBLING_SITE
 *   5. sessionId is structurally malformed (no order id) → REASON_MALFORMED_SESSION
 *   6. wc_get_order() returns falsy → REASON_ORDER_NOT_FOUND (genuinely missing)
 *   7. wc_get_order() returns a trashed order → REASON_ORDER_TRASHED (idempotent skip)
 *   8. otherwise the resolved \WC_Order
 */
class WpOrderResolver {

	/**
	 * Constructor.
	 *
	 * @param string $site_token 8-char lowercase hex token persisted at activation.
	 */
	public function __construct( private readonly string $site_token ) {
	}

	/**
	 * Resolve the WC order targeted by a verified webhook event.
	 *
	 * @param Event $event Verified SDK envelope.
	 * @return \WC_Order|ResolverResult Either the live order or a skip reason.
	 */
	public function resolve( Event $event ): \WC_Order|ResolverResult {
		// 1. webhook.test → valid no-op (the receiver maps this to 204 + markApplied).
		if ( EventType::WebhookTest === $event->knownType ) {
			return new ResolverResult( ResolverResult::REASON_TEST_EVENT );
		}

		// 2. Unknown event type — server emitted a type not in this plugin's SDK enum.
		if ( null === $event->knownType ) {
			return new ResolverResult( ResolverResult::REASON_UNKNOWN_EVENT );
		}

		$session_id = $this->extract_session_id( $event->data );

		// 3. No sessionId on the envelope.
		if ( null === $session_id || '' === $session_id ) {
			return new ResolverResult( ResolverResult::REASON_NO_SESSION_ID );
		}

		// 4. Session belongs to a sibling WP site sharing the same API key.
		if ( ! SessionIdComposer::belongs_to_site_token( $session_id, $this->site_token ) ) {
			return new ResolverResult( ResolverResult::REASON_SIBLING_SITE );
		}

		// 5. SessionId structure is bad — no embedded order id.
		$order_id = SessionIdComposer::extract_order_id( $session_id );
		if ( null === $order_id ) {
			return new ResolverResult( ResolverResult::REASON_MALFORMED_SESSION );
		}

		// 6/7. Order missing vs trashed.
		// In WooCommerce 9.4 wc_get_order() returns the WC_Order even for
		// trashed orders in BOTH HPOS-on and HPOS-off modes (the factory
		// resolves classes by post_type, and wp_trash_post() only flips
		// post_status). PR6 distinguishes the two outcomes because they
		// have different semantic meaning to the dispatcher:
		// - REASON_ORDER_NOT_FOUND (404, no row): caller has the wrong
		// sessionId — never persisted on this site.
		// - REASON_ORDER_TRASHED   (200, row):    the merchant trashed
		// a known order; further deliveries should be silently dropped.
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new ResolverResult( ResolverResult::REASON_ORDER_NOT_FOUND );
		}
		if ( 'trash' === $order->get_status() ) {
			return new ResolverResult( ResolverResult::REASON_ORDER_TRASHED );
		}

		return $order;
	}

	/**
	 * Extract sessionId from any of the SDK envelope DTOs that carry one.
	 *
	 * Intent, Order, Payment, and PaymentPartReleased envelopes all expose a
	 * nullable `sessionId` property. TestEnvelopeData has no sessionId, but the
	 * webhook.test branch is short-circuited above so it never reaches
	 * this helper. Unknown event types likewise return early.
	 *
	 * @param EnvelopeData|null $data Concrete envelope, or null when the
	 *                                event type was unknown server-side.
	 * @return string|null
	 */
	private function extract_session_id( ?EnvelopeData $data ): ?string {
		return match ( true ) {
			$data instanceof IntentEnvelopeData              => $data->sessionId,
			$data instanceof OrderEnvelopeData               => $data->sessionId,
			$data instanceof PaymentEnvelopeData             => $data->sessionId,
			$data instanceof PaymentPartReleasedEnvelopeData => $data->sessionId,
			default                                          => null,
		};
	}
}
