<?php
/**
 * Unit test for Checkout\OrderDisposer.
 *
 * @package Spart\WooCommerce\Tests\Unit\Checkout
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Checkout;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Checkout\CheckoutResult;
use Spart\WooCommerce\Checkout\FailureCode;
use Spart\WooCommerce\Checkout\OrderDisposer;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Logging\SpartLoggerInterface;

final class OrderDisposerTest extends TestCase {

	/** @var list<array{name:string, args:array<int, mixed>}> */
	private array $function_calls = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->function_calls = array();

		Monkey\Functions\when( 'wc_release_coupons_for_order' )->alias(
			function ( $order_id ): void {
				$this->function_calls[] = array(
					'name' => 'wc_release_coupons_for_order',
					'args' => array( $order_id ),
				);
			}
		);
		Monkey\Functions\when( 'wc_increase_stock_levels' )->alias(
			function ( $order ): void {
				$this->function_calls[] = array(
					'name' => 'wc_increase_stock_levels',
					'args' => array( $order ),
				);
			}
		);
		Monkey\Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ): void {
				$this->function_calls[] = array(
					'name' => 'do_action:' . $hook,
					'args' => $args,
				);
			}
		);
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build an OrderDisposer with a configurable api-key provider for the
	 * ErrorSanitizer redaction path.
	 */
	private function disposer( SpartLoggerInterface $logger, string $api_key = '' ): OrderDisposer {
		return new OrderDisposer( $logger, static fn(): string => $api_key );
	}

	public function test_happy_path_runs_action_delete_release_restore_in_order(): void {
		$order  = $this->order_spy( 42 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->times( 3 );
		$logger->shouldReceive( 'warning' )->once();

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-1' );

		$names = array_column( $this->function_calls, 'name' );
		$this->assertSame(
			array( 'do_action:spart_wc_order_disposing', 'WC_Order::update_meta_data', 'WC_Order::save', 'WC_Order::delete', 'do_action:spart_wc_order_destroyed', 'wc_release_coupons_for_order', 'wc_increase_stock_levels' ),
			$names,
			'delete must happen BEFORE coupon release + stock restore (delete-first); idempotency meta must be persisted via save() BEFORE delete so it survives a delete failure; spart_wc_order_destroyed action fires AFTER delete and BEFORE best-effort cleanup'
		);
		$this->assertTrue( $order->delete_called );
		$this->assertTrue( $order->delete_force, 'force=true must be passed to delete()' );
	}

	public function test_dispose_does_not_release_coupons_or_restore_stock_when_delete_fails(): void {
		$order  = $this->order_spy( 7, delete_returns: false );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->once(); // only the disposing INFO before delete.
		$logger->shouldReceive( 'error' )->once();
		$logger->shouldReceive( 'warning' )->never();

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::SERVER_ERROR );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-delfail' );

		$names = array_column( $this->function_calls, 'name' );
		$this->assertSame(
			array( 'do_action:spart_wc_order_disposing', 'WC_Order::update_meta_data', 'WC_Order::save', 'WC_Order::delete' ),
			$names,
			'when delete fails, coupons must NOT be released and stock must NOT be restored — pending order stays consistent. The persisted meta marker prevents future retry from picking it up again.'
		);
	}

	public function test_dispose_skips_when_order_is_no_longer_pending(): void {
		$order    = $this->order_spy( 88, status: 'processing' );
		$captured = null;
		$logger   = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					function ( array $ctx ) use ( &$captured ): bool {
						$captured = $ctx;
						return true;
					}
				)
			);
		$logger->shouldReceive( 'warning' )->never();
		$logger->shouldReceive( 'error' )->never();

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-skip' );

		$this->assertSame( LogEvents::DISPOSAL_SKIPPED, $captured['event'] ?? null );
		$this->assertSame( 'processing', $captured['current_status'] ?? null );
		$this->assertSame( 88, $captured['order_id'] ?? null );
		$this->assertSame( 'corr-skip', $captured['correlation_id'] ?? null );
		$this->assertFalse( $order->delete_called, 'non-pending order must NOT be deleted' );
		$this->assertSame( array(), $this->function_calls, 'no action, coupon, or stock work for non-pending orders' );
	}

	public function test_dispose_skips_when_meta_marker_already_present(): void {
		$order = $this->order_spy( 77 );
		$order->update_meta_data( OrderDisposer::META_DISPOSER_RAN, '1' );
		// Reset the spy's call log so the pre-condition write doesn't
		// pollute the post-dispose assertion that NO interaction happened.
		$this->function_calls = array();

		$captured = null;
		$logger   = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					function ( array $ctx ) use ( &$captured ): bool {
						$captured = $ctx;
						return true;
					}
				)
			);
		$logger->shouldReceive( 'warning' )->never();
		$logger->shouldReceive( 'error' )->never();

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-idem' );

		$this->assertSame( LogEvents::DISPOSAL_SKIPPED, $captured['event'] ?? null );
		$this->assertSame( 'already_ran', $captured['reason'] ?? null );
		$this->assertSame( 77, $captured['order_id'] ?? null );
		$this->assertSame( 'corr-idem', $captured['correlation_id'] ?? null );
		$this->assertFalse( $order->delete_called, 'order with disposer-ran marker must NOT be re-deleted' );
		$this->assertSame( array(), $this->function_calls, 'no action, coupon, or stock work on second call' );
	}

	public function test_dispose_marks_meta_before_delete(): void {
		$order  = $this->order_spy( 78 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-mark' );

		$names           = array_column( $this->function_calls, 'name' );
		$update_meta_idx = array_search( 'WC_Order::update_meta_data', $names, true );
		$save_idx        = array_search( 'WC_Order::save', $names, true );
		$delete_idx      = array_search( 'WC_Order::delete', $names, true );

		$this->assertNotFalse( $update_meta_idx, 'disposer must mark the order with _spart_disposer_ran meta' );
		$this->assertNotFalse( $save_idx, 'disposer must save the order to persist the meta marker' );
		$this->assertNotFalse( $delete_idx, 'disposer must still delete the order' );
		$this->assertLessThan( $delete_idx, $save_idx, 'save() must run BEFORE delete() so the marker persists if delete fails' );
		$this->assertLessThan( $save_idx, $update_meta_idx, 'update_meta_data must precede save()' );

		$update_call = $this->function_calls[ $update_meta_idx ];
		$this->assertSame( OrderDisposer::META_DISPOSER_RAN, $update_call['args'][0] ?? null );
		$this->assertSame( '1', $update_call['args'][1] ?? null );
	}

	public function test_dispose_emits_per_step_events_in_canonical_order(): void {
		$order  = $this->order_spy( 17 );
		$events = array();
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )
			->times( 3 )
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					function ( array $ctx ) use ( &$events ): bool {
						$events[] = $ctx['event'] ?? null;
						return true;
					}
				)
			);
		$logger->shouldReceive( 'warning' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					function ( array $ctx ) use ( &$events ): bool {
						$events[] = $ctx['event'] ?? null;
						return true;
					}
				)
			);

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-events' );

		// Under delete-first semantics, spart_order_deleted fires
		// immediately after delete returns truthy, BEFORE coupon
		// release + stock restore (best-effort post-delete cleanup).
		$this->assertSame(
			array(
				LogEvents::ORDER_DISPOSING,
				LogEvents::ORDER_DELETED,
				LogEvents::COUPONS_RELEASED,
				LogEvents::STOCK_RESTORED,
			),
			$events
		);
	}

	public function test_dispose_emits_warning_log_with_correlation_and_failure_code(): void {
		$order    = $this->order_spy( 99 );
		$captured = array();
		$logger   = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					function ( array $ctx ) use ( &$captured ): bool {
						$captured = $ctx;
						return true;
					}
				)
			);

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::AUTH_FAILED );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-2' );

		$this->assertSame( 'corr-2', $captured['correlation_id'] ?? null );
		$this->assertSame( 99, $captured['order_id'] ?? null );
		$this->assertSame( FailureCode::AUTH_FAILED, $captured['failure_code'] ?? null );
		$this->assertSame( LogEvents::ORDER_DELETED, $captured['event'] ?? null );
	}

	public function test_delete_failure_logs_error_and_does_not_throw(): void {
		$order  = $this->order_spy( 7, delete_returns: false );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$captured = null;
		$logger->shouldReceive( 'error' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					function ( array $ctx ) use ( &$captured ): bool {
						$captured = $ctx;
						return true;
					}
				)
			);

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::SERVER_ERROR );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-3' );

		$this->assertSame( LogEvents::DISPOSAL_FAILED, $captured['event'] ?? null );
		$this->assertSame( FailureCode::SERVER_ERROR, $captured['failure_code'] ?? null );
	}

	public function test_stock_restore_throwable_after_delete_logs_disposal_failed(): void {
		Monkey\Functions\when( 'wc_increase_stock_levels' )->alias(
			static function ( $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature must match WC.
				throw new \RuntimeException( 'stock blew up' );
			}
		);

		$order        = $this->order_spy( 11 );
		$captured     = null;
		$captured_msg = null;
		$logger       = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes(); // spart_order_deleted fired before throw.
		$logger->shouldReceive( 'error' )
			->once()
			->with(
				Mockery::on(
					function ( string $msg ) use ( &$captured_msg ): bool {
						$captured_msg = $msg;
						return true;
					}
				),
				Mockery::on(
					function ( array $ctx ) use ( &$captured ): bool {
						$captured = $ctx;
						return true;
					}
				)
			);

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );

		// Must NOT rethrow.
		$disposer->dispose( $order, $result, 'corr-4' );

		$this->assertSame( LogEvents::DISPOSAL_FAILED, $captured['event'] ?? null );
		$this->assertTrue( $order->delete_called, 'delete runs before stock under delete-first semantics' );
		$this->assertStringContainsString( 'RuntimeException:', (string) $captured_msg, 'message must be ErrorSanitizer-formatted with short class prefix' );
		$this->assertStringContainsString( 'stock blew up', (string) $captured_msg );
	}

	public function test_disposal_error_log_redacts_api_key_via_error_sanitizer(): void {
		$api_key = 'sk_live_super_secret_abc123';

		Monkey\Functions\when( 'wc_increase_stock_levels' )->alias(
			static function ( $order ) use ( $api_key ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature must match WC.
				// Simulate a downstream throwable whose message accidentally
				// embeds the merchant API key — exactly the kind of leak
				// ErrorSanitizer is designed to scrub.
				throw new \RuntimeException( 'curl error 7 with auth ' . $api_key . ' rejected' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $api_key is a test-only literal; the whole point of this test is to verify the disposer sanitises it before logging.
			}
		);

		$order        = $this->order_spy( 99 );
		$captured_msg = null;
		$logger       = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )
			->once()
			->with(
				Mockery::on(
					function ( string $msg ) use ( &$captured_msg ): bool {
						$captured_msg = $msg;
						return true;
					}
				),
				Mockery::type( 'array' )
			);

		$result   = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );
		$disposer = $this->disposer( $logger, $api_key );

		$disposer->dispose( $order, $result, 'corr-redact' );

		$this->assertStringNotContainsString( $api_key, (string) $captured_msg, 'raw API key must NEVER appear in disposer error logs' );
		$this->assertStringContainsString( '<redacted>', (string) $captured_msg, 'sanitizer must replace the key with its redaction sentinel' );
		$this->assertStringContainsString( 'RuntimeException:', (string) $captured_msg, 'message must be ErrorSanitizer-formatted' );
	}

	/**
	 * @dataProvider shopper_controllable_failure_codes
	 */
	public function test_dispose_skips_coupon_release_for_shopper_controllable_failure_codes( string $failure_code ): void {
		$order  = $this->order_spy( 55 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->never();

		$result = CheckoutResult::failure( 'msg.', 'log.', $failure_code );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-allowlist' );

		$names = array_column( $this->function_calls, 'name' );
		$this->assertNotContains(
			'wc_release_coupons_for_order',
			$names,
			"coupons must NOT be released for shopper-controllable failure_code '{$failure_code}' (would enable infinite single-use-coupon reapplication via deliberate failure)"
		);
		$this->assertContains( 'WC_Order::delete', $names, 'delete must still run regardless of failure_code' );
		$this->assertContains( 'wc_increase_stock_levels', $names, 'stock must still be restored — stock-hold is not an abuse vector' );
	}

	/** @return iterable<string, array{0: string}> */
	public static function shopper_controllable_failure_codes(): iterable {
		yield 'free_order'      => array( FailureCode::FREE_ORDER );
		yield 'validation'      => array( FailureCode::VALIDATION );
		yield 'missing_api_key' => array( FailureCode::MISSING_API_KEY );
		yield 'malformed'       => array( FailureCode::MALFORMED );
	}

	/**
	 * @dataProvider non_shopper_controllable_failure_codes
	 */
	public function test_dispose_releases_coupons_for_non_shopper_controllable_failure_codes( string $failure_code ): void {
		$order  = $this->order_spy( 56 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->never();

		$result = CheckoutResult::failure( 'msg.', 'log.', $failure_code );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-release' );

		$names = array_column( $this->function_calls, 'name' );
		$this->assertContains(
			'wc_release_coupons_for_order',
			$names,
			"coupons SHOULD be released for non-shopper-controllable failure_code '{$failure_code}' (legitimate server/network failure)"
		);
	}

	/** @return iterable<string, array{0: string}> */
	public static function non_shopper_controllable_failure_codes(): iterable {
		yield 'timeout'      => array( FailureCode::TIMEOUT );
		yield 'auth_failed'  => array( FailureCode::AUTH_FAILED );
		yield 'rate_limited' => array( FailureCode::RATE_LIMITED );
		yield 'server_error' => array( FailureCode::SERVER_ERROR );
		yield 'transport'    => array( FailureCode::TRANSPORT );
		yield 'api_error'    => array( FailureCode::API_ERROR );
		yield 'unknown'      => array( FailureCode::UNKNOWN );
	}

	public function test_dispose_logs_coupons_release_skipped_event_with_reason_and_failure_code(): void {
		$order    = $this->order_spy( 57 );
		$captured = null;
		$logger   = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->never();
		// Capture every info call, then assert one matches the skip event.
		$infos = array();
		$logger->shouldReceive( 'info' )
			->zeroOrMoreTimes()
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					function ( array $ctx ) use ( &$infos ): bool {
						$infos[] = $ctx;
						return true;
					}
				)
			);

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::FREE_ORDER );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-skip-coupons' );

		$skip_lines = array_values(
			array_filter(
				$infos,
				static fn( array $ctx ): bool => ( $ctx['event'] ?? null ) === LogEvents::COUPONS_RELEASE_SKIPPED
			)
		);

		$this->assertCount( 1, $skip_lines, 'disposer must emit exactly one spart_coupons_release_skipped INFO line when allowlist suppresses release' );
		$captured = $skip_lines[0];
		$this->assertSame( 'shopper_controllable_failure_code', $captured['reason'] ?? null );
		$this->assertSame( FailureCode::FREE_ORDER, $captured['failure_code'] ?? null );
		$this->assertSame( 57, $captured['order_id'] ?? null );
		$this->assertSame( 'corr-skip-coupons', $captured['correlation_id'] ?? null );

		// And the canonical COUPONS_RELEASED event must NOT also have been emitted.
		$released_lines = array_filter(
			$infos,
			static fn( array $ctx ): bool => ( $ctx['event'] ?? null ) === LogEvents::COUPONS_RELEASED
		);
		$this->assertCount( 0, $released_lines, 'skip path must NOT also emit the canonical spart_coupons_released event' );
	}

	public function test_dispose_fires_destroyed_action_after_successful_delete(): void {
		$order  = $this->order_spy( 1001 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->never();

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-action-1001' );

		$destroyed = array_values(
			array_filter(
				$this->function_calls,
				static fn( array $entry ): bool => 'do_action:' . OrderDisposer::HOOK_DESTROYED === $entry['name']
			)
		);

		$this->assertCount( 1, $destroyed, 'spart_wc_order_destroyed must fire exactly once after a successful delete' );
		$this->assertSame(
			array( 1001, FailureCode::TIMEOUT, 'corr-action-1001' ),
			$destroyed[0]['args'],
			'destroyed action must receive (order_id, failure_code, correlation_id)'
		);

		// Ordering invariant: the destroyed action must fire AFTER the delete
		// (so subscribers know the order is gone), and ideally before the
		// best-effort cleanup steps so metric collectors get the event
		// even if release/restore later throw.
		$names         = array_column( $this->function_calls, 'name' );
		$delete_idx    = array_search( 'WC_Order::delete', $names, true );
		$destroyed_idx = array_search( 'do_action:' . OrderDisposer::HOOK_DESTROYED, $names, true );
		$this->assertNotFalse( $delete_idx );
		$this->assertNotFalse( $destroyed_idx );
		$this->assertGreaterThan( $delete_idx, $destroyed_idx, 'destroyed action must fire AFTER delete' );
	}

	public function test_dispose_does_not_fire_destroyed_action_when_delete_fails(): void {
		$order  = $this->order_spy( 1002, delete_returns: false );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->once();
		$logger->shouldReceive( 'error' )->once();

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::SERVER_ERROR );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-action-1002' );

		$destroyed = array_filter(
			$this->function_calls,
			static fn( array $entry ): bool => 'do_action:' . OrderDisposer::HOOK_DESTROYED === $entry['name']
		);
		$this->assertCount( 0, $destroyed, 'destroyed action must NOT fire when delete returned false' );
	}

	/**
	 * Defense-in-depth: a hostile or buggy third-party subscriber to
	 * spart_wc_order_destroyed must NOT be able to suppress the best-effort
	 * coupon-release and stock-restore steps. Today both do_action calls
	 * sit inside the outer try, so a throwing subscriber would short-circuit
	 * the catch and skip the cleanup — leaking coupon usage_count and
	 * managed stock on every failed checkout. The fix wraps each do_action
	 * in its own try/catch that logs the throw and continues.
	 *
	 * @return void
	 */
	public function test_dispose_continues_cleanup_when_destroyed_action_subscriber_throws(): void {
		$order  = $this->order_spy( 1003 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		// Exactly one error: from the hook-subscriber-threw branch.
		$logger->shouldReceive( 'error' )->once();

		// Replace do_action with an alias that throws specifically on the
		// destroyed hook (mimicking a hostile/buggy subscriber).
		Monkey\Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ): void {
				$this->function_calls[] = array(
					'name' => 'do_action:' . $hook,
					'args' => $args,
				);
				if ( OrderDisposer::HOOK_DESTROYED === $hook ) {
					throw new \RuntimeException( 'hostile subscriber blew up' );
				}
			}
		);

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-hostile-destroyed' );

		$names = array_column( $this->function_calls, 'name' );
		$this->assertContains(
			'wc_release_coupons_for_order',
			$names,
			'coupon release MUST still run when a destroyed-hook subscriber throws — subscriber must not be able to leak coupon usage_count for a deleted order'
		);
		$this->assertContains(
			'wc_increase_stock_levels',
			$names,
			'stock restore MUST still run when a destroyed-hook subscriber throws — subscriber must not be able to permanently hold stock for a deleted order'
		);
	}

	/**
	 * Symmetric defense: a hostile subscriber on spart_wc_order_disposing
	 * (which fires BEFORE delete) must not be able to leave the order in
	 * a never-disposed state — the fix wraps that do_action in its own
	 * try/catch too. The idempotency marker and delete must still run.
	 *
	 * @return void
	 */
	public function test_dispose_continues_when_disposing_action_subscriber_throws(): void {
		$order  = $this->order_spy( 1004 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		Monkey\Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ): void {
				$this->function_calls[] = array(
					'name' => 'do_action:' . $hook,
					'args' => $args,
				);
				if ( OrderDisposer::HOOK_DISPOSING === $hook ) {
					throw new \RuntimeException( 'hostile subscriber blew up' );
				}
			}
		);

		$result = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );

		$disposer = $this->disposer( $logger );
		$disposer->dispose( $order, $result, 'corr-hostile-disposing' );

		$names = array_column( $this->function_calls, 'name' );
		$this->assertContains(
			'WC_Order::delete',
			$names,
			'order delete MUST still run when a disposing-hook subscriber throws — subscriber must not be able to permanently block disposal'
		);
	}

	/**
	 * Defense-in-depth: the api_key_provider closure is invoked inside both
	 * the outer catch and safe_do_action's catch to resolve the API key for
	 * ErrorSanitizer. The disposer's contract is "NEVER rethrows" — verify
	 * that even if the provider itself throws (a future faulty wiring, or
	 * a transient WP error during get_option), the disposer still completes
	 * cleanup without escaping the throw to the caller. We use a hostile
	 * HOOK_DESTROYED subscriber to force safe_do_action into its catch.
	 *
	 * @return void
	 */
	public function test_dispose_does_not_rethrow_when_api_key_provider_throws_inside_safe_do_action(): void {
		$order  = $this->order_spy( 1005 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		Monkey\Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ): void {
				$this->function_calls[] = array(
					'name' => 'do_action:' . $hook,
					'args' => $args,
				);
				if ( OrderDisposer::HOOK_DESTROYED === $hook ) {
					throw new \RuntimeException( 'hostile subscriber' );
				}
			}
		);

		$result   = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );
		$disposer = new OrderDisposer(
			$logger,
			static function (): string {
				throw new \RuntimeException( 'api_key_provider blew up' );
			}
		);

		$disposer->dispose( $order, $result, 'corr-key-throws-inner' );

		$names = array_column( $this->function_calls, 'name' );
		$this->assertContains(
			'wc_release_coupons_for_order',
			$names,
			'cleanup MUST still run when the api_key_provider closure itself throws — disposer contract is NEVER rethrow'
		);
	}

	/**
	 * Symmetric: the outer catch around the destructive sequence also
	 * invokes api_key_provider for ErrorSanitizer. If a cleanup step
	 * throws AND api_key_provider throws, the disposer must still log at
	 * ERROR and return without rethrowing — preserving the no-rethrow
	 * contract even under cascading failure.
	 *
	 * @return void
	 */
	public function test_dispose_does_not_rethrow_when_api_key_provider_throws_inside_outer_catch(): void {
		$order  = $this->order_spy( 1006 );
		$logger = Mockery::mock( SpartLoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		Monkey\Functions\when( 'do_action' )->justReturn( null );
		Monkey\Functions\when( 'wc_release_coupons_for_order' )->alias(
			function (): void {
				throw new \RuntimeException( 'release blew up' );
			}
		);

		$result   = CheckoutResult::failure( 'msg.', 'log.', FailureCode::TIMEOUT );
		$disposer = new OrderDisposer(
			$logger,
			static function (): string {
				throw new \RuntimeException( 'api_key_provider blew up' );
			}
		);

		$disposer->dispose( $order, $result, 'corr-key-throws-outer' );

		$this->assertTrue( true, 'dispose() returned without rethrowing despite both wc_release_coupons_for_order() and api_key_provider throwing' );
	}

	/**
	 * Build a tracking \WC_Order subclass for assertions.
	 */
	private function order_spy( int $id, bool $delete_returns = true, string $status = 'pending' ): \WC_Order {
		$calls_ref = &$this->function_calls;
		$order     = new class( $delete_returns, $calls_ref ) extends \WC_Order {
			public bool $delete_called = false;
			public bool $delete_force  = false;
			/**
			 * @param list<array{name:string, args:array<int, mixed>}> $calls_ref
			 */
			public function __construct( private bool $delete_returns, private array &$calls_ref ) {}
			public function delete( bool $force = false ): bool {
				$this->delete_called = true;
				$this->delete_force  = $force;
				$this->calls_ref[]   = array(
					'name' => 'WC_Order::delete',
					'args' => array( $force ),
				);
				return $this->delete_returns;
			}
			public function update_meta_data( string $key, mixed $value ): void {
				$this->calls_ref[] = array(
					'name' => 'WC_Order::update_meta_data',
					'args' => array( $key, $value ),
				);
				parent::update_meta_data( $key, $value );
			}
			public function save(): int {
				$this->calls_ref[] = array(
					'name' => 'WC_Order::save',
					'args' => array(),
				);
				return parent::save();
			}
			public function get_coupon_codes(): array {
				return array( 'SAVE10' );
			}
		};
		$order->__test_init(
			array(
				'id'       => $id,
				'currency' => 'USD',
				'status'   => $status,
			)
		);
		return $order;
	}
}
