<?php
/**
 * Result returned by Webhooks\WpOrderResolver when an event cannot be
 * resolved to a WC_Order.
 *
 * @package Spart\WooCommerce\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Webhooks;

/**
 * Reasons WpOrderResolver may return instead of a WC_Order.
 *
 * Each reason maps to a "skip" branch in the webhook receiver pipeline
 * (or, for REASON_TEST_EVENT, to a successful 204 no-op).
 */
final class ResolverResult {

	/** Event arrived with a type the SDK doesn't know about. */
	public const REASON_UNKNOWN_EVENT = 'unknown_event_type';

	/** Event envelope has no sessionId field for us to resolve. */
	public const REASON_NO_SESSION_ID = 'no_session_id';

	/** Session id belongs to a different WP site sharing the API key. */
	public const REASON_SIBLING_SITE = 'sibling_site';

	/** Session id is structurally invalid (cannot extract a WC order id). */
	public const REASON_MALFORMED_SESSION = 'malformed_session';

	/** WC order was never persisted (wc_get_order returned false). */
	public const REASON_ORDER_NOT_FOUND = 'order_not_found';

	/**
	 * WC order exists in the database but is in 'trash' status. Treated
	 * as a no-op skip (200 + dedupe row written) rather than a 404,
	 * because the merchant intentionally trashed a known order and the
	 * dispatcher should stop retrying without growing the dead-letter
	 * queue.
	 */
	public const REASON_ORDER_TRASHED = 'order_trashed';

	/** A `webhook.test` ping — valid no-op, not a skip. */
	public const REASON_TEST_EVENT = 'webhook_test';

	/**
	 * Construct a result for a single skip reason.
	 *
	 * @param string $reason One of the REASON_* class constants.
	 */
	public function __construct( public readonly string $reason ) {
	}
}
