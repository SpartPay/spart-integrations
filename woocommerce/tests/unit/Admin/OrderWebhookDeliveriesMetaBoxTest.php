<?php

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\OrderWebhookDeliveriesMetaBox;
use Spart\WooCommerce\Admin\StateBadge;
use Spart\WooCommerce\Checkout\CheckoutSession;
use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Webhooks\DeliveryRow;
use Spart\WooCommerce\Webhooks\WebhookReceiver;

final class OrderWebhookDeliveriesMetaBoxTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias( fn( string $p ): string => 'https://shop.test/wp-admin/' . $p );
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'woocommerce_page_wc-orders' );
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $f and $ts are wp_date()'s required positional params; this stub ignores them and returns a fixed timestamp.
		Functions\when( 'wp_date' )->alias( fn( string $f, $ts ): string => '2026-05-27 09:00:00' );
		// wc_get_order is intentionally NOT stubbed here: tests that pass an
		// already-resolved WC_Order mock add Functions\when('wc_get_order')->returnArg(1)
		// individually; the WP_Post coercion test uses Functions\expect() and would
		// otherwise be shadowed by a setUp-time when() registration.
		Functions\when( 'current_user_can' )->justReturn( true );
		StateBadge::reset_style_block_for_tests();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_register_hooks_into_add_meta_boxes(): void {
		Actions\expectAdded( 'add_meta_boxes' )->once();
		( new OrderWebhookDeliveriesMetaBox( Mockery::mock( DeliveryRepository::class ) ) )->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_does_nothing_for_non_order_screen(): void {
		Functions\expect( 'add_meta_box' )->never();
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->never();
		( new OrderWebhookDeliveriesMetaBox( Mockery::mock( DeliveryRepository::class ) ) )
			->maybe_add( 'some_other_screen', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_does_nothing_for_non_spart_order(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		Functions\expect( 'add_meta_box' )->never();
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'cheque' );
		( new OrderWebhookDeliveriesMetaBox( Mockery::mock( DeliveryRepository::class ) ) )
			->maybe_add( 'shop_order', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_registers_meta_box_for_spart_order_on_post_screen(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		Functions\expect( 'add_meta_box' )
			->once()
			->with(
				'spart_webhook_deliveries',
				Mockery::any(),
				Mockery::type( 'array' ),
				'shop_order',
				'normal',
				'low'
			);
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'spart' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );

		( new OrderWebhookDeliveriesMetaBox( Mockery::mock( DeliveryRepository::class ) ) )
			->maybe_add( 'shop_order', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_registers_meta_box_for_spart_order_on_hpos_screen(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		Functions\expect( 'add_meta_box' )
			->once()
			->with(
				'spart_webhook_deliveries',
				Mockery::any(),
				Mockery::type( 'array' ),
				'woocommerce_page_wc-orders',
				'normal',
				'low'
			);
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'spart' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );

		( new OrderWebhookDeliveriesMetaBox( Mockery::mock( DeliveryRepository::class ) ) )
			->maybe_add( 'woocommerce_page_wc-orders', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_coerces_wp_post_to_wc_order_on_legacy_cpt_screen(): void {
		$wc_order = Mockery::mock( \WC_Order::class );
		$wc_order->shouldReceive( 'get_payment_method' )->andReturn( 'spart' );
		$wc_order->shouldReceive( 'get_id' )->andReturn( 42 );

		$post = Mockery::mock( 'WP_Post' );

		Functions\expect( 'wc_get_order' )->once()->with( $post )->andReturn( $wc_order );

		Functions\expect( 'add_meta_box' )
			->once()
			->with(
				'spart_webhook_deliveries',
				Mockery::any(),
				Mockery::type( 'array' ),
				'shop_order',
				'normal',
				'low'
			);

		( new OrderWebhookDeliveriesMetaBox( Mockery::mock( DeliveryRepository::class ) ) )
			->maybe_add( 'shop_order', $post );
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_falls_back_to_legacy_screen_when_hpos_screen_id_is_empty(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( '' );
		Functions\when( 'wc_get_order' )->returnArg( 1 );

		Functions\expect( 'add_meta_box' )
			->once()
			->with(
				'spart_webhook_deliveries',
				Mockery::any(),
				Mockery::type( 'array' ),
				'shop_order',
				'normal',
				'low'
			);
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'spart' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );

		( new OrderWebhookDeliveriesMetaBox( Mockery::mock( DeliveryRepository::class ) ) )
			->maybe_add( 'shop_order', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_maybe_add_rejects_unknown_screen_when_hpos_screen_id_is_empty(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( '' );

		Functions\expect( 'add_meta_box' )->never();
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->never();

		( new OrderWebhookDeliveriesMetaBox( Mockery::mock( DeliveryRepository::class ) ) )
			->maybe_add( 'woocommerce_page_wc-orders', $order );
		$this->addToAssertionCount( 1 );
	}

	public function test_render_lists_deliveries_for_the_order(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'list_for_order' )
			->once()
			->with( 42, 50 )
			->andReturn(
				array(
					new DeliveryRow(
						id: 1,
						delivery_id: 'evt_abc',
						event_type: 'intent.created',
						wc_order_id: 42,
						state: 'applied',
						attempt_count: 1,
						received_at: '2026-05-27 09:00:00',
						applied_at: '2026-05-27 09:00:01',
						error_message: null,
					),
				)
			);

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_meta' )
			->with( CheckoutSession::META_CORRELATION_ID, true )
			->andReturn( 'corr_abc' );
		$order->shouldReceive( 'get_meta' )
			->with( CheckoutSession::META_INTENT_SHORT_ID, true )
			->andReturn( 'pi_short_42' );
		$order->shouldReceive( 'get_meta' )
			->with( WebhookReceiver::ORDER_DEDUPE_META_KEY, true )
			->andReturn( 'evt_abc' );

		ob_start();
		( new OrderWebhookDeliveriesMetaBox( $repo ) )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'evt_abc', $html );
		$this->assertStringContainsString( 'intent.created', $html );
		$this->assertStringContainsString( 'spart-state-applied', $html );
		// Top-section identifiers.
		$this->assertStringContainsString( 'corr_abc', $html );
		$this->assertStringContainsString( 'pi_short_42', $html );
		// Attempts column header + cell.
		$this->assertStringContainsString( 'Attempts', $html );
		$this->assertMatchesRegularExpression( '/<td>1<\/td>/', $html );
		// Identifiers table must use a CSS class hook, not inline `style=""` attributes (CSP-safe).
		$this->assertStringContainsString( 'spart-webhook-deliveries__identifiers', $html );
		$this->assertDoesNotMatchRegularExpression( '/<(?:table|tr|th|td)\b[^>]*\bstyle=/', $html );
	}

	public function test_render_empty_state_when_no_deliveries(): void {
		Functions\when( 'wc_get_order' )->returnArg( 1 );
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'list_for_order' )->once()->with( 42, 50 )->andReturn( array() );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );

		ob_start();
		( new OrderWebhookDeliveriesMetaBox( $repo ) )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No webhook deliveries', $html );
	}

	public function test_render_returns_silently_when_user_lacks_edit_shop_orders_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldNotReceive( 'list_for_order' );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_id' )->never();

		ob_start();
		( new OrderWebhookDeliveriesMetaBox( $repo ) )->render( $order );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}
}
