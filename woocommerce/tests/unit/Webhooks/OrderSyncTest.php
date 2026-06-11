<?php
/**
 * Unit tests for Webhooks\OrderSync.
 *
 * @package Spart\WooCommerce\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Webhooks;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\Sdk\Webhooks\Event;
use Spart\Sdk\Webhooks\EventType;
use Spart\Sdk\Webhooks\OrderEnvelopeData;
use Spart\Sdk\Webhooks\PaymentEnvelopeData;
use Spart\Sdk\Webhooks\PaymentPartReleasedEnvelopeData;
use Spart\Sdk\Webhooks\Models\WebhookCharge;
use Spart\Sdk\Webhooks\Models\WebhookContact;
use Spart\Sdk\Webhooks\Models\WebhookMoney;
use Spart\Sdk\Webhooks\Models\WebhookPaymentPart;
use Spart\WooCommerce\Checkout\CheckoutSession;
use Spart\WooCommerce\Logging\SpartLoggerInterface;
use Spart\WooCommerce\Webhooks\OrderSync;

/**
 * @covers \Spart\WooCommerce\Webhooks\OrderSync
 */
final class OrderSyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( 'wc_price' )->alias(
			static function ( $amount, $args = array() ): string {
				$currency = is_array( $args ) && isset( $args['currency'] ) ? (string) $args['currency'] : 'USD';
				return $currency . ' ' . number_format( (float) $amount, 2 );
			}
		);
		Monkey\Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- We ARE the wp_json_encode stub.
			static fn ( $data ) => json_encode( $data )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_fires_before_apply_action_before_wc_mutation(): void {
		$call_order = array();

		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )
			->once()
			->whenHappen(
				static function () use ( &$call_order ): void {
					$call_order[] = 'action';
				}
			);

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'payment_complete' )
			->once()
			->with( 'ABCD-1234' )
			->andReturnUsing(
				static function () use ( &$call_order ): void {
					$call_order[] = 'payment_complete';
				}
			);

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->order_event( EventType::OrderCompleted, 'ABCD-1234' ) );

		$this->assertSame( array( 'action', 'payment_complete' ), $call_order );
	}

	public function test_intent_created_logs_info_and_makes_no_wc_mutation(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 42 );
		$order->shouldReceive( 'get_meta' )
			->with( CheckoutSession::META_CORRELATION_ID )
			->andReturn( '' );
		// No mutation: not asserting `shouldNotReceive` (Mockery without
		// `shouldReceive` on a method already fails on unexpected calls in
		// strict mocks; we explicitly assert via the logger).

		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )
			->once()
			->with(
				'webhook.intent.created',
				array(
					'wc_order_id' => 42,
					'event_id'    => 'evt_intent_1',
				)
			);

		$sync = new OrderSync( $logger );
		$sync->apply( $order, $this->intent_event( 'evt_intent_1' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_intent_created_log_includes_correlation_id_from_order_meta(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->once()->andReturn( 42 );
		$order->shouldReceive( 'get_meta' )
			->with( CheckoutSession::META_CORRELATION_ID )
			->andReturn( 'corr-abcdef-1234' );

		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )
			->once()
			->with(
				'webhook.intent.created',
				array(
					'wc_order_id'    => 42,
					'event_id'       => 'evt_intent_2',
					'correlation_id' => 'corr-abcdef-1234',
				)
			);

		$sync = new OrderSync( $logger );
		$sync->apply( $order, $this->intent_event( 'evt_intent_2' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_unknown_event_type_log_includes_correlation_id_when_present(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( CheckoutSession::META_CORRELATION_ID )
			->andReturn( 'corr-unknown' );

		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'warning' )
			->once()
			->with(
				'webhook.ordersync.unknown_event_type',
				array(
					'event_id'       => 'evt-future-2',
					'type'           => 'shipment.created',
					'correlation_id' => 'corr-unknown',
				)
			);

		$event = new Event(
			id:            'evt-future-2',
			type:          'shipment.created',
			knownType:     null,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          null,
			deliveryId:    'd-2',
			attempt:       1,
		);

		( new OrderSync( $logger ) )->apply( $order, $event );
		$this->addToAssertionCount( 1 );
	}

	public function test_payment_authorized_adds_order_note_with_formatted_amount(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 70 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'add_order_note' )
			->once()
			->with( 'Spart authorized payment pp-uuid-1 for USD 25.50' );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->payment_event( 'pp-uuid-1', 25.50 ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_payment_authorized_uses_event_currency_not_store_currency(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 71 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'add_order_note' )
			->once()
			->with( 'Spart authorized payment pp-uuid-eur for EUR 19.99' );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->payment_event( 'pp-uuid-eur', 19.99, 'EUR' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_order_completed_calls_payment_complete_with_short_id(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'ORD-9' );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->order_event( EventType::OrderCompleted, 'ORD-9' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_order_canceled_calls_update_status_cancelled(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'update_status' )->once()->with( 'cancelled', 'Cancelled in Spart' );
		$order->shouldReceive( 'get_items' )->once()->andReturn( array() );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->order_event( EventType::OrderCanceled, 'ORD-X' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_order_expired_calls_update_status_failed(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'update_status' )->once()->with( 'failed', 'Spart intent expired' );
		$order->shouldReceive( 'get_items' )->once()->andReturn( array() );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->order_event( EventType::OrderExpired, 'ORD-Y' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_order_canceled_restores_managed_stock_per_line_item(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();
		Monkey\Functions\expect( 'wc_update_product_stock' )->once()->with(
			Mockery::type( \WC_Product::class ),
			3,
			'increase'
		);

		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'managing_stock' )->once()->andReturn( true );

		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_meta' )->with( '_reduced_stock', true )->andReturn( 3 );
		$item->shouldReceive( 'get_product' )->once()->andReturn( $product );
		$item->shouldReceive( 'delete_meta_data' )->once()->with( '_reduced_stock' );
		$item->shouldReceive( 'save' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'update_status' )->once()->with( 'cancelled', 'Cancelled in Spart' );
		$order->shouldReceive( 'get_items' )->once()->andReturn( array( $item ) );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->order_event( EventType::OrderCanceled, 'ORD-Z' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_order_canceled_skips_stock_restore_when_no_reduced_meta(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();
		Monkey\Functions\expect( 'wc_update_product_stock' )->never();

		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_meta' )->with( '_reduced_stock', true )->andReturn( 0 );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'update_status' )->once()->with( 'cancelled', 'Cancelled in Spart' );
		$order->shouldReceive( 'get_items' )->once()->andReturn( array( $item ) );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->order_event( EventType::OrderCanceled, 'ORD-W' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_order_canceled_skips_stock_restore_when_product_not_managing_stock(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();
		Monkey\Functions\expect( 'wc_update_product_stock' )->never();

		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'managing_stock' )->once()->andReturn( false );

		$item = Mockery::mock( \WC_Order_Item_Product::class );
		$item->shouldReceive( 'get_meta' )->with( '_reduced_stock', true )->andReturn( 5 );
		$item->shouldReceive( 'get_product' )->once()->andReturn( $product );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'update_status' )->once()->with( 'cancelled', 'Cancelled in Spart' );
		$order->shouldReceive( 'get_items' )->once()->andReturn( array( $item ) );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->order_event( EventType::OrderCanceled, 'ORD-V' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_unknown_event_type_logs_warning_and_does_not_touch_order(): void {
		Monkey\Actions\expectDone( 'spart_webhook_before_apply' )->once();

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( CheckoutSession::META_CORRELATION_ID )
			->andReturn( '' );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'warning' )
			->once()
			->with(
				'webhook.ordersync.unknown_event_type',
				array(
					'event_id' => 'evt-future-1',
					'type'     => 'shipment.created',
				)
			);

		$event = new Event(
			id:            'evt-future-1',
			type:          'shipment.created',
			knownType:     null,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          null,
			deliveryId:    'd-1',
			attempt:       1,
		);

		( new OrderSync( $logger ) )->apply( $order, $event );
		$this->addToAssertionCount( 1 );
	}

	public function test_order_created_persists_versioned_payment_parts_snapshot(): void {
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 77 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )
			->with(
				OrderSync::META_PAYMENT_PARTS,
				Mockery::on(
					static function ( $json ) use ( &$captured ): bool {
						$captured = $json;
						return is_string( $json );
					}
				)
			);

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply(
			$order,
			$this->order_event_with_parts(
				EventType::OrderCreated,
				'ORD-CR-1',
				array( $this->payment_part( 'pp-1', 'captured', true ) )
			)
		);

		$decoded = json_decode( (string) $captured, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 1, $decoded['v'] );
		$this->assertCount( 1, $decoded['parts'] );

		$part = $decoded['parts'][0];
		$this->assertSame( 'pp-1', $part['id'] );
		$this->assertSame( 'captured', $part['status'] );
		$this->assertSame( 'Percent', $part['amountType'] );
		$this->assertTrue( $part['isSparter'] );
		$this->assertSame( '•••', $part['payeeName'] );
		$this->assertSame( 'EUR', $part['total']['currency'] );
		$this->assertEquals( 195.0, $part['net']['amount'] );
		$this->assertEquals( 5.0, $part['fees']['platform'] );

		// PII hard guard: the email field is never stored and no value carries an "@".
		$this->assertArrayNotHasKey( 'payeeEmail', $part );
		$this->assertStringNotContainsString( '@', (string) $captured );
	}

	public function test_sanitizes_payee_name_that_looks_like_an_email(): void {
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 77 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )
			->with(
				OrderSync::META_PAYMENT_PARTS,
				Mockery::on(
					static function ( $json ) use ( &$captured ): bool {
						$captured = $json;
						return true;
					}
				)
			);

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply(
			$order,
			$this->order_event_with_parts(
				EventType::OrderCreated,
				'ORD-PII',
				array( $this->payment_part( 'pp-x', 'captured', true, 'real.person@gmail.com' ) )
			)
		);

		$decoded = json_decode( (string) $captured, true );
		$this->assertSame( '•••', $decoded['parts'][0]['payeeName'] );
		$this->assertStringNotContainsString( '@', (string) $captured );
	}

	public function test_redacts_plain_payee_name_that_is_not_the_mask(): void {
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 79 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )
			->with(
				OrderSync::META_PAYMENT_PARTS,
				Mockery::on(
					static function ( $json ) use ( &$captured ): bool {
						$captured = $json;
						return true;
					}
				)
			);

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply(
			$order,
			$this->order_event_with_parts(
				EventType::OrderCreated,
				'ORD-NAME',
				array( $this->payment_part( 'pp-n', 'captured', true, 'Beppe Brescia' ) )
			)
		);

		$decoded = json_decode( (string) $captured, true );
		$this->assertSame( '•••', $decoded['parts'][0]['payeeName'] );
		$this->assertStringNotContainsString( 'Beppe', (string) $captured );
	}

	public function test_order_created_with_empty_parts_does_not_write_meta(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 78 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldNotReceive( 'update_meta_data' );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->order_event( EventType::OrderCreated, 'ORD-CR-2' ) );

		$this->assertTrue( true );
	}

	public function test_order_completed_also_persists_payment_parts(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 79 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )
			->with( OrderSync::META_PAYMENT_PARTS, Mockery::type( 'string' ) );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'ORD-CMP-1' );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply(
			$order,
			$this->order_event_with_parts(
				EventType::OrderCompleted,
				'ORD-CMP-1',
				array( $this->payment_part( 'pp-2', 'captured', false ) )
			)
		);

		$this->assertTrue( true );
	}

	/** Decode a captured JSON snapshot into an id-keyed parts map. */
	private function decode_parts( ?string $json ): array {
		$doc = json_decode( (string) $json, true );
		$out = array();
		foreach ( ( is_array( $doc['parts'] ?? null ) ? $doc['parts'] : array() ) as $p ) {
			$out[ $p['id'] ] = $p;
		}
		return $out;
	}

	public function test_full_snapshot_derives_status_per_part_from_timestamps(): void {
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 90 );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with(
			OrderSync::META_PAYMENT_PARTS,
			Mockery::on(
				static function ( $j ) use ( &$captured ): bool {
					$captured = $j;
					return is_string( $j );
				}
			)
		);

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply(
			$order,
			$this->order_event_with_parts(
				EventType::OrderCreated,
				'ORD-DS',
				array(
					$this->payment_part( 'A', 'ignored', false, '•••', '2026-06-09T00:20:00Z', null, null ),
					$this->payment_part( 'B', 'ignored', false, '•••', null, null, null ),
					$this->payment_part( 'C', 'ignored', false, '•••', '2026-06-09T00:20:00Z', null, '2026-06-09T00:30:00Z' ),
				)
			)
		);

		$parts = $this->decode_parts( $captured );
		$this->assertSame( 'authorized', $parts['A']['status'] );
		$this->assertSame( 'none', $parts['B']['status'] );
		$this->assertSame( 'released', $parts['C']['status'] );
	}

	public function test_stale_full_snapshot_does_not_downgrade_captured(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test fixture
		$prior    = json_encode(
			array(
				'v'     => 1,
				'parts' => array(
					array(
						'id'           => 'A',
						'amount'       => 50.0,
						'amountType'   => 'Percent',
						'status'       => 'captured',
						'isSparter'    => true,
						'payeeName'    => '•••',
						'net'          => array(
							'amount'   => 195.0,
							'currency' => 'EUR',
						),
						'total'        => array(
							'amount'   => 200.0,
							'currency' => 'EUR',
						),
						'fees'         => array( 'platform' => 5.0 ),
						'authorizedAt' => '2026-06-09T00:20:00Z',
						'capturedAt'   => '2026-06-09T00:25:00Z',
						'releasedAt'   => null,
					),
				),
			)
		);
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 91 );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( $prior );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with(
			OrderSync::META_PAYMENT_PARTS,
			Mockery::on(
				static function ( $j ) use ( &$captured ): bool {
					$captured = $j;
					return true;
				}
			)
		);

		$sync = new OrderSync( $this->null_logger() );
		// A late order.created with A still 'none' (all-null ts).
		$sync->apply(
			$order,
			$this->order_event_with_parts(
				EventType::OrderCreated,
				'ORD-STALE',
				array( $this->payment_part( 'A', 'ignored', true, '•••', null, null, null ) )
			)
		);

		$parts = $this->decode_parts( $captured );
		$this->assertSame( 'captured', $parts['A']['status'] );
		$this->assertSame( '2026-06-09T00:25:00Z', $parts['A']['capturedAt'] );
	}

	public function test_payment_authorized_patches_snapshot_to_authorized(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test fixture
		$prior    = json_encode(
			array(
				'v'     => 1,
				'parts' => array(
					array(
						'id'           => 'A',
						'amount'       => 50.0,
						'amountType'   => 'Percent',
						'status'       => 'none',
						'isSparter'    => false,
						'payeeName'    => '•••',
						'net'          => array(
							'amount'   => 195.0,
							'currency' => 'EUR',
						),
						'total'        => array(
							'amount'   => 200.0,
							'currency' => 'EUR',
						),
						'fees'         => array(),
						'authorizedAt' => null,
						'capturedAt'   => null,
						'releasedAt'   => null,
					),
				),
			)
		);
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 92 );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( $prior );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'add_order_note' );
		$order->shouldReceive( 'update_meta_data' )->with(
			OrderSync::META_PAYMENT_PARTS,
			Mockery::on(
				static function ( $j ) use ( &$captured ): bool {
					$captured = $j;
					return true;
				}
			)
		);

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->payment_authorized_event( 'A', '2026-06-09T00:20:00Z' ) );

		$parts = $this->decode_parts( $captured );
		$this->assertSame( 'authorized', $parts['A']['status'] );
		$this->assertSame( '2026-06-09T00:20:00Z', $parts['A']['authorizedAt'] );
		$this->assertSame( '•••', $parts['A']['payeeName'] );
	}

	public function test_payment_part_released_patches_snapshot_to_released(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test fixture
		$prior    = json_encode(
			array(
				'v'     => 1,
				'parts' => array(
					array(
						'id'           => 'A',
						'amount'       => 50.0,
						'amountType'   => 'Percent',
						'status'       => 'authorized',
						'isSparter'    => false,
						'payeeName'    => '•••',
						'net'          => array(
							'amount'   => 195.0,
							'currency' => 'EUR',
						),
						'total'        => array(
							'amount'   => 200.0,
							'currency' => 'EUR',
						),
						'fees'         => array(),
						'authorizedAt' => '2026-06-09T00:20:00Z',
						'capturedAt'   => null,
						'releasedAt'   => null,
					),
				),
			)
		);
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 93 );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( $prior );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'add_order_note' );
		$order->shouldReceive( 'update_meta_data' )->with(
			OrderSync::META_PAYMENT_PARTS,
			Mockery::on(
				static function ( $j ) use ( &$captured ): bool {
					$captured = $j;
					return true;
				}
			)
		);

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->payment_part_released_event( 'A', '2026-06-09T00:30:00Z' ) );

		$parts = $this->decode_parts( $captured );
		$this->assertSame( 'released', $parts['A']['status'] );
		$this->assertSame( '2026-06-09T00:30:00Z', $parts['A']['releasedAt'] );
	}

	public function test_patch_for_unknown_part_does_not_write(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test fixture
		$prior = json_encode(
			array(
				'v'     => 1,
				'parts' => array(
					array(
						'id'           => 'A',
						'amount'       => 50.0,
						'amountType'   => 'Percent',
						'status'       => 'none',
						'isSparter'    => false,
						'payeeName'    => '•••',
						'net'          => array(
							'amount'   => 1.0,
							'currency' => 'EUR',
						),
						'total'        => array(
							'amount'   => 1.0,
							'currency' => 'EUR',
						),
						'fees'         => array(),
						'authorizedAt' => null,
						'capturedAt'   => null,
						'releasedAt'   => null,
					),
				),
			)
		);
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 94 );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( $prior );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'add_order_note' );
		$order->shouldNotReceive( 'update_meta_data' );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->payment_authorized_event( 'ZZZ', '2026-06-09T00:20:00Z' ) );
		$this->assertTrue( true );
	}

	public function test_patch_without_snapshot_does_not_write(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 95 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'add_order_note' );
		$order->shouldNotReceive( 'update_meta_data' );

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->payment_authorized_event( 'A', '2026-06-09T00:20:00Z' ) );
		$this->assertTrue( true );
	}

	public function test_payment_authorized_patch_never_stores_payee_pii(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test fixture
		$prior    = json_encode(
			array(
				'v'     => 1,
				'parts' => array(
					array(
						'id'           => 'A',
						'amount'       => 50.0,
						'amountType'   => 'Percent',
						'status'       => 'none',
						'isSparter'    => false,
						'payeeName'    => '•••',
						'net'          => array(
							'amount'   => 1.0,
							'currency' => 'EUR',
						),
						'total'        => array(
							'amount'   => 1.0,
							'currency' => 'EUR',
						),
						'fees'         => array(),
						'authorizedAt' => null,
						'capturedAt'   => null,
						'releasedAt'   => null,
					),
				),
			)
		);
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 96 );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( $prior );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'add_order_note' );
		$order->shouldReceive( 'update_meta_data' )->with(
			OrderSync::META_PAYMENT_PARTS,
			Mockery::on(
				static function ( $j ) use ( &$captured ): bool {
					$captured = $j;
					return true;
				}
			)
		);

		$sync = new OrderSync( $this->null_logger() );
		// Authorized event carrying REAL PII in its payee:
		$sync->apply( $order, $this->payment_authorized_event( 'A', '2026-06-09T00:20:00Z', 'Beppe Brescia', 'obiuan+spartwp@gmail.com' ) );

		$this->assertStringNotContainsString( '@', (string) $captured );
		$this->assertStringNotContainsString( 'Beppe', (string) $captured );
		$this->assertStringNotContainsString( 'obiuan', (string) $captured );
		$this->assertSame( '•••', $this->decode_parts( $captured )['A']['payeeName'] );
	}

	public function test_released_then_late_authorized_replay_stays_released(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test fixture
		$prior    = json_encode(
			array(
				'v'     => 1,
				'parts' => array(
					array(
						'id'           => 'A',
						'amount'       => 50.0,
						'amountType'   => 'Percent',
						'status'       => 'released',
						'isSparter'    => false,
						'payeeName'    => '•••',
						'net'          => array(
							'amount'   => 1.0,
							'currency' => 'EUR',
						),
						'total'        => array(
							'amount'   => 1.0,
							'currency' => 'EUR',
						),
						'fees'         => array(),
						'authorizedAt' => '2026-06-09T00:20:00Z',
						'capturedAt'   => null,
						'releasedAt'   => '2026-06-09T00:30:00Z',
					),
				),
			)
		);
		$captured = null;
		$order    = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 97 );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( $prior );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'add_order_note' );
		$order->shouldReceive( 'update_meta_data' )->with(
			OrderSync::META_PAYMENT_PARTS,
			Mockery::on(
				static function ( $j ) use ( &$captured ): bool {
					$captured = $j;
					return true;
				}
			)
		);

		$sync = new OrderSync( $this->null_logger() );
		$sync->apply( $order, $this->payment_authorized_event( 'A', '2026-06-09T00:20:00Z' ) );

		$this->assertSame( 'released', $this->decode_parts( $captured )['A']['status'] );
	}

	private function null_logger(): SpartLoggerInterface {
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldIgnoreMissing();
		return $logger;
	}

	private function intent_event( string $id ): Event {
		return new Event(
			id:            $id,
			type:          'intent.created',
			knownType:     EventType::IntentCreated,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          null,
			deliveryId:    'd-1',
			attempt:       1,
		);
	}

	private function order_event( EventType $type, string $short_id ): Event {
		$money   = new WebhookMoney( currency: 'USD', amount: 50.00 );
		$contact = new WebhookContact( fullName: 'Test Sparter', email: 'test@example.com' );
		$data    = new OrderEnvelopeData(
			shortId:       $short_id,
			originalTotal: $money,
			finalTotal:    $money,
			lineItems:     array(),
			sparter:       $contact,
			sessionId:     'spart-wc-abcd1234-99',
			status:        'completed',
			countryCode:   'US',
			createdAt:     '2026-05-13T10:00:00Z',
		);

		return new Event(
			id:            'evt-' . $short_id,
			type:          $type->value,
			knownType:     $type,
			createdAt:     '2026-05-13T10:00:00Z',
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          $data,
			deliveryId:    'd-' . $short_id,
			attempt:       1,
		);
	}

	private function payment_part( string $id, string $status, bool $is_sparter, string $name = '•••', ?string $authorized_at = '2026-06-09T00:15:16Z', ?string $captured_at = '2026-06-09T00:15:18Z', ?string $released_at = null ): WebhookPaymentPart {
		$redacted = new WebhookContact( fullName: $name, email: '•••' );
		$charge   = new WebhookCharge(
			net:   new WebhookMoney( currency: 'EUR', amount: 195.00 ),
			total: new WebhookMoney( currency: 'EUR', amount: 200.00 ),
			fees:  array( 'platform' => 5.00 ),
		);

		return new WebhookPaymentPart(
			id:           $id,
			amount:       50.00,
			amountType:   'Percent',
			status:       $status,
			isSparter:    $is_sparter,
			payee:        $redacted,
			payeeCharge:  $charge,
			authorizedAt: $authorized_at,
			capturedAt:   $captured_at,
			releasedAt:   $released_at,
		);
	}

	/**
	 * Build an order.* event carrying payment parts.
	 *
	 * The order's own `createdAt` is held constant (it is a property of the
	 * order resource and is identical across every lifecycle event), while the
	 * event emission time ($event_created_at) varies per event — this mirrors
	 * production and is what the recency gate must key off.
	 *
	 * @param array<int, WebhookPaymentPart> $parts
	 */
	private function order_event_with_parts( EventType $type, string $short_id, array $parts, string $event_created_at = '2026-06-09T00:15:22Z' ): Event {
		$money   = new WebhookMoney( currency: 'EUR', amount: 200.00 );
		$contact = new WebhookContact( fullName: '•••', email: '•••' );
		$data    = new OrderEnvelopeData(
			shortId:       $short_id,
			originalTotal: $money,
			finalTotal:    $money,
			lineItems:     array(),
			sparter:       $contact,
			sessionId:     'spart-wc-abcd1234-99',
			status:        'placed',
			countryCode:   'IT',
			createdAt:     '2026-06-09T00:15:16Z',
			paymentParts:  $parts,
		);

		return new Event(
			id:            'evt-' . $short_id,
			type:          $type->value,
			knownType:     $type,
			createdAt:     $event_created_at,
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          $data,
			deliveryId:    'd-' . $short_id,
			attempt:       1,
		);
	}

	private function payment_authorized_event( string $payment_part_id, string $authorized_at, string $payee_name = '•••', string $payee_email = '•••' ): Event {
		$data = new PaymentEnvelopeData(
			orderShortId:     'ORD-1',
			sessionId:        'spart-wc-abcd1234-99',
			paymentPartId:    $payment_part_id,
			amountAuthorized: new WebhookMoney( currency: 'EUR', amount: 100.00 ),
			payee:            new WebhookContact( fullName: $payee_name, email: $payee_email ),
			authorizedAt:     $authorized_at,
		);
		return new Event(
			id:            'evt-auth',
			type:          'payment.authorized',
			knownType:     EventType::PaymentAuthorized,
			createdAt:     $authorized_at,
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          $data,
			deliveryId:    'd-auth',
			attempt:       1,
		);
	}

	private function payment_part_released_event( string $payment_part_id, string $released_at, string $payee_name = '•••', string $payee_email = '•••' ): Event {
		$data = new PaymentPartReleasedEnvelopeData(
			orderShortId:   'ORD-1',
			sessionId:      'spart-wc-abcd1234-99',
			paymentPartId:  $payment_part_id,
			amountReleased: new WebhookMoney( currency: 'EUR', amount: 100.00 ),
			payee:          new WebhookContact( fullName: $payee_name, email: $payee_email ),
			releasedAt:     $released_at,
		);
		return new Event(
			id:            'evt-rel',
			type:          'order.payment_part_released',
			knownType:     EventType::PaymentPartReleased,
			createdAt:     $released_at,
			apiVersion:    '1',
			merchantAppId: 'app_1',
			data:          $data,
			deliveryId:    'd-rel',
			attempt:       1,
		);
	}

	private function payment_event( string $payment_part_id, float $amount, string $currency = 'USD' ): Event {
		$money   = new WebhookMoney( currency: $currency, amount: $amount );
		$contact = new WebhookContact( fullName: 'Test Sparter', email: 'test@example.com' );
		$data    = new PaymentEnvelopeData(
			orderShortId:     'ORD-1',
			sessionId:        'spart-wc-abcd1234-99',
			paymentPartId:    $payment_part_id,
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
}
