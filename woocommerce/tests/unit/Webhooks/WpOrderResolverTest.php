<?php
/**
 * Unit tests for Webhooks\WpOrderResolver.
 *
 * @package Spart\WooCommerce\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Webhooks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\Sdk\Webhooks\Event;
use Spart\Sdk\Webhooks\EventType;
use Spart\Sdk\Webhooks\IntentEnvelopeData;
use Spart\Sdk\Webhooks\Models\WebhookContact;
use Spart\Sdk\Webhooks\Models\WebhookMoney;
use Spart\Sdk\Webhooks\OrderEnvelopeData;
use Spart\Sdk\Webhooks\PaymentEnvelopeData;
use Spart\Sdk\Webhooks\PaymentPartReleasedEnvelopeData;
use Spart\Sdk\Webhooks\TestEnvelopeData;
use Spart\WooCommerce\Webhooks\ResolverResult;
use Spart\WooCommerce\Webhooks\WpOrderResolver;

/**
 * @covers \Spart\WooCommerce\Webhooks\WpOrderResolver
 */
final class WpOrderResolverTest extends TestCase {

	private const SITE_TOKEN = 'abcd1234';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_webhook_test_event_returns_test_event_reason(): void {
		$event  = $this->test_event();
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertInstanceOf( ResolverResult::class, $result );
		$this->assertSame( ResolverResult::REASON_TEST_EVENT, $result->reason );
	}

	public function test_unknown_event_type_returns_unknown_event_reason(): void {
		$event = new Event(
			id:            'evt-future',
			type:          'shipment.created',
			knownType:     null,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          null,
			deliveryId:    'd-1',
			attempt:       1,
		);

		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertInstanceOf( ResolverResult::class, $result );
		$this->assertSame( ResolverResult::REASON_UNKNOWN_EVENT, $result->reason );
	}

	public function test_event_without_session_id_returns_no_session_id_reason(): void {
		$event  = $this->order_event( EventType::OrderCompleted, null );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertInstanceOf( ResolverResult::class, $result );
		$this->assertSame( ResolverResult::REASON_NO_SESSION_ID, $result->reason );
	}

	public function test_event_with_empty_session_id_returns_no_session_id_reason(): void {
		$event  = $this->order_event( EventType::OrderCompleted, '' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertInstanceOf( ResolverResult::class, $result );
		$this->assertSame( ResolverResult::REASON_NO_SESSION_ID, $result->reason );
	}

	public function test_session_id_belonging_to_sibling_site_returns_sibling_site_reason(): void {
		$event  = $this->order_event( EventType::OrderCompleted, 'spart-wc-deadbeef-99' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertInstanceOf( ResolverResult::class, $result );
		$this->assertSame( ResolverResult::REASON_SIBLING_SITE, $result->reason );
	}

	public function test_malformed_session_id_for_local_site_returns_malformed_session_reason(): void {
		// Belongs to local site_token but does not match the order-id regex.
		$event  = $this->order_event( EventType::OrderCompleted, 'spart-wc-abcd1234-not-a-number' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertInstanceOf( ResolverResult::class, $result );
		$this->assertSame( ResolverResult::REASON_MALFORMED_SESSION, $result->reason );
	}

	public function test_wc_get_order_returns_false_returns_order_not_found_reason(): void {
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 99 )
			->andReturn( false );

		$event  = $this->order_event( EventType::OrderCompleted, 'spart-wc-abcd1234-99' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertInstanceOf( ResolverResult::class, $result );
		$this->assertSame( ResolverResult::REASON_ORDER_NOT_FOUND, $result->reason );
	}

	public function test_wc_get_order_returns_trashed_order_returns_order_trashed_reason(): void {
		$trashed = Mockery::mock( \WC_Order::class );
		$trashed->shouldReceive( 'get_status' )->once()->andReturn( 'trash' );

		Functions\expect( 'wc_get_order' )
			->once()
			->with( 99 )
			->andReturn( $trashed );

		$event  = $this->order_event( EventType::OrderCompleted, 'spart-wc-abcd1234-99' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertInstanceOf( ResolverResult::class, $result );
		$this->assertSame( ResolverResult::REASON_ORDER_TRASHED, $result->reason );
	}

	public function test_happy_path_returns_resolved_wc_order(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_status' )->once()->andReturn( 'pending' );

		Functions\expect( 'wc_get_order' )
			->once()
			->with( 99 )
			->andReturn( $order );

		$event  = $this->order_event( EventType::OrderCompleted, 'spart-wc-abcd1234-99' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertSame( $order, $result );
	}

	public function test_payment_envelope_session_id_is_extracted(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_status' )->once()->andReturn( 'pending' );

		Functions\expect( 'wc_get_order' )
			->once()
			->with( 7 )
			->andReturn( $order );

		$event  = $this->payment_event( 'spart-wc-abcd1234-7' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertSame( $order, $result );
	}

	public function test_intent_envelope_session_id_is_extracted(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_status' )->once()->andReturn( 'pending' );

		Functions\expect( 'wc_get_order' )
			->once()
			->with( 13 )
			->andReturn( $order );

		$event  = $this->intent_event( 'spart-wc-abcd1234-13' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertSame( $order, $result );
	}

	public function test_payment_part_released_envelope_session_id_is_extracted(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_status' )->once()->andReturn( 'pending' );

		Functions\expect( 'wc_get_order' )
			->once()
			->with( 21 )
			->andReturn( $order );

		$event  = $this->payment_part_released_event( 'spart-wc-abcd1234-21' );
		$result = ( new WpOrderResolver( self::SITE_TOKEN ) )->resolve( $event );

		$this->assertSame( $order, $result );
	}

	private function test_event(): Event {
		return new Event(
			id:            'evt-test',
			type:          'webhook.test',
			knownType:     EventType::WebhookTest,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          new TestEnvelopeData(
				merchantAppName: 'TestApp',
				sentAt:          '2026-05-13T10:00:00Z',
			),
			deliveryId:    'd-test',
			attempt:       1,
		);
	}

	private function order_event( EventType $type, ?string $session_id ): Event {
		$money   = new WebhookMoney( currency: 'USD', amount: 50.00 );
		$contact = new WebhookContact( fullName: 'Test Sparter', email: 'test@example.com' );
		$data    = new OrderEnvelopeData(
			shortId:       'ORD-1',
			originalTotal: $money,
			finalTotal:    $money,
			lineItems:     array(),
			sparter:       $contact,
			sessionId:     $session_id,
			status:        'completed',
			countryCode:   'US',
			createdAt:     '2026-05-13T10:00:00Z',
		);

		return new Event(
			id:            'evt-' . $type->value,
			type:          $type->value,
			knownType:     $type,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          $data,
			deliveryId:    'd-' . $type->value,
			attempt:       1,
		);
	}

	private function payment_event( string $session_id ): Event {
		$money   = new WebhookMoney( currency: 'USD', amount: 25.00 );
		$contact = new WebhookContact( fullName: 'Payee', email: 'payee@example.com' );
		$data    = new PaymentEnvelopeData(
			orderShortId:     'ORD-1',
			sessionId:        $session_id,
			paymentPartId:    'pp-1',
			amountAuthorized: $money,
			payee:            $contact,
			authorizedAt:     '2026-05-13T10:00:00Z',
		);

		return new Event(
			id:            'evt-pay',
			type:          'payment.authorized',
			knownType:     EventType::PaymentAuthorized,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          $data,
			deliveryId:    'd-pay',
			attempt:       1,
		);
	}

	private function payment_part_released_event( string $session_id ): Event {
		$money   = new WebhookMoney( currency: 'USD', amount: 25.00 );
		$contact = new WebhookContact( fullName: 'Payee', email: 'payee@example.com' );
		$data    = new PaymentPartReleasedEnvelopeData(
			orderShortId:   'ORD-1',
			sessionId:      $session_id,
			paymentPartId:  'pp-1',
			amountReleased: $money,
			payee:          $contact,
			releasedAt:     '2026-05-13T10:00:00Z',
		);

		return new Event(
			id:            'evt-rel',
			type:          'order.payment_part_released',
			knownType:     EventType::PaymentPartReleased,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          $data,
			deliveryId:    'd-rel',
			attempt:       1,
		);
	}

	private function intent_event( string $session_id ): Event {
		$money   = new WebhookMoney( currency: 'USD', amount: 100.00 );
		$contact = new WebhookContact( fullName: 'Sparter', email: 'sparter@example.com' );
		$data    = new IntentEnvelopeData(
			shortId:     'INT-1',
			total:       $money,
			lineItems:   array(),
			sparter:     $contact,
			sessionId:   $session_id,
			countryCode: 'US',
			createdAt:   '2026-05-13T10:00:00Z',
			expiresOn:   '2026-05-14T10:00:00Z',
		);

		return new Event(
			id:            'evt-int',
			type:          'intent.created',
			knownType:     EventType::IntentCreated,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          $data,
			deliveryId:    'd-int',
			attempt:       1,
		);
	}
}
