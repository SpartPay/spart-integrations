<?php
/**
 * Integration test for the live per-payee payment-status sync.
 *
 * Exercises the full webhook lifecycle for a single payee across three
 * deliveries — order.created -> payment.authorized -> order.payment_part_released
 * — and asserts that:
 *  - the derived status in the stored snapshot advances none -> authorized
 *    -> released (a previously-authorized hold voided, never captured);
 *  - the admin meta box renders the merchant-friendly collapsed labels
 *    Pending -> Paid -> Canceled accordingly;
 *  - the payee name and email seeded by order.created persist in the stored
 *    snapshot and render in the meta box at every step (the patch-only
 *    authorized/released deliveries never clear them).
 *
 * @package Spart\WooCommerce\Tests\Integration\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Webhooks;

use Spart\WooCommerce\Admin\OrderPayeesMetaBox;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;
use Spart\WooCommerce\Webhooks\OrderSync;

final class WebhookPayeeLiveStatusTest extends WC_Spart_IntegrationTestCase {

	private const PART_ID = 'pp-live-1';

	public function test_payee_status_advances_pending_paid_canceled_and_shows_payee(): void {
		$this->set_signing_secret( 'whsec_test' );
		$order = $this->make_order( '361.00' );

		// 1) order.created — seeds the snapshot with a never-authorized part.
		$created                          = $this->order_envelope_payload( $order, 'placed' );
		$created['order']['paymentParts'] = array( $this->created_part( 'none' ) );
		$this->deliver_and_assert_ok( 'order.created', $order, $created );

		$snapshot = $this->snapshot( $order );
		$this->assertSame( 'none', $this->part_status( $snapshot ) );
		$this->assert_payee_shown( $snapshot );
		$this->assert_meta_box_shows( $order, 'Pending' );

		// 2) payment.authorized — patches authorizedAt; status derives to authorized (Paid).
		$this->deliver_and_assert_ok(
			'payment.authorized',
			$order,
			$this->payment_envelope_payload( $order, self::PART_ID, 200.0 )
		);

		$snapshot = $this->snapshot( $order );
		$this->assertSame( 'authorized', $this->part_status( $snapshot ) );
		$this->assert_payee_shown( $snapshot );
		$this->assert_meta_box_shows( $order, 'Paid' );

		// 3) order.payment_part_released — patches releasedAt; the authorized hold
		//    is voided without capture, so status derives to released (Canceled).
		$this->deliver_and_assert_ok(
			'order.payment_part_released',
			$order,
			$this->released_envelope_payload( $order, self::PART_ID, 200.0 )
		);

		$snapshot = $this->snapshot( $order );
		$this->assertSame( 'released', $this->part_status( $snapshot ) );
		$this->assert_payee_shown( $snapshot );
		$this->assert_meta_box_shows( $order, 'Canceled' );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function deliver_and_assert_ok( string $event, \WC_Order $order, array $payload ): void {
		$response = $this->deliver_webhook(
			$event,
			$this->compose_session_id( $order->get_id() ),
			$payload
		);
		$this->assertSame( 204, $response['status'], "[$event] body was: " . $response['body'] );
		$this->assert_dedupe_state( $response['delivery_id'], 'applied' );
	}

	private function snapshot( \WC_Order $order ): string {
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		return (string) $reloaded->get_meta( OrderSync::META_PAYMENT_PARTS );
	}

	private function part_status( string $snapshot ): string {
		$decoded = json_decode( $snapshot, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'parts', $decoded );
		$this->assertCount( 1, $decoded['parts'] );
		$this->assertSame( self::PART_ID, $decoded['parts'][0]['id'] );
		return (string) $decoded['parts'][0]['status'];
	}

	private function assert_payee_shown( string $snapshot ): void {
		$this->assertNotSame( '', $snapshot );
		$this->assertStringContainsString( 'Beppe Brescia', $snapshot );
		$this->assertStringContainsString( 'obiuan+spartwp@gmail.com', $snapshot );
	}

	private function assert_meta_box_shows( \WC_Order $order, string $expected_label ): void {
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );

		$html = $this->render_meta_box( $reloaded );

		$this->assertStringContainsString( $expected_label, $html );
		$this->assertStringContainsString( 'Beppe Brescia', $html );
		$this->assertStringContainsString( 'obiuan+spartwp@gmail.com', $html );
	}

	private function render_meta_box( \WC_Order $order ): string {
		$admin    = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$admin_id = $admin === array() ? wp_insert_user(
			array(
				'user_login' => 'spart_admin_' . $order->get_id(),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'spart_admin_' . $order->get_id() . '@example.test',
				'role'       => 'administrator',
			)
		) : (int) $admin[0];
		wp_set_current_user( (int) $admin_id );

		ob_start();
		( new OrderPayeesMetaBox() )->render( $order );
		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function created_part( string $status ): array {
		return array(
			'id'          => self::PART_ID,
			'amount'      => 100.0,
			'amountType'  => 'Percent',
			'status'      => $status,
			'isSparter'   => true,
			'payee'       => array(
				'fullName' => 'Beppe Brescia',
				'email'    => 'obiuan+spartwp@gmail.com',
			),
			'payeeCharge' => array(
				'net'   => array(
					'amount'   => 195.0,
					'currency' => 'EUR',
				),
				'total' => array(
					'amount'   => 200.0,
					'currency' => 'EUR',
				),
				'fees'  => array( 'platform' => 5.0 ),
			),
		);
	}

	/**
	 * Build the `data.payment` envelope for order.payment_part_released.
	 *
	 * @return array<string, mixed>
	 */
	private function released_envelope_payload( \WC_Order $order, string $part_id, float $amount ): array {
		return array(
			'payment' => array(
				'orderShortId'   => 'spart_short_' . $order->get_id(),
				'sessionId'      => $this->compose_session_id( $order->get_id() ),
				'paymentPartId'  => $part_id,
				'amountReleased' => array(
					'currency' => 'EUR',
					'amount'   => $amount,
				),
				'payee'          => array(
					'fullName' => 'Beppe Brescia',
					'email'    => 'obiuan+spartwp@gmail.com',
				),
				'releasedAt'     => gmdate( 'c' ),
			),
		);
	}
}
