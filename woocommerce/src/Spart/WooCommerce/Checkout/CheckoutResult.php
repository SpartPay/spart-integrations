<?php
/**
 * Checkout\CheckoutResult — immutable result of CheckoutSession::checkout().
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

/**
 * Immutable union-style return value: either a success carrying redirect_url
 * + intent_short_id, or a failure carrying a customer-safe message and a
 * private log message. Accessors throw LogicException when the wrong branch
 * is queried — fail loudly rather than silently return empty strings.
 */
final class CheckoutResult {

	/**
	 * Sentinel failure_code used when a failure result has no originating
	 * SDK exception attached. Identical value to {@see FailureCode::UNKNOWN};
	 * retained as a class-level shortcut and for backward compatibility.
	 */
	public const UNKNOWN_FAILURE_CODE = FailureCode::UNKNOWN;

	/**
	 * Internal constructor — use the static factories success() and failure().
	 *
	 * @param bool        $success           Whether the checkout call succeeded.
	 * @param string|null $redirect_url      Success-only: hosted-checkout URL to redirect to.
	 * @param string|null $intent_short_id   Success-only: short ID returned by the API.
	 * @param string|null $customer_message  Failure-only: message safe to show the shopper.
	 * @param string|null $log_message       Failure-only: detailed message for server logs.
	 * @param string|null $failure_code      Failure-only: one of {@see FailureCode}::* constants.
	 */
	private function __construct(
		private readonly bool $success,
		private readonly ?string $redirect_url,
		private readonly ?string $intent_short_id,
		private readonly ?string $customer_message,
		private readonly ?string $log_message,
		private readonly ?string $failure_code,
	) {
	}

	/**
	 * Build a successful result.
	 *
	 * @param string $redirect_url    Hosted-checkout URL to redirect the shopper to.
	 * @param string $intent_short_id Short ID returned by the create-intent endpoint.
	 */
	public static function success( string $redirect_url, string $intent_short_id ): self {
		return new self( true, $redirect_url, $intent_short_id, null, null, null );
	}

	/**
	 * Build a failure result.
	 *
	 * @param string      $customer_message Message safe to display to the shopper.
	 * @param string|null $log_message      Optional detailed message for logs (defaults to $customer_message).
	 * @param string      $failure_code     One of {@see FailureCode}::* constants (default {@see FailureCode::UNKNOWN}).
	 */
	public static function failure( string $customer_message, ?string $log_message = null, string $failure_code = self::UNKNOWN_FAILURE_CODE ): self {
		return new self( false, null, null, $customer_message, $log_message ?? $customer_message, $failure_code );
	}

	/**
	 * Whether this is a success result.
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Success-only: redirect URL the shopper should be sent to.
	 *
	 * @throws \LogicException When called on a failure result.
	 */
	public function redirect_url(): string {
		if ( ! $this->success ) {
			throw new \LogicException( 'redirect_url() not available on failure result.' );
		}
		return (string) $this->redirect_url;
	}

	/**
	 * Success-only: short ID of the created intent.
	 *
	 * @throws \LogicException When called on a failure result.
	 */
	public function intent_short_id(): string {
		if ( ! $this->success ) {
			throw new \LogicException( 'intent_short_id() not available on failure result.' );
		}
		return (string) $this->intent_short_id;
	}

	/**
	 * Failure-only: shopper-facing error message.
	 *
	 * @throws \LogicException When called on a success result.
	 */
	public function customer_message(): string {
		if ( $this->success ) {
			throw new \LogicException( 'customer_message() not available on success result.' );
		}
		return (string) $this->customer_message;
	}

	/**
	 * Failure-only: detailed log message (defaults to customer_message()).
	 *
	 * @throws \LogicException When called on a success result.
	 */
	public function log_message(): string {
		if ( $this->success ) {
			throw new \LogicException( 'log_message() not available on success result.' );
		}
		return (string) $this->log_message;
	}

	/**
	 * Failure-only: stable lowercase `FailureCode::*` token identifying the
	 * failure kind (e.g., `timeout`, `auth_failed`, `server_error`,
	 * `validation`, `missing_api_key`, `free_order`). Returns
	 * self::UNKNOWN_FAILURE_CODE when the failure has no SDK exception
	 * attached or the exception does not map to a known token. See
	 * {@see FailureCode} for the closed set of tokens.
	 *
	 * @throws \LogicException When called on a success result.
	 */
	public function failure_code(): string {
		if ( $this->success ) {
			throw new \LogicException( 'failure_code() not available on success result.' );
		}
		return (string) $this->failure_code;
	}
}
