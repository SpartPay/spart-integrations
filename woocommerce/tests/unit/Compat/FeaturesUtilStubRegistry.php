<?php
/**
 * Holds the active recorder for the eval-defined FeaturesUtil stub.
 *
 * @package Spart\WooCommerce\Tests\Unit\Compat
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Compat;

/**
 * Registry that holds the shared recorder object between the eval'd stub and the test.
 *
 * @internal
 */
final class FeaturesUtilStubRegistry {

	/**
	 * The active recorder instance, or null when no stub is installed.
	 *
	 * @var object|null
	 */
	public static ?object $recorder = null;
}
