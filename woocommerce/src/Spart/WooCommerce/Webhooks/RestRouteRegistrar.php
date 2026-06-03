<?php
/**
 * Webhooks\RestRouteRegistrar — registers the POST /spart/v1/webhook REST route.
 *
 * @package Spart\WooCommerce\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Webhooks;

/**
 * Registers the WP REST API route that backs Spart's webhook delivery.
 *
 * The class is a thin shim whose only job is to invoke
 * register_rest_route() with the canonical namespace, path and args.
 * Plugin::on_plugins_loaded() wires it via add_action('rest_api_init').
 *
 * The route lives under namespace 'spart/v1' so future Spart REST endpoints
 * can be added under the same prefix without colliding with WooCommerce's
 * own 'wc/v3' or other plugins' namespaces.
 *
 * permission_callback is '__return_true' on purpose: the authorization
 * mechanism for webhook deliveries is the HMAC-SHA256 signature in the
 * X-Spart-Signature header (verified inside WebhookReceiver::handle()).
 * WP capability checks would gate against logged-in users; webhooks
 * arrive unauthenticated by definition. See
 * the webhook receiver design
 * (lines 235-251) for the locked rationale.
 */
final class RestRouteRegistrar {

	/**
	 * REST API namespace for all Spart routes.
	 */
	public const NAMESPACE = 'spart/v1';

	/**
	 * REST API route path (relative to the namespace).
	 */
	public const ROUTE = '/webhook';

	/**
	 * Wire the registrar with the receiver that handles incoming requests.
	 *
	 * @param WebhookReceiver $receiver Orchestrator invoked as the route callback.
	 */
	public function __construct(
		private readonly WebhookReceiver $receiver,
	) {
	}

	/**
	 * Register the route on rest_api_init.
	 *
	 * Idempotent in practice — register_rest_route() with identical args
	 * is a no-op if WP has already registered the route in the same
	 * request (WP keeps a static map keyed by namespace+route).
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->receiver, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}
}
