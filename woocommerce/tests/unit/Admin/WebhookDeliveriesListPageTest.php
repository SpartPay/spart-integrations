<?php

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\WebhookDeliveriesListPage;
use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Webhooks\DeliveryRow;

final class WebhookDeliveriesListPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias( fn( string $p ): string => 'https://shop.test/wp-admin/' . $p );
		Functions\when( 'current_user_can' )->justReturn( true );
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $f and $ts are wp_date()'s required positional params; this stub ignores them and returns a fixed timestamp.
		Functions\when( 'wp_date' )->alias( fn( string $f, $ts ): string => '2026-05-27 09:00:00' );
		Functions\when( 'wc_get_order' )->justReturn( null );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( fn( string $s ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\\-]/i', '', $s ) ) );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b = true, bool $display = true ): string {
				$out = (string) $a === (string) $b ? " selected='selected'" : '';
				if ( $display ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub, static literal.
					echo $out;
				}
				return $out;
			}
		);
		// __() is defined in tests/unit/bootstrap.php before Patchwork activates,
		// so it cannot be redefined via Functions\when(); the bootstrap stub already
		// returns the text as-is, which is exactly what these tests need.
		// Mirror WordPress's submit_button() signature: text, type, name, wrap, other_attributes.
		Functions\when( 'submit_button' )->alias(
			static function ( string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true, $other_attributes = null ): void {
				unset( $type, $wrap, $other_attributes );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub with hard-coded callers in render_filters().
				echo '<input type="submit" name="' . $name . '" value="' . $text . '" />';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_register_adds_admin_menu_action(): void {
		$page = new WebhookDeliveriesListPage( Mockery::mock( DeliveryRepository::class ) );
		Actions\expectAdded( 'admin_menu' )->once();
		$page->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_register_menu_calls_add_submenu_page(): void {
		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'woocommerce',
				Mockery::any(),
				'Spart Webhooks',
				'manage_woocommerce',
				'spart-webhook-deliveries',
				Mockery::type( 'array' )
			)
			->andReturn( 'woocommerce_page_spart-webhook-deliveries' );

		$page = new WebhookDeliveriesListPage( Mockery::mock( DeliveryRepository::class ) );
		$page->register_menu();
		$this->addToAssertionCount( 1 );
	}

	public function test_render_denies_users_without_manage_woocommerce(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'wp_die' ) );

		$page = new WebhookDeliveriesListPage( Mockery::mock( DeliveryRepository::class ) );
		ob_start();
		try {
			$page->render();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		} finally {
			ob_end_clean();
		}
	}

	public function test_render_detail_for_known_delivery(): void {
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )
			->once()
			->with( 'evt_abc' )
			->andReturn(
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
				)
			);

		$_GET['view'] = 'evt_abc';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		$this->assertStringContainsString( 'evt_abc', $html );
		$this->assertStringContainsString( 'intent.created', $html );
	}

	public function test_render_detail_renders_order_as_link(): void {
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )
			->once()
			->with( 'evt_link' )
			->andReturn(
				new DeliveryRow(
					id: 2,
					delivery_id: 'evt_link',
					event_type: 'intent.created',
					wc_order_id: 42,
					state: 'applied',
					attempt_count: 1,
					received_at: '2026-05-27 09:00:00',
					applied_at: '2026-05-27 09:00:01',
					error_message: null,
				)
			);

		$_GET['view'] = 'evt_link';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		// Order field must be an anchor tag pointing at the order edit screen.
		// HPOS-aware: either OrderUtil::get_order_admin_edit_url or post.php fallback,
		// both surface order id 42 (post=42 in fallback, or OrderUtil-supplied URL).
		$this->assertMatchesRegularExpression(
			'/<th[^>]*>\s*Order\s*<\/th>\s*<td[^>]*>\s*<a [^>]*href="[^"]*(post=42|action=edit[^"]*42)[^"]*"[^>]*>\s*#42\s*<\/a>\s*<\/td>/',
			$html
		);
	}

	public function test_render_detail_for_unknown_delivery_shows_not_found_notice(): void {
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )->once()->with( 'nope' )->andReturn( null );

		$_GET['view'] = 'nope';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		$this->assertStringContainsString( 'Delivery not found', $html );
	}

	public function test_render_detail_renders_dash_when_error_message_is_empty_string(): void {
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )
			->once()
			->with( 'evt_empty_error' )
			->andReturn(
				new DeliveryRow(
					id: 11,
					delivery_id: 'evt_empty_error',
					event_type: 'intent.created',
					wc_order_id: 42,
					state: 'applied',
					attempt_count: 1,
					received_at: '2026-05-27 09:00:00',
					applied_at: '2026-05-27 09:00:01',
					error_message: '',
				)
			);

		$_GET['view'] = 'evt_empty_error';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		// Error row must render as `—`, NOT an empty cell. Mirrors WebhookDeliveriesTable::column_error_message().
		$this->assertMatchesRegularExpression(
			'/<th[^>]*>\s*Error\s*<\/th>\s*<td[^>]*>\s*—\s*<\/td>/',
			$html
		);
	}

	public function test_render_detail_renders_dash_when_wc_order_id_is_null(): void {
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )
			->once()
			->with( 'evt_unmatched' )
			->andReturn(
				new DeliveryRow(
					id: 9,
					delivery_id: 'evt_unmatched',
					event_type: 'webhook.test',
					wc_order_id: null,
					state: 'received',
					attempt_count: 1,
					received_at: '2026-05-27 09:00:00',
					applied_at: null,
					error_message: null,
				)
			);

		$_GET['view'] = 'evt_unmatched';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		// Order row must render as `—`, NOT a bare `#`.
		$this->assertMatchesRegularExpression(
			'/<th[^>]*>\s*Order\s*<\/th>\s*<td[^>]*>\s*—\s*<\/td>/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<th[^>]*>\s*Order\s*<\/th>\s*<td[^>]*>\s*#\s*<\/td>/',
			$html
		);
	}

	public function test_render_detail_renders_back_to_list_link(): void {
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )->once()->with( 'nope' )->andReturn( null );

		$_GET['view'] = 'nope';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		$this->assertStringContainsString( 'admin.php?page=spart-webhook-deliveries', $html );
		$this->assertStringContainsString( 'Back to deliveries', $html );
	}

	public function test_render_detail_surfaces_intent_short_id_when_meta_present(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( '_spart_intent_short_id', true )
			->andReturn( 'SPART-ABC123' );
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $id === 42 ? $order : null );

		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )
			->once()
			->with( 'evt_abc' )
			->andReturn(
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
				)
			);

		$_GET['view'] = 'evt_abc';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		$this->assertStringContainsString( 'Intent short ID', $html );
		$this->assertStringContainsString( 'SPART-ABC123', $html );
	}

	public function test_render_detail_omits_intent_short_id_when_meta_empty(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( '_spart_intent_short_id', true )
			->andReturn( '' );
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $id === 42 ? $order : null );

		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )
			->once()
			->with( 'evt_abc' )
			->andReturn(
				new DeliveryRow(
					id: 1,
					delivery_id: 'evt_abc',
					event_type: 'intent.created',
					wc_order_id: 42,
					state: 'applied',
					attempt_count: 1,
					received_at: '2026-05-27 09:00:00',
					applied_at: null,
					error_message: null,
				)
			);

		$_GET['view'] = 'evt_abc';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		$this->assertStringNotContainsString( 'Intent short ID', $html );
	}

	public function test_render_detail_omits_intent_short_id_when_wc_order_id_null(): void {
		// Guard: must not call wc_get_order(null) → would throw or warn.
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'find' )
			->once()
			->with( 'evt_x' )
			->andReturn(
				new DeliveryRow(
					id: 1,
					delivery_id: 'evt_x',
					event_type: 'webhook.test',
					wc_order_id: null,
					state: 'received',
					attempt_count: 1,
					received_at: '2026-05-27 09:00:00',
					applied_at: null,
					error_message: null,
				)
			);

		$_GET['view'] = 'evt_x';
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		$this->assertStringNotContainsString( 'Intent short ID', $html );
	}

	public function test_render_falls_back_to_list_when_view_param_exceeds_max_length(): void {
		$repo = Mockery::mock( DeliveryRepository::class );
		// Detail view must NOT be invoked for an overlong view param.
		$repo->shouldNotReceive( 'find' );
		// Falls through to render_list() → prepare_items().
		$repo->shouldReceive( 'list_for_admin' )->andReturn( array() );
		$repo->shouldReceive( 'count_for_admin' )->andReturn( 0 );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( fn( string $s ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\\-]/i', '', $s ) ) );

		$_GET['view'] = str_repeat( 'z', 200 );
		$page         = new WebhookDeliveriesListPage( $repo );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['view'] );

		$this->assertStringContainsString( 'Spart Webhook Deliveries', $html );
	}

	public function test_render_returns_via_wp_die_when_user_lacks_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$died = false;
		Functions\when( 'wp_die' )->alias(
			function () use ( &$died ): void {
				$died = true;
				throw new \RuntimeException( 'wp_die invoked' );
			}
		);

		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldNotReceive( 'list_for_admin' );
		$repo->shouldNotReceive( 'find' );

		try {
			( new WebhookDeliveriesListPage( $repo ) )->render();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentionally swallow the wp_die() spy exception; the assertion below verifies wp_die() was hit.
			unset( $e );
		}

		$this->assertTrue( $died );
	}
}
