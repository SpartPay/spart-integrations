<?php
/**
 * Checkout\FreeOrderException — thrown when a WC order has zero or negative total.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

/**
 * Raised by IntentRequestBuilder when the WooCommerce order total is zero or
 * negative. The Spart server rejects free orders, so we fail fast and let
 * CheckoutSession map this to a friendly customer-facing message.
 */
final class FreeOrderException extends \DomainException {}
