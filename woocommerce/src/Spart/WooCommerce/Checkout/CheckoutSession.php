<?php
/**
 * Checkout\CheckoutSession — orchestrates `process_payment` for WC_Gateway_Spart.
 *
 * @package Spart\WooCommerce\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

use Spart\Sdk\Exceptions\SpartApiException;
use Spart\Sdk\Exceptions\SpartAuthException;
use Spart\Sdk\Exceptions\SpartRateLimitException;
use Spart\Sdk\Exceptions\SpartServerException;
use Spart\Sdk\Exceptions\SpartTimeoutException;
use Spart\Sdk\Exceptions\SpartTransportException;
use Spart\Sdk\Exceptions\SpartValidationException;
use Spart\WooCommerce\Logging\ErrorSanitizer;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * Heart of the plugin: builds the SDK client, asks IntentRequestBuilder for a
 * CreateIntentRequest, calls IntentsEndpoint::create, and translates every
 * possible exception into a customer-facing CheckoutResult.
 *
 * The error mapping table here is the spec's canonical mapping
 * (`the v1 plugin design`
 * §"Errors & edge cases"). Adding a new SDK exception type means adding a
 * branch here AND a row in the dataProvider in CheckoutSessionTest.
 *
 * Persists _spart_intent_short_id (the Spart-side short ID) and
 * _spart_correlation_id (the WC-side request correlation token) on the
 * order on successful checkout. See issue #273 for the admin UI that
 * surfaces these values.
 */
class CheckoutSession {

	/**
	 * Post-meta key used to persist the Spart intent's short id (e.g.
	 * `SPART-ABC123`) onto the order at successful intent creation, so
	 * later admin surfaces ({@see \Spart\WooCommerce\Admin\WebhookDeliveriesListPage})
	 * can cross-reference webhook deliveries with the intent on the
	 * Spart side without re-querying the SDK.
	 *
	 * @internal The leading underscore on the meta key follows the
	 * WordPress convention that flags a post meta value as "hidden" —
	 * it is excluded from the default REST API exposure for posts and
	 * orders. DO NOT register this key via `register_meta()` /
	 * `register_post_meta()` with `show_in_rest => true` without first
	 * adding an explicit `auth_callback` that enforces a `manage_woocommerce`
	 * (or stronger) capability check. Spart intent short ids are
	 * considered admin-only diagnostic data.
	 */
	public const META_INTENT_SHORT_ID = '_spart_intent_short_id';

	/**
	 * Post-meta key used to persist the WC plugin's request-scoped
	 * `correlation_id` (UUIDv4) onto the order at successful intent
	 * creation, so later webhook handlers ({@see \Spart\WooCommerce\Webhooks\OrderSync})
	 * can include the same correlation_id in their log lines and link
	 * webhook deliveries back to the original checkout attempt in
	 * `wc-logs/spart-*.log`.
	 *
	 * Lives on order meta (NOT in the SDK request) because the Spart
	 * server does not yet expose a per-intent metadata field that would
	 * round-trip back in webhook envelopes. When the server / SDK adds
	 * a `CreateIntentRequest::metadata` field, prefer that mechanism
	 * over this local-meta hop (it would survive even if the order is
	 * recreated by some merchant tooling between intent creation and
	 * webhook delivery).
	 *
	 * @internal Same REST-exposure caveat as META_INTENT_SHORT_ID: the
	 * leading underscore makes this key hidden from the default REST
	 * API; do not register it as a public REST field without an
	 * explicit capability-checking `auth_callback`.
	 */
	public const META_CORRELATION_ID = '_spart_correlation_id';

	/**
	 * Wire the orchestrator with its three collaborators.
	 *
	 * @param SpartClientFactoryInterface $client_factory  Source of {@see \Spart\Sdk\SpartClient}.
	 * @param IntentRequestBuilder        $request_builder Maps `WC_Order` → `CreateIntentRequest`.
	 * @param SpartLoggerInterface        $logger          Sink for sanitised diagnostics.
	 */
	public function __construct(
		private readonly SpartClientFactoryInterface $client_factory,
		private readonly IntentRequestBuilder $request_builder,
		private readonly SpartLoggerInterface $logger,
	) {}

	/**
	 * Convert a WooCommerce order into a Spart checkout intent.
	 *
	 * @param \WC_Order $order          The order to check out.
	 * @param string    $correlation_id UUIDv4 the gateway generated to correlate
	 *                                  all log lines for this checkout attempt.
	 * @return CheckoutResult Either a success(redirect_url, intent_short_id)
	 *                         or a failure(customer_message, log_message, failure_code).
	 */
	public function checkout( \WC_Order $order, string $correlation_id ): CheckoutResult {
		$api_key      = $this->client_factory->api_key();
		$base_context = array(
			'correlation_id' => $correlation_id,
			'order_id'       => $order->get_id(),
		);

		try {
			$sessions = new SessionIdComposer( $this->site_token() );
			$request  = $this->request_builder->build( $order, $sessions );
			$client   = $this->client_factory->create( $base_context );
			$intent   = $client->intents()->create( $request );

			$this->logger->info(
				'Spart intent created',
				array_merge(
					$base_context,
					array(
						'event'           => LogEvents::INTENT_CREATED,
						'intent_short_id' => $intent->intentShortId,
						'replay'          => $intent->wasIdempotentReplay,
					)
				)
			);

			// Persist the request-scoped correlation_id on the order so
			// later webhook handlers ({@see \Spart\WooCommerce\Webhooks\OrderSync})
			// can include it in their log lines and link asynchronous
			// webhook deliveries back to the original checkout attempt.
			// Server-agnostic alternative to a (currently non-existent)
			// CreateIntentRequest::metadata field — see the
			// META_CORRELATION_ID const docblock for the trade-off.
			$order->update_meta_data( self::META_INTENT_SHORT_ID, $intent->intentShortId );
			$order->update_meta_data( self::META_CORRELATION_ID, $correlation_id );
			$order->save();

			return CheckoutResult::success( $intent->checkoutUrl, $intent->intentShortId );
		} catch ( MissingApiKeyException $e ) {
			return $this->fail(
				$e,
				FailureCode::MISSING_API_KEY,
				__( 'Spart is not yet configured. Please contact the merchant.', 'spart-woocommerce' ),
				'error',
				$api_key,
				$base_context
			);
		} catch ( FreeOrderException $e ) {
			return $this->fail(
				$e,
				FailureCode::FREE_ORDER,
				__( 'Spart cannot be used for orders with a zero total.', 'spart-woocommerce' ),
				'warning',
				$api_key,
				$base_context
			);
		} catch ( SpartAuthException $e ) {
			return $this->fail(
				$e,
				FailureCode::AUTH_FAILED,
				__( "We couldn't reach the payment provider. Please try another method.", 'spart-woocommerce' ),
				'error',
				$api_key,
				$base_context
			);
		} catch ( SpartValidationException | \InvalidArgumentException $e ) {
			return $this->fail(
				$e,
				$e instanceof SpartValidationException ? FailureCode::VALIDATION : FailureCode::MALFORMED,
				__( 'Some checkout data is invalid. Please review your cart.', 'spart-woocommerce' ),
				'warning',
				$api_key,
				$base_context
			);
		} catch ( SpartRateLimitException $e ) {
			return $this->fail(
				$e,
				FailureCode::RATE_LIMITED,
				__( 'Spart is busy right now. Please try again in a moment.', 'spart-woocommerce' ),
				'warning',
				$api_key,
				$base_context
			);
		} catch ( SpartTimeoutException $e ) {
			return $this->fail(
				$e,
				FailureCode::TIMEOUT,
				__( 'The payment provider took too long to respond. Please try again.', 'spart-woocommerce' ),
				'warning',
				$api_key,
				$base_context
			);
		} catch ( SpartTransportException $e ) {
			return $this->fail(
				$e,
				FailureCode::TRANSPORT,
				__( "We couldn't reach the payment provider. Please try again.", 'spart-woocommerce' ),
				'warning',
				$api_key,
				$base_context
			);
		} catch ( SpartServerException $e ) {
			return $this->fail(
				$e,
				FailureCode::SERVER_ERROR,
				__( 'Spart is having trouble right now. Please try again in a moment.', 'spart-woocommerce' ),
				'error',
				$api_key,
				$base_context
			);
		} catch ( SpartApiException $e ) {
			return $this->fail(
				$e,
				FailureCode::API_ERROR,
				__( "We couldn't start your payment. Please try again.", 'spart-woocommerce' ),
				'error',
				$api_key,
				$base_context
			);
		} catch ( \Throwable $e ) {
			return $this->fail(
				$e,
				FailureCode::UNKNOWN,
				__( "We couldn't start your payment. Please try again.", 'spart-woocommerce' ),
				'error',
				$api_key,
				$base_context
			);
		}//end try
	}

	/**
	 * Centralised failure helper — sanitises the throwable, emits a single
	 * structured log line at the requested level, and returns a CheckoutResult
	 * carrying the failure_code discriminator.
	 *
	 * @param \Throwable           $e                Originating exception.
	 * @param string               $failure_code     One of {@see FailureCode}::* constants.
	 * @param string               $customer_message Shopper-safe message.
	 * @param string               $level            Logger method ('warning' | 'error').
	 * @param string               $api_key          API key for sanitiser scrubbing.
	 * @param array<string, mixed> $base_context     Correlation/order context to merge in.
	 */
	private function fail(
		\Throwable $e,
		string $failure_code,
		string $customer_message,
		string $level,
		string $api_key,
		array $base_context
	): CheckoutResult {
		$log_message = ErrorSanitizer::sanitize( $e, $api_key );
		$this->logger->{$level}(
			$log_message,
			array_merge(
				$base_context,
				array(
					'event'        => LogEvents::CHECKOUT_FAILED,
					'failure_code' => $failure_code,
				)
			)
		);

		return CheckoutResult::failure( $customer_message, $log_message, $failure_code );
	}

	/**
	 * Resolve the cached site token, deriving it on the fly if missing
	 * (defensive — Activation::activate() should have written it).
	 */
	private function site_token(): string {
		$token = (string) \get_option( 'spart_site_token', '' );
		if ( '' === $token && function_exists( 'home_url' ) ) {
			$token = SessionIdComposer::derive_site_token( (string) \home_url() );
		}
		return $token;
	}
}
