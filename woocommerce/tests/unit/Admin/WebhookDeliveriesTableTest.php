<?php

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\WebhookDeliveriesTable;
use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Webhooks\DeliveryRow;

final class WebhookDeliveriesTableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		// esc_attr() and sanitize_html_class() are defined as faithful pass-throughs
		// in tests/unit/stubs.php (loaded before Patchwork), so they cannot be redefined
		// here via Functions\when(); the test inputs contain no HTML-special chars, so
		// the stub output equals returnArg( 1 ).
		Functions\when( 'admin_url' )->alias( fn( string $p ): string => 'https://shop.test/wp-admin/' . $p );
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $f and $ts are wp_date()'s required positional params; this stub ignores them and returns a fixed timestamp.
		Functions\when( 'wp_date' )->alias( fn( string $f, $ts ): string => '2026-05-27 09:00:00' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( fn( string $s ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\\-]/i', '', $s ) ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		$_GET = array();
		parent::tearDown();
	}

	private function row( array $overrides = array() ): DeliveryRow {
		$wc_order_id = array_key_exists( 'wc_order_id', $overrides )
			? $overrides['wc_order_id']
			: 42;
		return new DeliveryRow(
			id:           (int) ( $overrides['id'] ?? 1 ),
			delivery_id:  (string) ( $overrides['delivery_id'] ?? 'evt_abc' ),
			event_type:   (string) ( $overrides['event_type'] ?? 'intent.created' ),
			wc_order_id:  $wc_order_id === null ? null : (int) $wc_order_id,
			state:        (string) ( $overrides['state'] ?? 'applied' ),
			attempt_count: (int) ( $overrides['attempt_count'] ?? 1 ),
			received_at:  (string) ( $overrides['received_at'] ?? '2026-05-27 09:00:00' ),
			applied_at:   $overrides['applied_at'] ?? null,
			error_message:$overrides['error_message'] ?? null,
		);
	}

	public function test_column_delivery_id_links_to_detail_view(): void {
		$table = new WebhookDeliveriesTable();
		$html  = $table->column_delivery_id( $this->row( array( 'delivery_id' => 'evt_abc' ) ) );
		$this->assertStringContainsString( 'admin.php?page=spart-webhook-deliveries&view=evt_abc', $html );
		$this->assertStringContainsString( 'evt_abc', $html );
	}

	public function test_column_state_renders_badge(): void {
		$table = new WebhookDeliveriesTable();
		$html  = $table->column_state( $this->row( array( 'state' => 'errored' ) ) );
		$this->assertStringContainsString( 'spart-state-errored', $html );
	}

	public function test_column_wc_order_id_links_via_order_util(): void {
		// Patchwork cannot redefine the internal class_exists() by default, so we
		// eval the OrderUtil class once and let production code's real class_exists()
		// see it directly. The class is namespaced under Automattic\WooCommerce so
		// there's no clash with the WC stubs in tests/unit/bootstrap.php.
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', false ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only: synthesize the WC OrderUtil class on demand to avoid pulling the WC plugin into a pure unit-test bootstrap.
			eval(
				'namespace Automattic\\WooCommerce\\Utilities;
                  class OrderUtil { public static function get_order_admin_edit_url(int $id): string { return "https://shop.test/wp-admin/post.php?action=edit&post=" . $id; } }'
			);
		}

		$table = new WebhookDeliveriesTable();
		$html  = $table->column_wc_order_id( $this->row( array( 'wc_order_id' => 42 ) ) );
		$this->assertStringContainsString( 'post=42', $html );
		$this->assertStringContainsString( '42', $html );
	}

	public function test_column_wc_order_id_renders_dash_when_null(): void {
		$table = new WebhookDeliveriesTable();
		$this->assertSame(
			'—',
			$table->column_wc_order_id( $this->row( array( 'wc_order_id' => null ) ) )
		);
	}

	public function test_column_received_at_passes_through_wp_date(): void {
		$table = new WebhookDeliveriesTable();
		$html  = $table->column_received_at( $this->row( array( 'received_at' => '2026-05-27 09:00:00' ) ) );
		$this->assertStringContainsString( '2026-05-27 09:00:00', $html );
	}

	public function test_column_error_message_truncates_long_text(): void {
		$long  = str_repeat( 'A', 200 );
		$table = new WebhookDeliveriesTable();
		$html  = $table->column_error_message( $this->row( array( 'error_message' => $long ) ) );
		$this->assertStringContainsString( '…', $html );
		$this->assertLessThanOrEqual( 120, strlen( $html ) );
	}

	public function test_column_error_message_truncates_multibyte_safely(): void {
		$emoji = str_repeat( '🔥', 100 );
		$table = new WebhookDeliveriesTable();
		$html  = $table->column_error_message( $this->row( array( 'error_message' => $emoji ) ) );
		$this->assertStringNotContainsString( "\xEF\xBF\xBD", $html );
		$this->assertStringContainsString( '…', $html );
		$this->assertStringContainsString( str_repeat( '🔥', 80 ), $html );
	}

	public function test_column_error_message_renders_empty_when_null(): void {
		$table = new WebhookDeliveriesTable();
		$this->assertSame( '—', $table->column_error_message( $this->row( array( 'error_message' => null ) ) ) );
	}

	public function test_prepare_items_returns_empty_when_user_lacks_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldNotReceive( 'list_for_admin' );
		$repo->shouldNotReceive( 'count_for_admin' );

		$table = new WebhookDeliveriesTable( $repo );
		$table->prepare_items();

		$this->assertSame( array(), $table->items );
	}

	public function test_prepare_items_passes_sanitized_filters_to_repository(): void {
		$_GET = array(
			'paged'      => '3',
			'orderby'    => 'received_at',
			'order'      => 'asc',
			'state'      => 'failed',
			'event_type' => 'intent.created',
			's'          => 'abc',
		);

		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'list_for_admin' )
			->once()
			->with(
				3,
				20,
				array(
					'state'      => 'failed',
					'event_type' => 'intent.created',
					'search'     => 'abc',
				),
				'received_at',
				'ASC'
			)
			->andReturn( array() );
		$repo->shouldReceive( 'count_for_admin' )
			->once()
			->andReturn( 0 );

		( new WebhookDeliveriesTable( $repo ) )->prepare_items();
		$this->addToAssertionCount( 1 );
	}

	public function test_prepare_items_drops_event_type_when_over_64_chars(): void {
		$long = str_repeat( 'a', 65 );
		$_GET = array( 'event_type' => $long );

		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'list_for_admin' )
			->once()
			->with(
				1,
				20,
				Mockery::on( fn( array $f ) => $f['event_type'] === '' ),
				'received_at',
				'DESC'
			)
			->andReturn( array() );
		$repo->shouldReceive( 'count_for_admin' )->once()->andReturn( 0 );

		( new WebhookDeliveriesTable( $repo ) )->prepare_items();
		$this->addToAssertionCount( 1 );
	}

	public function test_prepare_items_truncates_search_at_128_chars(): void {
		$long = str_repeat( 'x', 200 );
		$_GET = array( 's' => $long );

		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'list_for_admin' )
			->once()
			->with(
				1,
				20,
				Mockery::on( fn( array $f ) => strlen( $f['search'] ) === 128 ),
				'received_at',
				'DESC'
			)
			->andReturn( array() );
		$repo->shouldReceive( 'count_for_admin' )->once()->andReturn( 0 );

		( new WebhookDeliveriesTable( $repo ) )->prepare_items();
		$this->addToAssertionCount( 1 );
	}

	public function test_prepare_items_truncates_multibyte_search_without_splitting_codepoints(): void {
		// Each '€' is 3 bytes in UTF-8. 200 of them = 600 bytes, 200 codepoints.
		// `substr( $s, 0, 128 )` would slice mid-codepoint (128 bytes = 42 full + 2 trailing bytes of the 43rd)
		// and produce invalid UTF-8. `mb_substr(..., 128, 'UTF-8')` slices at the 128th codepoint cleanly.
		$long = str_repeat( '€', 200 );
		$_GET = array( 's' => $long );

		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'list_for_admin' )
			->once()
			->with(
				1,
				20,
				Mockery::on(
					fn( array $f ) =>
						mb_check_encoding( $f['search'], 'UTF-8' )
						&& mb_strlen( $f['search'], 'UTF-8' ) === 128
				),
				'received_at',
				'DESC'
			)
			->andReturn( array() );
		$repo->shouldReceive( 'count_for_admin' )->once()->andReturn( 0 );

		( new WebhookDeliveriesTable( $repo ) )->prepare_items();
		$this->addToAssertionCount( 1 );
	}

	public function test_prepare_items_normalizes_invalid_order_to_desc(): void {
		$_GET = array( 'order' => 'sideways' );

		$repo = Mockery::mock( DeliveryRepository::class );
		$repo->shouldReceive( 'list_for_admin' )
			->once()
			->with( 1, 20, Mockery::any(), 'received_at', 'DESC' )
			->andReturn( array() );
		$repo->shouldReceive( 'count_for_admin' )->once()->andReturn( 0 );

		( new WebhookDeliveriesTable( $repo ) )->prepare_items();
		$this->addToAssertionCount( 1 );
	}
}
