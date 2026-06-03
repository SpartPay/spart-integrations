<?php
/**
 * Checkout\OrderDisposer — destroys a pending WC order after a failed Spart checkout.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

use Spart\WooCommerce\Logging\ErrorSanitizer;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * Single-purpose helper invoked by WC_Gateway_Spart::process_payment when
 * CheckoutSession returns a failure. Reverses every side effect WC applied
 * when it created the order during the POST to /?wc-ajax=checkout, and
 * then force-deletes the order so the merchant does not see an orphan
 * "pending payment" row.
 *
 * Sequence (per spec §3.4, delete-first):
 *   1. do_action( HOOK_DISPOSING, $order, $result, $correlation_id )
 *      — escape hatch for extensions that need to react before deletion.
 *   2. $order->delete( true ) — HPOS-aware force delete via WC data store.
 *      If delete returns false, log spart_disposal_failed and bail
 *      WITHOUT releasing coupons or restoring stock — leaving the still-
 *      pending order in a consistent state for the merchant to retry.
 *   3. wc_release_coupons_for_order( $order_id ) — best-effort: decrement
 *      usage_count on every applied coupon. SKIPPED for shopper-
 *      controllable failure_codes (see SHOPPER_CONTROLLABLE_FAILURE_CODES
 *      const) to prevent infinite re-application of single-use coupons
 *      via deliberate failure loops. When skipped, emits
 *      spart_coupons_release_skipped INFO log instead.
 *   4. wc_increase_stock_levels( $order )
 *      — best-effort: restore stock for managed line items. The plan
 *        originally specified wc_maybe_increase_stock_levels() but that
 *        helper's gate (`$order->get_data_store()->get_stock_reduced()`)
 *        returns false in WC 9.4.x even after a successful
 *        wc_reduce_stock_levels() — the order-level _order_stock_reduced
 *        meta is not reliably persisted, while the per-line _reduced_stock
 *        meta IS. We call wc_increase_stock_levels() directly because
 *        (a) it iterates line items via the per-line meta which is
 *        reliable, (b) it's idempotent (clears _reduced_stock per item
 *        after restoring), and (c) the disposer is the SOLE caller —
 *        no double-restore risk from competing hooks since force-delete
 *        that precedes it does not transition status.
 *
 * Delete-first rationale: if release/restore ran before delete and the
 * delete then failed, the merchant would see a still-`pending` order whose
 * coupons had already been released and whose stock had already been
 * reverted — an inconsistent state that masks real failures. Running
 * delete first means either (a) the order is gone (cleanup safe to run
 * best-effort, errors logged but state already consistent), or (b) the
 * order is intact and untouched (clean failure to investigate).
 *
 * If ANY step throws, the throwable is caught and logged at ERROR level
 * (event = spart_disposal_failed) — the disposer NEVER rethrows. The order
 * may persist in `pending` status as a fallback; the existing checkout
 * failure notice still reaches the shopper.
 */
/**
 * The production implementation. Declared `final` to prevent subclassing —
 * the only legitimate consumer is {@see \Spart\WooCommerce\Plugin::order_disposer()},
 * and tests depend on {@see OrderDisposerInterface} via
 * {@see \Spart\WooCommerce\Plugin::set_order_disposer_for_tests()}.
 */
final class OrderDisposer implements OrderDisposerInterface {

	public const HOOK_DISPOSING                = 'spart_wc_order_disposing';
	public const HOOK_DESTROYED                = 'spart_wc_order_destroyed';
	public const EVENT_DISPOSING               = LogEvents::ORDER_DISPOSING;
	public const EVENT_COUPONS                 = LogEvents::COUPONS_RELEASED;
	public const EVENT_COUPONS_RELEASE_SKIPPED = LogEvents::COUPONS_RELEASE_SKIPPED;
	public const EVENT_STOCK                   = LogEvents::STOCK_RESTORED;
	public const EVENT_DELETED                 = LogEvents::ORDER_DELETED;
	public const EVENT_FAILED                  = LogEvents::DISPOSAL_FAILED;
	public const EVENT_SKIPPED                 = LogEvents::DISPOSAL_SKIPPED;
	public const META_DISPOSER_RAN             = '_spart_disposer_ran';

	/**
	 * Failure codes that a shopper can deliberately trigger (cart
	 * manipulation: applying a 100%-off coupon to engineer FREE_ORDER,
	 * forcing invalid customer data to engineer VALIDATION, attempting
	 * checkout when the merchant has not configured an API key to
	 * engineer MISSING_API_KEY, or crafting cart inputs that trip the
	 * Spart SDK's stricter argument validators to engineer MALFORMED).
	 * For these codes the disposer SKIPS `wc_release_coupons_for_order()`
	 * so a single-use coupon cannot be infinitely re-applied via
	 * deliberate failure loops.
	 *
	 * MALFORMED here covers the broader `CheckoutSession::checkout()`
	 * catch arm `SpartValidationException | \InvalidArgumentException`,
	 * which maps the `\InvalidArgumentException` branch to MALFORMED.
	 * The shopper-controllable threat scenario is cart inputs that pass
	 * WC validation but trip the SDK model constructors (Money,
	 * Contact, OrderOptions, LineItem). The catch is intentionally
	 * broader than that single origin: a third-party hook firing during
	 * checkout could theoretically also raise `\InvalidArgumentException`
	 * and be mapped to MALFORMED. Allowlisting accepts that minor
	 * collateral conservatism — the alternative (a narrow per-call-site
	 * try/catch with a tagged exception) would add complexity for a
	 * trade-off that already errs on the side of preserving merchant
	 * revenue when in doubt.
	 *
	 * Trade-off accepted: a misconfigured merchant (no API key) will
	 * permanently consume legitimate shoppers' single-use coupons until
	 * the merchant fixes the configuration — but the alternative (per-
	 * shopper coupon abuse on a correctly-running merchant) is the worse
	 * failure mode by orders of magnitude in real impact.
	 *
	 * Stock restoration and order deletion are NOT gated by this allowlist:
	 * stock-hold is a temporary inventory concern with no monetary abuse
	 * vector (the shopper cannot take possession of the items), and
	 * delete-first is fundamental to the disposer's purpose.
	 */
	private const SHOPPER_CONTROLLABLE_FAILURE_CODES = array(
		FailureCode::FREE_ORDER,
		FailureCode::VALIDATION,
		FailureCode::MISSING_API_KEY,
		FailureCode::MALFORMED,
	);

	/**
	 * The single WooCommerce order status the disposer is willing to act
	 * upon. Any other status (processing, on-hold, completed, cancelled,
	 * refunded, failed) means another code path has taken ownership of the
	 * order and the disposer must bail to avoid undoing their state.
	 *
	 * Extracted into a named constant per the repo's "no magic strings"
	 * rule — the centralised log event names (LogEvents) and failure codes
	 * (FailureCode) follow the same convention.
	 */
	private const STATUS_PENDING = 'pending';

	/**
	 * Wire the disposer with its logger sink and an API-key provider used to
	 * scrub the configured key from any exception messages that bubble into
	 * the disposal-failed log line.
	 *
	 * @param SpartLoggerInterface $logger           Sink for both success warnings and disposal-failure errors.
	 * @param \Closure             $api_key_provider Closure of shape `(): string` returning the merchant's currently-configured API key (or empty string), invoked on each disposal failure so a rotated key takes effect without rebuilding the disposer.
	 */
	public function __construct(
		private readonly SpartLoggerInterface $logger,
		private readonly \Closure $api_key_provider,
	) {}

	/**
	 * Reverse side effects of order creation and delete the order.
	 *
	 * @param \WC_Order      $order          The pending order to dispose of.
	 * @param CheckoutResult $result         The failure result that triggered disposal.
	 * @param string         $correlation_id UUIDv4 stamped on every log line for this attempt.
	 */
	public function dispose( \WC_Order $order, CheckoutResult $result, string $correlation_id ): void {
		$failure_code = $result->is_success() ? CheckoutResult::UNKNOWN_FAILURE_CODE : $result->failure_code();
		$base_context = array(
			'correlation_id' => $correlation_id,
			'order_id'       => $order->get_id(),
			'failure_code'   => $failure_code,
		);

		// Defense-in-depth: if some out-of-band code path (parallel admin
		// action, webhook handler) already moved the order off `pending`,
		// do NOT undo their state by deleting it. Log and bail.
		$current_status = $order->get_status();
		if ( self::STATUS_PENDING !== $current_status ) {
			$this->logger->info(
				'Spart skipped disposal: order is no longer pending.',
				array_merge(
					$base_context,
					array(
						'event'          => self::EVENT_SKIPPED,
						'current_status' => $current_status,
					)
				)
			);
			return;
		}

		// Idempotency guard: if a previous dispose() call on this order
		// already ran (delete failed or threw, leaving the marker
		// persisted), do not re-attempt — re-runs would emit duplicate
		// logs and risk double-releasing coupons or double-restoring
		// stock if the previous run had partially succeeded.
		if ( '' !== (string) $order->get_meta( self::META_DISPOSER_RAN ) ) {
			$this->logger->info(
				'Spart skipped disposal: disposer already ran for this order.',
				array_merge(
					$base_context,
					array(
						'event'  => self::EVENT_SKIPPED,
						'reason' => 'already_ran',
					)
				)
			);
			return;
		}

		try {
			$this->safe_do_action(
				self::HOOK_DISPOSING,
				array( $order, $result, $correlation_id ),
				$base_context
			);

			$this->logger->info(
				'Spart starting disposal of failed checkout order.',
				array_merge( $base_context, array( 'event' => self::EVENT_DISPOSING ) )
			);

			// Persist the idempotency marker BEFORE the destructive
			// sequence so it survives a delete() failure or throw and
			// blocks future retries. On the success path delete(true)
			// wipes the row anyway, so the marker only outlives the
			// call when something went wrong. Placed after do_action so
			// extension callbacks see the same pre-dispose order state
			// that they would have seen historically.
			$order->update_meta_data( self::META_DISPOSER_RAN, '1' );
			$order->save();

			// Delete-first: if delete fails we leave coupons + stock
			// allocations untouched so the still-pending order remains
			// consistent. Otherwise (delete succeeded) the order is gone
			// and we can safely release coupons + restore stock as
			// best-effort cleanup.
			$deleted = $order->delete( true );
			if ( false === $deleted ) {
				$this->logger->error(
					'Spart could not delete the failed checkout order; it will remain in pending state. Coupons and stock left untouched.',
					array_merge( $base_context, array( 'event' => self::EVENT_FAILED ) )
				);
				return;
			}

			$this->logger->warning(
				'Spart deleted the failed checkout order to keep merchant order lists clean.',
				array_merge( $base_context, array( 'event' => self::EVENT_DELETED ) )
			);

			// Fire the public destroyed action so merchants and observability
			// integrations can react to disposal (metrics, alerting, audit
			// log forwarding). Fired BEFORE the best-effort cleanup steps so
			// subscribers still see the event even if a later release/restore
			// throws — the order is already gone, the cleanup is bookkeeping.
			// Routed through safe_do_action() so a hostile/buggy subscriber
			// cannot suppress the coupon release + stock restore that follow.
			$this->safe_do_action(
				self::HOOK_DESTROYED,
				array( $base_context['order_id'], $failure_code, $correlation_id ),
				$base_context
			);

			if ( function_exists( 'wc_release_coupons_for_order' ) ) {
				if ( in_array( $failure_code, self::SHOPPER_CONTROLLABLE_FAILURE_CODES, true ) ) {
					$this->logger->info(
						'Spart suppressed coupon release on failed checkout order: failure_code is shopper-controllable; releasing the coupon would let a malicious shopper re-apply a single-use coupon indefinitely via deliberate failures.',
						array_merge(
							$base_context,
							array(
								'event'  => self::EVENT_COUPONS_RELEASE_SKIPPED,
								'reason' => 'shopper_controllable_failure_code',
							)
						)
					);
				} else {
					\wc_release_coupons_for_order( $order->get_id() );
					$this->logger->info(
						'Spart released applied coupons on failed checkout order.',
						array_merge( $base_context, array( 'event' => self::EVENT_COUPONS ) )
					);
				}
			}

			if ( function_exists( 'wc_increase_stock_levels' ) ) {
				\wc_increase_stock_levels( $order );
				$this->logger->info(
					'Spart restored stock levels for failed checkout order.',
					array_merge( $base_context, array( 'event' => self::EVENT_STOCK ) )
				);
			}
		} catch ( \Throwable $e ) {
			$api_key = $this->safe_api_key();
			$this->logger->error(
				ErrorSanitizer::sanitize( $e, $api_key ),
				array_merge( $base_context, array( 'event' => self::EVENT_FAILED ) )
			);
		}//end try
	}

	/**
	 * Resolve the currently-configured Spart API key via the injected
	 * provider closure, guarding against the closure itself throwing.
	 *
	 * The disposer's contract is "NEVER rethrows" (see class docblock),
	 * and that contract MUST hold even if a future wiring change makes
	 * the provider's `get_option()` read throw, or a defective DB
	 * filter raises on a key lookup. Falling back to an empty string
	 * means ErrorSanitizer will skip the key-redaction step (the rest
	 * of its truncation + short-class-name prefix still runs).
	 */
	private function safe_api_key(): string {
		try {
			return (string) ( $this->api_key_provider )();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Fire a `do_action()` such that a hostile/buggy subscriber throwing
	 * a \Throwable cannot suppress the caller's subsequent work. The throw
	 * is sanitised (so a third-party plugin's stack trace cannot leak the
	 * configured API key into wc-logs) and logged at ERROR level alongside
	 * the disposer's base context.
	 *
	 * Without this guard the do_action() at HOOK_DESTROYED — fired AFTER
	 * delete but BEFORE the best-effort coupon-release + stock-restore —
	 * would let a buggy observability plugin permanently leak coupon
	 * usage_count and managed stock on every failed checkout: exactly the
	 * inverse of the feature's purpose.
	 *
	 * @param string               $hook    Action hook name (e.g. HOOK_DESTROYED).
	 * @param array<int, mixed>    $args    Positional arguments to forward.
	 * @param array<string, mixed> $context Logger base context for the failure branch.
	 */
	private function safe_do_action( string $hook, array $args, array $context ): void {
		try {
			\do_action( $hook, ...$args );
		} catch ( \Throwable $e ) {
			$api_key = $this->safe_api_key();
			$this->logger->error(
				'Spart action subscriber threw during disposal; continuing. ' . ErrorSanitizer::sanitize( $e, $api_key ),
				array_merge(
					$context,
					array(
						'event' => self::EVENT_FAILED,
						'hook'  => $hook,
					)
				)
			);
		}
	}
}
