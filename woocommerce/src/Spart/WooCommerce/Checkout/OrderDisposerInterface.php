<?php
/**
 * OrderDisposerInterface — abstraction for failed-checkout order cleanup.
 *
 * Allows production code (Plugin::order_disposer()) and tests to depend on a
 * single seam rather than a concrete class. The production implementation,
 * {@see OrderDisposer}, is `final` so it cannot be subclassed; tests inject
 * a hand-rolled spy via {@see \Spart\WooCommerce\Plugin::set_order_disposer_for_tests()}.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

/**
 * Contract for destroying a WC order whose Spart checkout attempt failed.
 *
 * Implementations MUST treat the call as best-effort and MUST NOT rethrow:
 * any exception is the implementation's responsibility to log/swallow so the
 * gateway's checkout failure-notice flow remains uninterrupted.
 */
interface OrderDisposerInterface {

	/**
	 * Destroy the given pending order. See {@see OrderDisposer::dispose()} for
	 * the full behavioural contract (idempotency, allowlist, log events).
	 *
	 * @param \WC_Order      $order          The WC order to dispose.
	 * @param CheckoutResult $result         The failed checkout result.
	 * @param string         $correlation_id The request-scoped correlation id.
	 * @return void
	 */
	public function dispose( \WC_Order $order, CheckoutResult $result, string $correlation_id ): void;
}
