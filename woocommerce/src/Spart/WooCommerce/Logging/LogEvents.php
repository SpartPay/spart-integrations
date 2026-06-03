<?php
/**
 * Logging\LogEvents — canonical event-name constants for spart checkout logs.
 *
 * @package Spart\WooCommerce\Logging
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Logging;

/**
 * Single source of truth for the `event` key in every spart checkout log
 * line. The eight CHECKOUT_*, INTENT_*, ORDER_* and DISPOSAL_* events
 * mirror the contract documented in
 * the destroy-order-on-checkout-failure design §3.8.
 *
 * The CHECKOUT_SUCCEEDED constant is a plugin-side convenience (not part
 * of the spec §3.8 table, which covers failed checkouts only). It marks
 * the gateway boundary on success so support can confirm process_payment
 * returned a redirect, complementing spart_intent_created from
 * CheckoutSession.
 *
 * Per the repository's "no magic strings" convention, every emitter and
 * every test MUST reference these constants — never inline literals.
 */
final class LogEvents {

	public const CHECKOUT_STARTED   = 'spart_checkout_started';
	public const CHECKOUT_SUCCEEDED = 'spart_checkout_succeeded';
	public const CHECKOUT_FAILED    = 'spart_checkout_failed';
	public const INTENT_CREATED     = 'spart_intent_created';
	public const ORDER_DISPOSING    = 'spart_order_disposing';
	public const COUPONS_RELEASED   = 'spart_coupons_released';
	public const STOCK_RESTORED     = 'spart_stock_restored';
	public const ORDER_DELETED      = 'spart_order_deleted';
	public const DISPOSAL_FAILED    = 'spart_disposal_failed';
	/**
	 * Defense-in-depth event (NOT in spec §3.8) — emitted when the
	 * disposer is invoked against an order whose status has already
	 * moved off `pending` (e.g. an out-of-band parallel admin action).
	 * Tells support the disposer ran but bailed without touching the
	 * order, so they don't suspect data loss.
	 */
	public const DISPOSAL_SKIPPED = 'spart_disposal_skipped';

	/**
	 * Defense-in-depth event (NOT in spec §3.8) — emitted when the
	 * disposer suppresses the canonical {@see self::COUPONS_RELEASED}
	 * step because the checkout's `failure_code` is in the
	 * shopper-controllable allowlist (see
	 * {@see \Spart\WooCommerce\Checkout\OrderDisposer} for the rationale).
	 * The line carries `reason=shopper_controllable_failure_code` and the
	 * `failure_code` field so support can correlate "why was this coupon
	 * not released?" with the specific failure category.
	 */
	public const COUPONS_RELEASE_SKIPPED = 'spart_coupons_release_skipped';

	/**
	 * Defense-in-depth event (NOT in spec §3.8) — emitted by
	 * {@see \Spart\WooCommerce\Eligibility\EligibilityChecker} when the
	 * SDK throws while resolving the merchant's
	 * `GET /api/merchants/eligibility` verdict. The checker fails open
	 * (gateway stays visible) so the customer can still attempt
	 * checkout; this event lets support correlate a "checkout was
	 * available but ultimately failed" report with the underlying SDK
	 * problem (timeout, 5xx, transport error).
	 */
	public const ELIGIBILITY_CHECK_FAILED = 'spart_eligibility_check_failed';

	/**
	 * Prevent instantiation; this class holds constants only.
	 */
	private function __construct() {}
}
