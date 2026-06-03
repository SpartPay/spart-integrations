<?php
/**
 * Unit tests for Webhooks\DeliveryRepository.
 *
 * @package Spart\WooCommerce\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Webhooks;

use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Persistence\WebhookDeliveriesSchema;
use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Webhooks\DeliveryRow;

/**
 * @covers \Spart\WooCommerce\Webhooks\DeliveryRepository
 */
final class DeliveryRepositoryTest extends TestCase {

	private const PREFIX = 'wp_';
	private const TABLE  = 'wp_spart_webhook_deliveries';

	/**
	 * Mockery mock of \wpdb. Re-typed as `\wpdb` so the repository's
	 * type hint is satisfied at runtime.
	 *
	 * @var \wpdb&\Mockery\MockInterface
	 */
	private $wpdb;

	private DeliveryRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb         = Mockery::mock( \wpdb::class );
		$this->wpdb->prefix = self::PREFIX;
		// last_error is set/read in the race-handling path; default to empty.
		$this->wpdb->last_error = '';
		$this->repo             = new DeliveryRepository( $this->wpdb );
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	public function test_table_name_uses_schema_helper_with_wpdb_prefix(): void {
		// Sanity check that the constant we test against matches what the schema computes.
		$this->assertSame(
			self::TABLE,
			WebhookDeliveriesSchema::table_name( self::PREFIX )
		);
	}

	public function test_find_returns_null_when_row_not_found(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					static fn ( $sql ) => is_string( $sql )
						&& str_contains( $sql, 'SELECT * FROM ' . self::TABLE )
						&& str_contains( $sql, 'WHERE delivery_id = %s' )
						&& str_contains( $sql, 'LIMIT 1' )
				),
				'd-missing'
			)
			->andReturn( 'PREPARED:d-missing' );

		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->with( 'PREPARED:d-missing', ARRAY_A )
			->andReturn( null );

		$this->assertNull( $this->repo->find( 'd-missing' ) );
	}

	public function test_find_hydrates_delivery_row_from_array_result(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->with( 'PREPARED', ARRAY_A )
			->andReturn(
				array(
					'id'            => '42',
					'delivery_id'   => 'd-1',
					'event_type'    => 'order.completed',
					'wc_order_id'   => '99',
					'state'         => 'applied',
					'attempt_count' => '2',
					'received_at'   => '2026-05-13 10:00:00',
					'applied_at'    => '2026-05-13 10:00:01',
					'error_message' => null,
				)
			);

		$row = $this->repo->find( 'd-1' );
		$this->assertInstanceOf( DeliveryRow::class, $row );
		$this->assertSame( 42, $row->id );
		$this->assertSame( 'd-1', $row->delivery_id );
		$this->assertSame( 'order.completed', $row->event_type );
		$this->assertSame( 99, $row->wc_order_id );
		$this->assertSame( 'applied', $row->state );
		$this->assertSame( 2, $row->attempt_count );
		$this->assertSame( '2026-05-13 10:00:00', $row->received_at );
		$this->assertSame( '2026-05-13 10:00:01', $row->applied_at );
		$this->assertNull( $row->error_message );
	}

	public function test_find_handles_null_optional_columns(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn(
				array(
					'id'            => '7',
					'delivery_id'   => 'd-7',
					'event_type'    => 'webhook.test',
					'wc_order_id'   => null,
					'state'         => 'received',
					'attempt_count' => '1',
					'received_at'   => '2026-05-13 10:00:00',
					'applied_at'    => null,
					'error_message' => null,
				)
			);

		$row = $this->repo->find( 'd-7' );
		$this->assertNotNull( $row );
		$this->assertNull( $row->wc_order_id );
		$this->assertNull( $row->applied_at );
		$this->assertNull( $row->error_message );
	}

	public function test_insert_received_writes_row_with_received_state_and_attempt_one_and_returns_true(): void {
		$captured_data    = null;
		$captured_formats = null;

		$this->wpdb->shouldReceive( 'suppress_errors' )->twice()->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				self::TABLE,
				Mockery::on(
					static function ( $data ) use ( &$captured_data ) {
						$captured_data = $data;
						return is_array( $data );
					}
				),
				Mockery::on(
					static function ( $formats ) use ( &$captured_formats ) {
						$captured_formats = $formats;
						return is_array( $formats );
					}
				)
			)
			->andReturn( 1 );

		$result = $this->repo->insert_received( 'd-new', 'order.completed', 99 );

		$this->assertTrue( $result );
		$this->assertIsArray( $captured_data );
		$this->assertSame( 'd-new', $captured_data['delivery_id'] );
		$this->assertSame( 'order.completed', $captured_data['event_type'] );
		$this->assertSame( 99, $captured_data['wc_order_id'] );
		$this->assertSame( 'received', $captured_data['state'] );
		$this->assertSame( 1, $captured_data['attempt_count'] );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$captured_data['received_at']
		);
		$this->assertSame( array( '%s', '%s', '%d', '%s', '%d', '%s' ), $captured_formats );
	}

	public function test_insert_received_allows_null_wc_order_id(): void {
		$captured = null;

		$this->wpdb->shouldReceive( 'suppress_errors' )->twice()->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				self::TABLE,
				Mockery::on(
					static function ( $data ) use ( &$captured ) {
						$captured = $data;
						return is_array( $data );
					}
				),
				Mockery::any()
			)
			->andReturn( 1 );

		$result = $this->repo->insert_received( 'd-x', 'webhook.test', null );

		$this->assertTrue( $result );
		$this->assertNull( $captured['wc_order_id'] );
	}

	public function test_insert_received_returns_false_on_unique_index_race(): void {
		$this->wpdb->last_error = "Duplicate entry 'd-race' for key 'uk_delivery_id'";

		$this->wpdb->shouldReceive( 'suppress_errors' )->twice()->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturn( false );

		// On the recovery re-read, find() is called and returns a row.
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn(
				array(
					'id'            => '1',
					'delivery_id'   => 'd-race',
					'event_type'    => 'order.completed',
					'wc_order_id'   => 99,
					'state'         => 'received',
					'attempt_count' => '1',
					'received_at'   => '2026-05-13 10:00:00',
					'applied_at'    => null,
					'error_message' => null,
				)
			);

		$result = $this->repo->insert_received( 'd-race', 'order.completed', 99 );

		$this->assertFalse( $result, 'Race-loss must return false so receiver can short-circuit.' );
	}

	public function test_insert_received_throws_when_insert_fails_and_row_still_missing(): void {
		$this->wpdb->last_error = 'Connection refused';

		$this->wpdb->shouldReceive( 'suppress_errors' )->twice()->andReturn( false );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Connection refused' );

		$this->repo->insert_received( 'd-broken', 'order.completed', 99 );
	}

	public function test_insert_received_restores_previous_suppress_errors_flag(): void {
		// Pin the contract: whatever the caller had configured for
		// $wpdb->suppress_errors before insert_received() runs MUST be
		// what it sees afterwards. The repository only silences errors
		// for its own insert; it does not leak that suppression to
		// other code paths sharing the same $wpdb handle.
		$call_args = array();
		$this->wpdb->shouldReceive( 'suppress_errors' )
			->twice()
			->andReturnUsing(
				static function ( $flag = null ) use ( &$call_args ) {
					$call_args[] = $flag;
					// Simulate: caller already had suppress_errors ON before
					// we entered insert_received. The restore call must pass
					// true back, not a hardcoded false.
					return true;
				}
			);

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );

		$this->repo->insert_received( 'd-ok', 'order.completed', 99 );

		$this->assertSame(
			array( true, true ),
			$call_args,
			'Second call must restore the previous value (true), not hardcode false.'
		);
	}

	public function test_increment_attempt_runs_arithmetic_update_via_query(): void {
		$captured_sql = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					static function ( $sql ) use ( &$captured_sql ) {
						$captured_sql = $sql;
						return is_string( $sql )
							&& str_contains( $sql, 'UPDATE ' . self::TABLE )
							&& str_contains( $sql, 'attempt_count = attempt_count + 1' )
							&& str_contains( $sql, 'WHERE delivery_id = %s' );
					}
				),
				'd-1'
			)
			->andReturn( 'PREPARED' );

		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED' );

		$this->repo->increment_attempt( 'd-1' );
		$this->assertNotNull( $captured_sql );
	}

	public function test_claim_for_retry_returns_true_when_exactly_one_row_updated(): void {
		// rows_affected returned via $wpdb->query() — 1 means the
		// atomic UPDATE matched a stale `received` row.
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED' );

		$this->wpdb->shouldReceive( 'query' )
			->once()
			->with( 'PREPARED' )
			->andReturn( 1 );

		$this->assertTrue( $this->repo->claim_for_retry( 'd-stale', 30 ) );
	}

	public function test_claim_for_retry_returns_false_when_no_rows_match(): void {
		// rows_affected = 0 means: row too fresh (received_at >= cutoff),
		// or missing, or already transitioned out of `received`. In all
		// cases the caller must short-circuit with 200 deduped — another
		// worker either is, or has been, applying.
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED' )->andReturn( 0 );

		$this->assertFalse( $this->repo->claim_for_retry( 'd-fresh', 30 ) );
	}

	public function test_claim_for_retry_returns_false_when_query_fails(): void {
		// wpdb::query() returns boolean false on SQL error. We must
		// NOT claim in that case — the caller short-circuits.
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED' )->andReturn( false );

		$this->assertFalse( $this->repo->claim_for_retry( 'd-err', 30 ) );
	}

	public function test_claim_for_retry_sql_filters_by_state_and_cutoff_and_bumps_counter(): void {
		// Inspect the actual SQL passed to prepare() — verifies that:
		//   1. State is filtered to 'received' (so terminal rows can't
		//      be re-claimed and apply path can't re-fire on applied).
		//   2. received_at < cutoff guards the idle-threshold check.
		//   3. attempt_count is incremented and received_at refreshed
		//      so a subsequent claim within the window also fails.
		$captured_sql    = null;
		$captured_args   = null;
		$expected_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					static function ( $sql ) use ( &$captured_sql ) {
						$captured_sql = $sql;
						return is_string( $sql );
					}
				),
				Mockery::on(
					static function ( $arg ) use ( &$captured_args ) {
						$captured_args = array( $arg );
						return is_string( $arg );
					}
				),
				'd-1',
				Mockery::on(
					static function ( $arg ) use ( &$captured_args ) {
						$captured_args[] = $arg;
						return is_string( $arg );
					}
				)
			)
			->andReturn( 'PREPARED' );

		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED' )->andReturn( 1 );

		$this->repo->claim_for_retry( 'd-1', 30 );

		$this->assertNotNull( $captured_sql );
		$this->assertStringContainsString( 'UPDATE ' . self::TABLE, $captured_sql );
		$this->assertStringContainsString( 'attempt_count = attempt_count + 1', $captured_sql );
		$this->assertStringContainsString( 'received_at = %s', $captured_sql );
		$this->assertStringContainsString( "state = 'received'", $captured_sql );
		$this->assertStringContainsString( 'received_at < %s', $captured_sql );

		// The cutoff bound to prepare() should be within 2s of the
		// expected gmdate-derived cutoff (clock skew between this line
		// and the call inside claim_for_retry).
		$this->assertNotNull( $captured_args );
		$bound_cutoff = $captured_args[1] ?? null;
		$this->assertNotNull( $bound_cutoff );
		$this->assertEqualsWithDelta(
			strtotime( $expected_cutoff ),
			strtotime( (string) $bound_cutoff ),
			2,
			'cutoff must be NOW - max_idle_seconds in UTC (gmdate-derived).'
		);
	}

	public function test_mark_applied_updates_state_and_applied_at_only_when_no_order_id(): void {
		$captured_data    = null;
		$captured_formats = null;
		$captured_where   = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				self::TABLE,
				Mockery::on(
					static function ( $data ) use ( &$captured_data ) {
						$captured_data = $data;
						return is_array( $data );
					}
				),
				Mockery::on(
					static function ( $where ) use ( &$captured_where ) {
						$captured_where = $where;
						return is_array( $where );
					}
				),
				Mockery::on(
					static function ( $formats ) use ( &$captured_formats ) {
						$captured_formats = $formats;
						return is_array( $formats );
					}
				),
				array( '%s' )
			)
			->andReturn( 1 );

		$this->repo->mark_applied( 'd-1' );

		$this->assertSame( array( 'state', 'applied_at' ), array_keys( $captured_data ) );
		$this->assertSame( 'applied', $captured_data['state'] );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$captured_data['applied_at']
		);
		$this->assertSame( array( '%s', '%s' ), $captured_formats );
		$this->assertSame( array( 'delivery_id' => 'd-1' ), $captured_where );
	}

	public function test_mark_applied_with_wc_order_id_appends_that_column(): void {
		$captured_data    = null;
		$captured_formats = null;

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				self::TABLE,
				Mockery::on(
					static function ( $data ) use ( &$captured_data ) {
						$captured_data = $data;
						return is_array( $data );
					}
				),
				array( 'delivery_id' => 'd-1' ),
				Mockery::on(
					static function ( $formats ) use ( &$captured_formats ) {
						$captured_formats = $formats;
						return is_array( $formats );
					}
				),
				array( '%s' )
			)
			->andReturn( 1 );

		$this->repo->mark_applied( 'd-1', 555 );

		$this->assertSame( 555, $captured_data['wc_order_id'] );
		$this->assertSame( array( '%s', '%s', '%d' ), $captured_formats );
	}

	public function test_mark_skipped_records_reason_in_error_message(): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				self::TABLE,
				Mockery::on(
					static function ( $data ) {
						return is_array( $data )
							&& 'skipped' === ( $data['state'] ?? null )
							&& 'order_not_found' === ( $data['error_message'] ?? null )
							&& isset( $data['applied_at'] );
					}
				),
				array( 'delivery_id' => 'd-1' ),
				array( '%s', '%s', '%s' ),
				array( '%s' )
			)
			->andReturn( 1 );

		$this->repo->mark_skipped( 'd-1', 'order_not_found' );
		$this->addToAssertionCount( 1 );
	}

	public function test_mark_errored_stores_message_in_error_message_column(): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				self::TABLE,
				array(
					'state'         => 'errored',
					'error_message' => 'WC_Order::payment_complete failed',
				),
				array( 'delivery_id' => 'd-1' ),
				array( '%s', '%s' ),
				array( '%s' )
			)
			->andReturn( 1 );

		$this->repo->mark_errored( 'd-1', 'WC_Order::payment_complete failed' );
		$this->addToAssertionCount( 1 );
	}

	public function test_cleanup_older_than_returns_deleted_row_count(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					static fn ( $sql ) => is_string( $sql )
						&& str_contains( $sql, 'DELETE FROM ' . self::TABLE )
						&& str_contains( $sql, 'WHERE received_at < %s' )
				),
				Mockery::on(
					static fn ( $cutoff ) => is_string( $cutoff )
						&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff )
				)
			)
			->andReturn( 'PREPARED' );

		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED' )->andReturn( 7 );

		$this->assertSame( 7, $this->repo->cleanup_older_than( 30 ) );
	}

	public function test_cleanup_older_than_returns_zero_when_query_fails(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( false );

		$this->assertSame( 0, $this->repo->cleanup_older_than( 30 ) );
	}

	public function test_list_for_order_returns_rows_for_wc_order(): void {
		$expected_sql = 'SELECT * FROM wp_spart_webhook_deliveries WHERE wc_order_id = %d ORDER BY received_at DESC LIMIT %d';
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( $expected_sql, 42, 50 )
			->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED', ARRAY_A )
			->andReturn(
				array(
					array(
						'id'            => '7',
						'delivery_id'   => 'evt_abc',
						'event_type'    => 'intent.created',
						'wc_order_id'   => '42',
						'state'         => 'received',
						'attempt_count' => '1',
						'received_at'   => '2026-05-27 09:00:00',
						'applied_at'    => null,
						'error_message' => null,
					),
				)
			);

		$rows = $this->repo->list_for_order( 42 );

		$this->assertCount( 1, $rows );
		$this->assertInstanceOf( DeliveryRow::class, $rows[0] );
		$this->assertSame( 'evt_abc', $rows[0]->delivery_id );
		$this->assertSame( 42, $rows[0]->wc_order_id );
	}

	public function test_list_for_order_clamps_limit_to_max_200(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::any(), 1, 200 )
			->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->repo->list_for_order( 1, 9999 );
		$this->addToAssertionCount( 1 );
	}

	public function test_list_for_order_clamps_limit_to_min_1(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::any(), 1, 1 )
			->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->repo->list_for_order( 1, 0 );
		$this->addToAssertionCount( 1 );
	}

	public function test_count_for_admin_no_filters(): void {
		// No filters → empty args → wpdb::prepare() MUST NOT be called.
		// Otherwise WP 5.3+ emits _doing_it_wrong and WP 6.2+ may return
		// an empty string, zeroing out the default-page pagination.
		$wpdb = $this->makeWpdb();
		$wpdb->shouldReceive( 'prepare' )->never();
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->with( 'SELECT COUNT(*) FROM wp_spart_webhook_deliveries' )
			->andReturn( '17' );

		$this->assertSame( 17, ( new DeliveryRepository( $wpdb ) )->count_for_admin( array() ) );
	}

	public function test_count_for_admin_filters_by_state_event_type_and_search(): void {
		$wpdb = $this->makeWpdb();
		$wpdb->shouldReceive( 'esc_like' )->with( 'evt_' )->andReturn( 'evt\_' );
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				'SELECT COUNT(*) FROM wp_spart_webhook_deliveries WHERE state = %s AND event_type = %s AND delivery_id LIKE %s',
				array( 'errored', 'intent.created', 'evt\_%' )
			)
			->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$this->assertSame(
			3,
			( new DeliveryRepository( $wpdb ) )->count_for_admin(
				array(
					'state'      => 'errored',
					'event_type' => 'intent.created',
					'search'     => 'evt_',
				)
			)
		);
	}

	public function test_count_for_admin_ignores_invalid_state(): void {
		// 'bogus' state is filtered out by build_where_clause → empty args →
		// same no-prepare path as test_count_for_admin_no_filters.
		$wpdb = $this->makeWpdb();
		$wpdb->shouldReceive( 'prepare' )->never();
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->with( 'SELECT COUNT(*) FROM wp_spart_webhook_deliveries' )
			->andReturn( '0' );

		( new DeliveryRepository( $wpdb ) )->count_for_admin( array( 'state' => 'bogus' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_list_for_admin_default_paging_and_order(): void {
		$wpdb = $this->makeWpdb();
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				'SELECT * FROM wp_spart_webhook_deliveries ORDER BY received_at DESC LIMIT %d OFFSET %d',
				array( 20, 0 )
			)
			->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED', ARRAY_A )
			->andReturn(
				array(
					array(
						'id'            => '1',
						'delivery_id'   => 'evt_1',
						'event_type'    => 'intent.created',
						'wc_order_id'   => '42',
						'state'         => 'applied',
						'attempt_count' => '1',
						'received_at'   => '2026-05-27 09:00:00',
						'applied_at'    => '2026-05-27 09:00:01',
						'error_message' => null,
					),
				)
			);

		$rows = ( new DeliveryRepository( $wpdb ) )->list_for_admin( 1, 20, array() );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'evt_1', $rows[0]->delivery_id );
	}

	public function test_list_for_admin_paginates(): void {
		$wpdb = $this->makeWpdb();
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::any(), array( 20, 40 ) )
			->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		( new DeliveryRepository( $wpdb ) )->list_for_admin( 3, 20, array() );
		$this->addToAssertionCount( 1 );
	}

	public function test_list_for_admin_clamps_per_page(): void {
		$wpdb = $this->makeWpdb();
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::any(), array( 200, 0 ) )
			->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		( new DeliveryRepository( $wpdb ) )->list_for_admin( 1, 9999, array() );
		$this->addToAssertionCount( 1 );
	}

	public function test_list_for_admin_rejects_invalid_orderby_and_order(): void {
		$wpdb = $this->makeWpdb();
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				'SELECT * FROM wp_spart_webhook_deliveries ORDER BY received_at DESC LIMIT %d OFFSET %d',
				array( 20, 0 )
			)
			->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		( new DeliveryRepository( $wpdb ) )->list_for_admin( 1, 20, array(), 'nope; DROP TABLE', 'sideways' );
		$this->addToAssertionCount( 1 );
	}

	public function test_list_for_admin_applies_filters(): void {
		$wpdb = $this->makeWpdb();
		$wpdb->shouldReceive( 'esc_like' )->with( 'abc' )->andReturn( 'abc' );
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				'SELECT * FROM wp_spart_webhook_deliveries WHERE state = %s AND event_type = %s AND delivery_id LIKE %s ORDER BY received_at ASC LIMIT %d OFFSET %d',
				array( 'errored', 'intent.created', 'abc%', 20, 0 )
			)
			->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		( new DeliveryRepository( $wpdb ) )->list_for_admin(
			1,
			20,
			array(
				'state'      => 'errored',
				'event_type' => 'intent.created',
				'search'     => 'abc',
			),
			'received_at',
			'ASC'
		);
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Create a fresh wpdb mock with the test prefix and empty last_error.
	 *
	 * @return \wpdb&\Mockery\MockInterface
	 */
	private function makeWpdb() {
		$wpdb             = Mockery::mock( \wpdb::class );
		$wpdb->prefix     = self::PREFIX;
		$wpdb->last_error = '';
		return $wpdb;
	}
}
