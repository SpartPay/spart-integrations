<?php
/**
 * Checkout\MissingApiKeyException — raised when the API key is empty.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

/**
 * Thrown by WpSpartClientFactory::create() when the merchant has not
 * configured an API key yet. CheckoutSession maps this to a friendly
 * customer-facing message ("Spart is not currently available — please
 * try again later or use a different payment method").
 */
final class MissingApiKeyException extends \DomainException {}
