<?php
/**
 * Unit tests for Webhooks\DeliveryRow.
 *
 * @package Spart\WooCommerce\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Webhooks\DeliveryRow;

/**
 * @covers \Spart\WooCommerce\Webhooks\DeliveryRow
 */
final class DeliveryRowTest extends TestCase {

	public function test_constructs_with_all_fields_populated(): void {
		$row = new DeliveryRow(
			id: 42,
			delivery_id: 'd-uuid-123',
			event_type: 'order.completed',
			wc_order_id: 99,
			state: 'applied',
			attempt_count: 1,
			received_at: '2026-05-13 10:00:00',
			applied_at: '2026-05-13 10:00:01',
			error_message: null,
		);

		$this->assertSame( 42, $row->id );
		$this->assertSame( 'd-uuid-123', $row->delivery_id );
		$this->assertSame( 'order.completed', $row->event_type );
		$this->assertSame( 99, $row->wc_order_id );
		$this->assertSame( 'applied', $row->state );
		$this->assertSame( 1, $row->attempt_count );
		$this->assertSame( '2026-05-13 10:00:00', $row->received_at );
		$this->assertSame( '2026-05-13 10:00:01', $row->applied_at );
		$this->assertNull( $row->error_message );
	}

	public function test_allows_null_wc_order_id_for_unresolvable_events(): void {
		$row = new DeliveryRow(
			id: 1,
			delivery_id: 'd-1',
			event_type: 'webhook.test',
			wc_order_id: null,
			state: 'applied',
			attempt_count: 1,
			received_at: '2026-05-13 10:00:00',
			applied_at: '2026-05-13 10:00:00',
			error_message: null,
		);

		$this->assertNull( $row->wc_order_id );
	}

	public function test_carries_error_message_in_errored_state(): void {
		$row = new DeliveryRow(
			id: 7,
			delivery_id: 'd-7',
			event_type: 'order.completed',
			wc_order_id: 100,
			state: 'errored',
			attempt_count: 2,
			received_at: '2026-05-13 09:00:00',
			applied_at: null,
			error_message: 'WC_Order::payment_complete failed',
		);

		$this->assertSame( 'errored', $row->state );
		$this->assertNull( $row->applied_at );
		$this->assertSame( 'WC_Order::payment_complete failed', $row->error_message );
		$this->assertSame( 2, $row->attempt_count );
	}

	public function test_received_state_has_null_applied_at(): void {
		$row = new DeliveryRow(
			id: 3,
			delivery_id: 'd-3',
			event_type: 'order.canceled',
			wc_order_id: 50,
			state: 'received',
			attempt_count: 1,
			received_at: '2026-05-13 10:00:00',
			applied_at: null,
			error_message: null,
		);

		$this->assertSame( 'received', $row->state );
		$this->assertNull( $row->applied_at );
	}
}
