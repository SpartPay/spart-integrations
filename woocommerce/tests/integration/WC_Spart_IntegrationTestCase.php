<?php
/**
 * Base class for integration tests that exercise the plugin against the
 * stub-spart sidecar.
 *
 * @package Spart\WooCommerce\Tests\Integration
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Checkout\SessionIdComposer;
use Spart\WooCommerce\Persistence\WebhookDeliveriesSchema;

/**
 * Provides scenario / reset / recorded-request helpers and a fresh-order
 * factory for every test that talks to the stub.
 */
abstract class WC_Spart_IntegrationTestCase extends TestCase {

	protected const STUB_BASE = 'http://stub-spart:8080';

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_SPART_BASE_URL' ) ) {
			define( 'WP_SPART_BASE_URL', self::STUB_BASE );
		}

		$this->reset_stub();

		update_option(
			'woocommerce_spart_settings',
			array(
				'enabled'        => 'yes',
				'title'          => 'Pay with Spart',
				'description'    => 'Installments via Spart',
				'api_key'        => 'sk_test_integration',
				'webhook_secret' => 'whsec_test',
				'environment'    => 'live',
				'debug_logging'  => 'no',
			)
		);
	}

	protected function set_stub_scenario( string $scenario ): void {
		$this->stub_post( '/__stub/scenario', array( 'scenario' => $scenario ) );
	}

	protected function reset_stub(): void {
		$this->stub_post( '/__stub/reset', array() );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function stub_recorded_requests(): array {
		$resp    = wp_remote_get( self::STUB_BASE . '/__stub/recorded', array( 'timeout' => 5 ) );
		$body    = is_array( $resp ) ? (string) wp_remote_retrieve_body( $resp ) : '';
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$recorded = $decoded['recorded'] ?? $decoded;
		return is_array( $recorded ) ? $recorded : array();
	}

	/**
	 * @param array<string, mixed> $body Body to send as JSON.
	 */
	private function stub_post( string $path, array $body ): void {
		wp_remote_post(
			self::STUB_BASE . $path,
			array(
				'body'    => wp_json_encode( $body ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 5,
			)
		);
	}

	/**
	 * Push the per-test webhook signing secret into the stub so that
	 * subsequent {@see deliver_webhook()} calls produce a HMAC the WC
	 * plugin will accept. The secret must match the plugin setting
	 * `woocommerce_spart_settings.webhook_secret` (set in setUp() to
	 * `whsec_test`); supplying a deliberately-wrong secret is the canonical
	 * way to test the bad-signature 401 branch.
	 *
	 * Note: the stub always re-applies a scenario field on this endpoint,
	 * so we explicitly send 'happy' to keep `/api/intents` in a sane state
	 * for any test that mixes intent + webhook flows.
	 *
	 * @param string $secret Webhook signing secret (raw, not the `whsec_` prefix).
	 * @return void
	 */
	protected function set_signing_secret( string $secret ): void {
		$this->stub_post(
			'/__stub/scenario',
			array(
				'scenario'       => 'happy',
				'signing_secret' => $secret,
			)
		);
	}

	/**
	 * Deliver a single webhook to the WC test instance via the stub-spart
	 * sidecar's signing path. Returns the stub's response envelope, which
	 * includes the WC target's status + body + the delivery_id that was
	 * sent (auto-generated UUID v4 when $delivery_id is null).
	 *
	 * @param string                $event_type  Spart event type (e.g. 'order.completed').
	 * @param string|null           $session_id  Spart session id; recorded only.
	 * @param array<string, mixed>  $payload     Becomes the event envelope's `data` field.
	 * @param int                   $attempt     Spart attempt number (X-Spart-Webhook-Attempt header).
	 * @param string|null           $delivery_id Override the auto-generated delivery_id (use to test dedupe).
	 * @param int|null              $timestamp   Override the signing timestamp (Unix seconds; default time()). Set in the past to drive the expired-signature 401 branch.
	 * @return array{status:int, body:string, headers_sent:array<string,string>, delivery_id:string}
	 */
	protected function deliver_webhook(
		string $event_type,
		?string $session_id,
		array $payload,
		int $attempt = 1,
		?string $delivery_id = null,
		?int $timestamp = null
	): array {
		$body = array(
			'event_type' => $event_type,
			'session_id' => $session_id,
			'payload'    => $payload,
			'attempt'    => $attempt,
		);
		if ( null !== $delivery_id ) {
			$body['delivery_id'] = $delivery_id;
		}
		if ( null !== $timestamp ) {
			$body['timestamp'] = $timestamp;
		}

		$resp = wp_remote_post(
			self::STUB_BASE . '/__stub/deliver-webhook',
			array(
				'body'    => wp_json_encode( $body ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 30,
			)
		);

		$raw     = is_array( $resp ) ? (string) wp_remote_retrieve_body( $resp ) : '';
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$this->fail( 'Stub returned non-JSON response from /__stub/deliver-webhook: ' . $raw );
		}

		// The REST handler runs in a separate PHP-FPM worker process from this
		// PHPUnit test. WP's wp_options (`alloptions`) and post_meta caches are
		// per-process; mutations made by the REST worker (e.g. wc_increase_stock,
		// update_option from the crash-injector mu-plugin) are NOT visible to
		// this process's caches. Flush after every delivery so post-webhook
		// assertions read fresh from MySQL.
		wp_cache_flush();

		return array(
			'status'       => (int) ( $decoded['status'] ?? 0 ),
			'body'         => (string) ( $decoded['body'] ?? '' ),
			'headers_sent' => is_array( $decoded['headers_sent'] ?? null ) ? $decoded['headers_sent'] : array(),
			'delivery_id'  => (string) ( $decoded['delivery_id'] ?? '' ),
		);
	}

	/**
	 * Look up a row in the dedupe table by delivery_id. Returns the
	 * associative-array shape WP returns from $wpdb->get_row(..., ARRAY_A),
	 * or null when no row exists.
	 *
	 * @param string $delivery_id Spart-issued delivery id.
	 * @return array<string, mixed>|null
	 */
	protected function find_dedupe_row( string $delivery_id ): ?array {
		global $wpdb;
		$table = WebhookDeliveriesSchema::table_name( $wpdb->prefix );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from constant; delivery_id parameterized via prepare(); test-only direct query.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from constant.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE delivery_id = %s LIMIT 1", $delivery_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Assert the dedupe table has a row with the given delivery_id and
	 * the given state ('received', 'applied', 'skipped', 'errored').
	 *
	 * @param string $delivery_id    Spart-issued delivery id.
	 * @param string $expected_state Expected state column value.
	 * @return void
	 */
	protected function assert_dedupe_state( string $delivery_id, string $expected_state ): void {
		$row = $this->find_dedupe_row( $delivery_id );
		$this->assertNotNull(
			$row,
			'Expected dedupe row for delivery_id ' . $delivery_id . ', found none.'
		);
		$this->assertSame(
			$expected_state,
			(string) $row['state'],
			'Dedupe state mismatch for delivery_id ' . $delivery_id
		);
	}

	/**
	 * Build a real WC order via WC factories — exercises HPOS read/write paths.
	 */
	protected function make_order( string $total = '129.99', string $currency = 'USD' ): \WC_Order {
		$order = wc_create_order();
		$order->set_currency( $currency );
		$order->set_billing_email( 'jane@example.com' );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'T-shirt' );
		$product->set_regular_price( (string) ( (float) $total ) );
		$product->save();

		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Build a real WC order whose only line is a virtual+downloadable
	 * product. WC's payment_complete() routes virtual-only orders to
	 * 'completed' (instead of the default 'processing' for shippable
	 * orders). Used by the OrderCompleted digital-branch test.
	 */
	protected function make_digital_order( string $total = '49.99' ): \WC_Order {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_billing_email( 'jane@example.com' );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'E-book' );
		$product->set_regular_price( (string) ( (float) $total ) );
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$product->save();

		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Build a real WC order backed by a stock-managed product whose
	 * stock has been reduced to simulate the post-checkout state. Used
	 * by the OrderCanceled stock-restoration test.
	 *
	 * @param int $initial_stock Stock count BEFORE the order reduces it.
	 * @return array{order: \WC_Order, product: \WC_Product_Simple, initial_stock: int}
	 */
	protected function make_order_with_reduced_managed_stock( int $initial_stock = 10 ): array {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Stocked T-shirt' );
		$product->set_regular_price( '19.99' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $initial_stock );
		$product->save();

		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_billing_email( 'jane@example.com' );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		// Promote to a real status before reducing stock — wc_create_order()
		// leaves a new order in 'auto-draft' (WC's internal placeholder for
		// not-yet-checked-out orders), and WC's stock-restore action chain on
		// 'cancelled' relies on the from-status being one that is treated as
		// 'has reduced stock'. In production, stock is reduced after the order
		// transitions to pending/on-hold during checkout — so simulate that
		// real lifecycle here.
		$order->set_status( 'pending' );
		$order->save();

		wc_reduce_stock_levels( $order );

		return array(
			'order'         => $order,
			'product'       => $product,
			'initial_stock' => $initial_stock,
		);
	}

	/**
	 * Compose a Spart session ID that the plugin's WpOrderResolver will
	 * accept (correct site_token + correct order_id). Reads the
	 * spart_site_token option set by Activation::activate(); falls back
	 * to deriving from home_url() if the option is somehow absent
	 * (matches CheckoutSession::site_token()'s fallback so a missing
	 * option behaves identically to production).
	 */
	protected function compose_session_id( int $order_id ): string {
		$token = (string) get_option( 'spart_site_token', '' );
		if ( 8 !== strlen( $token ) || ! ctype_xdigit( $token ) ) {
			$token = SessionIdComposer::derive_site_token( (string) home_url() );
		}
		return ( new SessionIdComposer( $token ) )->compose( $order_id );
	}

	/**
	 * Build the `data.order` envelope shape for OrderCompleted /
	 * OrderCanceled / OrderExpired events. Mirrors
	 * Spart\Sdk\Webhooks\OrderEnvelopeData::fromArray() field requirements.
	 *
	 * @param \WC_Order $order  Order to compose a sessionId from.
	 * @param string    $status Lowercased Spart order status string.
	 * @return array<string, mixed>
	 */
	protected function order_envelope_payload( \WC_Order $order, string $status ): array {
		return array(
			'order' => array(
				'shortId'       => 'spart_short_' . $order->get_id(),
				'originalTotal' => array(
					'currency' => 'USD',
					'amount'   => 129.99,
				),
				'finalTotal'    => array(
					'currency' => 'USD',
					'amount'   => 129.99,
				),
				'lineItems'     => array(
					array(
						'name'     => 'T-shirt',
						'quantity' => 1,
					),
				),
				'sparter'       => array(
					'fullName' => 'Jane Doe',
					'email'    => 'jane@example.com',
				),
				'sessionId'     => $this->compose_session_id( $order->get_id() ),
				'status'        => $status,
				'countryCode'   => 'US',
				'createdAt'     => gmdate( 'c' ),
			),
		);
	}

	/**
	 * Build the `data.payment` envelope shape for PaymentAuthorized.
	 *
	 * @param \WC_Order $order  Order to compose a sessionId from.
	 * @param string    $part_id PaymentPartId GUID string.
	 * @param float     $amount  Authorized amount.
	 * @return array<string, mixed>
	 */
	protected function payment_envelope_payload( \WC_Order $order, string $part_id, float $amount ): array {
		return array(
			'payment' => array(
				'orderShortId'     => 'spart_short_' . $order->get_id(),
				'sessionId'        => $this->compose_session_id( $order->get_id() ),
				'paymentPartId'    => $part_id,
				'amountAuthorized' => array(
					'currency' => 'USD',
					'amount'   => $amount,
				),
				'payee'            => array(
					'fullName' => 'Jane Doe',
					'email'    => 'jane@example.com',
				),
				'authorizedAt'     => gmdate( 'c' ),
			),
		);
	}

	/**
	 * Build the `data.intent` envelope shape for IntentCreated.
	 *
	 * @param \WC_Order $order  Order to compose a sessionId from.
	 * @return array<string, mixed>
	 */
	protected function intent_envelope_payload( \WC_Order $order ): array {
		return array(
			'intent' => array(
				'shortId'     => 'spart_short_' . $order->get_id(),
				'total'       => array(
					'currency' => 'USD',
					'amount'   => 129.99,
				),
				'lineItems'   => array(
					array(
						'name'     => 'T-shirt',
						'quantity' => 1,
					),
				),
				'sparter'     => array(
					'fullName' => 'Jane Doe',
					'email'    => 'jane@example.com',
				),
				'sessionId'   => $this->compose_session_id( $order->get_id() ),
				'countryCode' => 'US',
				'createdAt'   => gmdate( 'c' ),
				'expiresOn'   => gmdate( 'c', time() + 3600 ),
			),
		);
	}
}
