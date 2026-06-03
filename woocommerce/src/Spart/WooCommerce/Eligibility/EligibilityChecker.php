<?php
/**
 * Eligibility\EligibilityChecker — admin-side gating for the Spart gateway.
 *
 * @package Spart\WooCommerce\Eligibility
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Eligibility;

use Spart\Sdk\Exceptions\SpartException;
use Spart\WooCommerce\Checkout\MissingApiKeyException;
use Spart\WooCommerce\Checkout\SpartClientFactoryInterface;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

/**
 * Decides whether the Spart gateway should be offered to a shopper at
 * checkout, based on the merchant's current `GET /api/merchants/eligibility`
 * verdict.
 *
 * Used from {@see \Spart\WooCommerce\Gateway\WC_Gateway_Spart::is_available()}
 * to hide the Spart payment method when the merchant cannot start payment
 * intents (e.g. Stripe Connect not yet linked, onboarding incomplete).
 *
 * # Caching
 *
 * Every shopper hitting checkout triggers `is_available()`, so a synchronous
 * SDK call on every page load is unacceptable. Three transients give the
 * gateway a "live"-feeling verdict without saturating the API:
 *
 *  - {@see self::POSITIVE_TRANSIENT} (TTL {@see self::POSITIVE_TTL_SECONDS})
 *    — eligible merchants stay eligible for ~5 minutes; reflects a stable
 *    "ready to sell" state where extra freshness has no shopper benefit.
 *  - {@see self::NEGATIVE_TRANSIENT} (TTL {@see self::NEGATIVE_TTL_SECONDS})
 *    — ineligible merchants get re-checked sooner so completing onboarding
 *    promotes them back into the checkout within ~30 seconds.
 *  - {@see self::ERROR_TRANSIENT} (TTL {@see self::ERROR_TTL_SECONDS})
 *    — short-lived breaker that suppresses log spam when the Spart API is
 *    transiently unreachable; the verdict during this window is "allow"
 *    (fail-open, see below).
 *
 * The three keys are distinct so {@see self::purge_cache()} can wipe them
 * together when the merchant changes settings — see the priority-20 filter
 * wired in {@see \Spart\WooCommerce\Gateway\WC_Gateway_Spart}.
 *
 * # Fail-open policy
 *
 * Any of:
 *   - missing API key ({@see MissingApiKeyException}),
 *   - SDK exception ({@see SpartException} — timeout, transport, 5xx, etc.),
 *   - a successful response that decoded to `eligible=false`,
 * deserves different handling. The first two return `true` ("allow") so a
 * misconfigured-key or temporary-API-outage merchant does NOT lose
 * checkout traffic — the customer will still be able to attempt payment
 * and the gateway's own checkout call will surface the real failure to
 * the customer with a meaningful error message. The third returns
 * `false` (hide the gateway) — when the API explicitly says "no", trust
 * it.
 *
 * Not marked `final` so {@see \Spart\WooCommerce\Plugin::set_eligibility_checker_for_tests()}
 * can accept a deterministic test-only subclass that returns a fixed
 * verdict without hitting WP transients or the SDK.
 */
class EligibilityChecker {

	/**
	 * Transient key holding the cached positive ("eligible") verdict.
	 *
	 * Value when present: `'1'`.
	 */
	public const POSITIVE_TRANSIENT = 'spart_eligibility_positive';

	/**
	 * Transient key holding the cached negative ("ineligible") verdict.
	 *
	 * Value when present: `'1'`.
	 */
	public const NEGATIVE_TRANSIENT = 'spart_eligibility_negative';

	/**
	 * Transient key holding the short-lived "API error / unreachable"
	 * breaker. While present the checker returns `true` without calling
	 * the SDK again — see the fail-open notes on the class docblock.
	 *
	 * Value when present: `'1'`.
	 */
	public const ERROR_TRANSIENT = 'spart_eligibility_error';

	/**
	 * TTL (seconds) for {@see self::POSITIVE_TRANSIENT}.
	 *
	 * Set to 5 minutes — eligible merchants are a stable steady-state, so a
	 * few minutes of staleness is invisible to the shopper. Inlined as a
	 * literal (rather than referencing the WP `MINUTE_IN_SECONDS` constant)
	 * so the class loads in unit tests that don't bootstrap WordPress.
	 */
	public const POSITIVE_TTL_SECONDS = 300;

	/**
	 * TTL (seconds) for {@see self::NEGATIVE_TRANSIENT}.
	 *
	 * Tighter than the positive TTL so completing onboarding promotes the
	 * gateway back into the checkout within ~30 seconds.
	 */
	public const NEGATIVE_TTL_SECONDS = 30;

	/**
	 * TTL (seconds) for {@see self::ERROR_TRANSIENT}.
	 *
	 * Mirrors the negative TTL: long enough to dampen log spam during a
	 * brief API blip, short enough that genuine misconfiguration is
	 * surfaced quickly when an admin watches the checkout.
	 */
	public const ERROR_TTL_SECONDS = 30;

	/**
	 * Per-request timeout (seconds) for the eligibility probe.
	 *
	 * Much smaller than the customer-facing 30 s checkout timeout — a
	 * stalled API must not stall every shopper's checkout page render
	 * waiting for the gating decision.
	 */
	public const TIMEOUT_SECONDS = 2;

	/**
	 * Construct a checker bound to a per-call SDK client factory.
	 *
	 * @param SpartClientFactoryInterface $factory Factory producing per-call SDK clients with a constrained timeout.
	 * @param SpartLoggerInterface|null   $logger  Optional structured logger; falls back to silent if null.
	 */
	public function __construct(
		private readonly SpartClientFactoryInterface $factory,
		private readonly ?SpartLoggerInterface $logger = null,
	) {}

	/**
	 * Resolve whether the Spart gateway should be offered at checkout.
	 *
	 * Order of evaluation:
	 *   1. Cached positive  → `true`  (no SDK call)
	 *   2. Cached negative  → `false` (no SDK call)
	 *   3. Cached error     → `true`  (fail-open, no SDK call)
	 *   4. Call SDK; cache + return verdict.
	 */
	public function is_eligible(): bool {
		if ( '1' === (string) \get_transient( self::POSITIVE_TRANSIENT ) ) {
			return true;
		}

		if ( '1' === (string) \get_transient( self::NEGATIVE_TRANSIENT ) ) {
			return false;
		}

		if ( '1' === (string) \get_transient( self::ERROR_TRANSIENT ) ) {
			return true;
		}

		try {
			$client      = $this->factory->create_with_timeout( self::TIMEOUT_SECONDS );
			$eligibility = $client->merchants()->eligibility();
		} catch ( MissingApiKeyException $e ) {
			// Missing key is a configuration state, not an outage. Fail open
			// with a short breaker so we don't recompute every page load,
			// but don't emit ELIGIBILITY_CHECK_FAILED (the merchant will see
			// the gateway settings notice instead).
			\set_transient( self::ERROR_TRANSIENT, '1', self::ERROR_TTL_SECONDS );
			return true;
		} catch ( SpartException $e ) {
			$this->logger?->warning(
				'Eligibility check failed; allowing checkout.',
				array(
					'event'          => LogEvents::ELIGIBILITY_CHECK_FAILED,
					'exception_type' => $e::class,
					'message'        => $e->getMessage(),
				)
			);
			\set_transient( self::ERROR_TRANSIENT, '1', self::ERROR_TTL_SECONDS );
			return true;
		}//end try

		if ( $eligibility->eligible ) {
			\set_transient( self::POSITIVE_TRANSIENT, '1', self::POSITIVE_TTL_SECONDS );
			return true;
		}

		\set_transient( self::NEGATIVE_TRANSIENT, '1', self::NEGATIVE_TTL_SECONDS );
		return false;
	}

	/**
	 * Drop every cached verdict so the next {@see self::is_eligible()}
	 * call re-queries the API.
	 *
	 * Wired into the priority-20 sanitised-settings filter so a merchant
	 * who, say, swaps environments or pastes a new API key immediately
	 * sees the gateway reflect the new state instead of waiting up to
	 * five minutes for the positive TTL to expire.
	 */
	public static function purge_cache(): void {
		\delete_transient( self::POSITIVE_TRANSIENT );
		\delete_transient( self::NEGATIVE_TRANSIENT );
		\delete_transient( self::ERROR_TRANSIENT );
	}
}
