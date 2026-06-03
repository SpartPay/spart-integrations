<?php
/**
 * Hand-rolled spy implementing {@see \Spart\WooCommerce\Checkout\OrderDisposerInterface},
 * used by tests that need to assert dispose() was called with specific args.
 *
 * Lives in its own file (rather than as a sibling of the test class) because
 * PHPCS's Generic.Files.OneObjectStructurePerFile rule forbids multiple
 * classes per PHP file. A Mockery mock would have been the obvious
 * alternative, but the production OrderDisposer is `final` (a deliberate
 * choice — see Plugin::set_order_disposer_for_tests() docblock) and Mockery
 * refuses to mock final classes.
 *
 * @package Spart\WooCommerce\Tests\Unit\Gateway\Fixtures
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Gateway\Fixtures;

use Spart\WooCommerce\Checkout\CheckoutResult;
use Spart\WooCommerce\Checkout\OrderDisposerInterface;

/**
 * Records every dispose() call so tests can assert on order, result, and
 * correlation id.
 */
final class DisposerSpy implements OrderDisposerInterface {

	/**
	 * Captured calls: each entry is `['order' => ..., 'result' => ..., 'correlation_id' => ...]`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = array();

	public function dispose( \WC_Order $order, CheckoutResult $result, string $correlation_id ): void {
		$this->calls[] = array(
			'order'          => $order,
			'result'         => $result,
			'correlation_id' => $correlation_id,
		);
	}
}
