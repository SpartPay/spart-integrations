<?php

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\OrderPayeesMetaBox;
use Spart\WooCommerce\Webhooks\OrderSync;

final class OrderPayeesMetaBoxTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'woocommerce_page_wc-orders' );
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, $args = array() ): string {
				$currency = is_array( $args ) && isset( $args['currency'] ) ? (string) $args['currency'] : 'USD';
				return $currency . ' ' . number_format( (float) $amount, 2 );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $parts
	 */
	private function order_with_parts( array $parts ): \WC_Order {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( OrderSync::META_PAYMENT_PARTS, true )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test helper; wp_json_encode() unavailable in unit tests.
			->andReturn( (string) json_encode( $parts ) );
		return $order;
	}

	private function order_with_versioned_parts( array $parts ): \WC_Order {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( OrderSync::META_PAYMENT_PARTS, true )
			->andReturn(
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test helper; wp_json_encode() unavailable in unit tests.
				(string) json_encode(
					array(
						'v'     => 1,
						'parts' => $parts,
					)
				)
			);
		return $order;
	}

	private function sample_part( string $status = 'captured', bool $is_sparter = true ): array {
		return array(
			'id'         => 'pp-1',
			'amount'     => 50.0,
			'amountType' => 'Percent',
			'status'     => $status,
			'isSparter'  => $is_sparter,
			'payeeName'  => '•••',
			'net'        => array(
				'amount'   => 195.0,
				'currency' => 'EUR',
			),
			'total'      => array(
				'amount'   => 200.0,
				'currency' => 'EUR',
			),
			'fees'       => array( 'platform' => 5.0 ),
		);
	}

	public function test_register_hooks_into_add_meta_boxes(): void {
		Actions\expectAdded( 'add_meta_boxes' )->once();
		( new OrderPayeesMetaBox() )->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_does_nothing_for_non_order_screen(): void {
		Functions\expect( 'add_meta_box' )->never();
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->never();
		( new OrderPayeesMetaBox() )->maybe_add( 'some_other_screen', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_does_nothing_for_non_spart_order(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		Functions\expect( 'add_meta_box' )->never();
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'cheque' );
		( new OrderPayeesMetaBox() )->maybe_add( 'shop_order', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_registers_meta_box_for_spart_order(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		Functions\expect( 'add_meta_box' )
			->once()
			->with(
				'spart_payees',
				Mockery::any(),
				Mockery::type( 'array' ),
				'shop_order',
				'normal',
				'default'
			);
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'spart' );
		( new OrderPayeesMetaBox() )->maybe_add( 'shop_order', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_render_lists_payees_from_meta(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		$order = $this->order_with_parts( array( $this->sample_part() ) );

		ob_start();
		( new OrderPayeesMetaBox() )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '•••', $html );
		$this->assertStringContainsString( 'captured', $html );
		$this->assertStringContainsString( 'EUR 200.00', $html );
		$this->assertStringContainsString( 'EUR 195.00', $html );
		$this->assertStringContainsString( 'platform', $html );
		$this->assertStringContainsString( 'EUR 5.00', $html );
	}

	public function test_render_lists_payees_from_versioned_snapshot(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		$order = $this->order_with_versioned_parts( array( $this->sample_part() ) );

		ob_start();
		( new OrderPayeesMetaBox() )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '•••', $html );
		$this->assertStringContainsString( 'captured', $html );
		$this->assertStringContainsString( 'EUR 200.00', $html );
	}

	public function test_render_empty_state_when_no_meta(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( '' );

		ob_start();
		( new OrderPayeesMetaBox() )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No payees', $html );
	}

	public function test_render_survives_corrupt_json_without_fatal(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( OrderSync::META_PAYMENT_PARTS, true )->andReturn( '{not-valid-json' );

		ob_start();
		( new OrderPayeesMetaBox() )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No payees', $html );
	}

	public function test_render_skips_malformed_part_entries(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		// A list whose single entry is a scalar, not an object.
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( OrderSync::META_PAYMENT_PARTS, true )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test helper; wp_json_encode() unavailable in unit tests.
			->andReturn( (string) json_encode( array( 'oops' ) ) );

		ob_start();
		( new OrderPayeesMetaBox() )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No payees', $html );
	}

	public function test_render_returns_silently_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )->never();

		ob_start();
		( new OrderPayeesMetaBox() )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}
}
