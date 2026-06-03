<?php
/**
 * Unit tests for Webhooks\WebhookReceiver.
 *
 * @package Spart\WooCommerce\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Webhooks;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spart\Sdk\Webhooks\Event;
use Spart\Sdk\Webhooks\SignatureVerifier;
use Spart\WooCommerce\Logging\SpartLoggerInterface;
use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Webhooks\DeliveryRow;
use Spart\WooCommerce\Webhooks\OrderSync;
use Spart\WooCommerce\Webhooks\ResolverResult;
use Spart\WooCommerce\Webhooks\WebhookReceiver;
use Spart\WooCommerce\Webhooks\WpOrderResolver;

/**
 * @covers \Spart\WooCommerce\Webhooks\WebhookReceiver
 */
final class WebhookReceiverTest extends TestCase {

	private const DELIVERY_ID    = 'd-abc-123';
	private const SIGNING_SECRET = 'test_secret_2026';

	/** @var DeliveryRepository&\Mockery\MockInterface */
	private DeliveryRepository $deliveries;

	/** @var WpOrderResolver&\Mockery\MockInterface */
	private WpOrderResolver $resolver;

	/** @var OrderSync&\Mockery\MockInterface */
	private OrderSync $order_sync;

	/** @var SpartLoggerInterface&\Mockery\MockInterface */
	private SpartLoggerInterface $logger;

	private WebhookReceiver $receiver;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->deliveries = Mockery::mock( DeliveryRepository::class );
		$this->resolver   = Mockery::mock( WpOrderResolver::class );
		$this->order_sync = Mockery::mock( OrderSync::class );
		$this->logger     = Mockery::mock( SpartLoggerInterface::class );
		$this->logger->shouldIgnoreMissing();

		$this->receiver = new WebhookReceiver(
			new SignatureVerifier( self::SIGNING_SECRET ),
			$this->deliveries,
			$this->order_sync,
			$this->resolver,
			$this->logger
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// -------------------------------------------------------------------
	// Inputs
	// -------------------------------------------------------------------

	public function test_returns_400_when_delivery_id_header_is_empty(): void {
		$request = new \WP_REST_Request( '{}', array() );

		$response = $this->receiver->handle( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'error' => 'missing_delivery_id' ), $response->get_data() );
	}

	public function test_returns_401_when_signature_is_invalid(): void {
		$body    = $this->order_completed_body();
		$request = new \WP_REST_Request(
			$body,
			array(
				WebhookReceiver::HEADER_DELIVERY_ID => self::DELIVERY_ID,
				WebhookReceiver::HEADER_SIGNATURE   => 't=1,v1=badhex',
				WebhookReceiver::HEADER_ATTEMPT     => '1',
			)
		);

		$response = $this->receiver->handle( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( array( 'error' => 'invalid_signature' ), $response->get_data() );
	}

	public function test_returns_401_when_envelope_is_malformed(): void {
		// Validly-signed body, but body is not a valid event envelope.
		$response = $this->receiver->handle( $this->signed_request( '{"not":"an event"}' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( array( 'error' => 'invalid_signature' ), $response->get_data() );
	}

	// -------------------------------------------------------------------
	// Race-loss path (insert_received returns false → 200 {deduped:true})
	// -------------------------------------------------------------------

	public function test_returns_200_deduped_when_insert_received_reports_race_loss(): void {
		// Find() sees no row yet (this worker hadn't observed the
		// concurrent delivery). Resolver succeeds normally. Then
		// insert_received returns false — the unique key was stolen
		// by a concurrent receiver between find() and insert().
		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );

		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( $this->resolved_order_stub() );

		$this->deliveries->shouldReceive( 'insert_received' )->once()
			->with( self::DELIVERY_ID, 'order.completed', null )
			->andReturn( false );

		// MUST NOT enter the apply path on race-loss.
		$this->deliveries->shouldNotReceive( 'increment_attempt' );
		$this->deliveries->shouldNotReceive( 'claim_for_retry' );
		$this->deliveries->shouldNotReceive( 'mark_applied' );
		$this->deliveries->shouldNotReceive( 'mark_skipped' );
		$this->deliveries->shouldNotReceive( 'mark_errored' );
		$this->order_sync->shouldNotReceive( 'apply' );

		// Logger contract: exactly one structured webhook.race_lost info
		// entry with reason=insert_collision (distinguishes this branch
		// from the in_progress one that fires on attempt=1 + existing
		// row + fresh received_at). The usual webhook.received entry is
		// suppressed (we did not 'receive' the delivery — another
		// worker did).
		$this->logger = Mockery::mock( SpartLoggerInterface::class );
		$this->logger->shouldReceive( 'warning' )->never();
		$this->logger->shouldReceive( 'error' )->never();
		$this->logger->shouldReceive( 'info' )->with( 'webhook.received', Mockery::any() )->never();
		$this->logger->shouldReceive( 'info' )->with( 'webhook.applied', Mockery::any() )->never();
		$this->logger->shouldReceive( 'info' )->with(
			'webhook.race_lost',
			Mockery::on(
				static function ( $context ) {
					return is_array( $context )
						&& self::DELIVERY_ID === ( $context['delivery_id'] ?? null )
						&& 'order.completed' === ( $context['event_type'] ?? null )
						&& 'insert_collision' === ( $context['reason'] ?? null );
				}
			)
		)->once();
		$this->logger->shouldReceive( 'debug' );
		$this->receiver = $this->build_receiver();

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'deduped' => true ), $response->get_data() );
	}

	// -------------------------------------------------------------------
	// Dedupe (terminal states → 200 {deduped:true})
	// -------------------------------------------------------------------

	public function test_returns_500_when_insert_received_throws_and_handler_emits_structured_log(): void {
		// insert_received() throws on a genuine DB failure (e.g. wpdb
		// timeout, dropped connection). The widened try/catch in handle()
		// MUST catch it, sanitize it, emit the structured
		// webhook.handler_exception log, and return the standardised
		// 500 {error: 'handler_exception'} shape — NOT bubble up as a
		// raw exception that bypasses ErrorSanitizer.
		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );

		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( $this->resolved_order_stub() );

		$this->deliveries->shouldReceive( 'insert_received' )->once()
			->with( self::DELIVERY_ID, 'order.completed', null )
			->andThrow( new \RuntimeException( 'DB unavailable' ) );

		// MUST NOT touch the order / mark anything after the throw.
		$this->deliveries->shouldNotReceive( 'increment_attempt' );
		$this->deliveries->shouldNotReceive( 'claim_for_retry' );
		$this->deliveries->shouldNotReceive( 'mark_applied' );
		$this->deliveries->shouldNotReceive( 'mark_skipped' );
		$this->deliveries->shouldNotReceive( 'mark_errored' );
		$this->order_sync->shouldNotReceive( 'apply' );

		$this->logger = Mockery::mock( SpartLoggerInterface::class );
		$this->logger->shouldReceive( 'info' );
		$this->logger->shouldReceive( 'warning' )->never();
		$this->logger->shouldReceive( 'debug' );
		$this->logger->shouldReceive( 'error' )->with(
			'webhook.handler_exception',
			Mockery::on(
				static function ( $context ) {
					return is_array( $context )
						&& self::DELIVERY_ID === ( $context['delivery_id'] ?? null )
						&& 'order.completed' === ( $context['event_type'] ?? null )
						&& isset( $context['error'] )
						&& str_contains( (string) $context['error'], 'RuntimeException' )
						&& str_contains( (string) $context['error'], 'DB unavailable' );
				}
			)
		)->once();
		$this->receiver = $this->build_receiver();

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array( 'error' => 'handler_exception' ), $response->get_data() );
	}

	public function test_returns_200_deduped_for_already_applied_delivery(): void {
		$this->deliveries->shouldReceive( 'find' )->once()->with( self::DELIVERY_ID )
			->andReturn( $this->make_delivery_row( 'applied' ) );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'deduped' => true ), $response->get_data() );
	}

	public function test_returns_200_deduped_for_already_skipped_delivery(): void {
		$this->deliveries->shouldReceive( 'find' )->once()->with( self::DELIVERY_ID )
			->andReturn( $this->make_delivery_row( 'skipped' ) );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'deduped' => true ), $response->get_data() );
	}

	public function test_returns_200_deduped_for_already_errored_delivery(): void {
		$this->deliveries->shouldReceive( 'find' )->once()->with( self::DELIVERY_ID )
			->andReturn( $this->make_delivery_row( 'errored' ) );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'deduped' => true ), $response->get_data() );
	}

	// -------------------------------------------------------------------
	// Dedupe (received row, attempt>1 → increment_attempt then proceed)
	// -------------------------------------------------------------------

	public function test_increments_attempt_when_row_in_received_state_and_attempt_is_retry(): void {
		// Sequential dispatcher retry (attempt=2). Existing row is in
		// 'received' (e.g. previous attempt crashed mid-apply). We
		// trust the signed attempt header and bump the counter
		// unconditionally — no claim_for_retry idle check needed
		// because dispatcher retry intervals are minutes.
		$this->deliveries->shouldReceive( 'find' )->once()
			->andReturn( $this->make_delivery_row( 'received' ) );
		$this->deliveries->shouldReceive( 'increment_attempt' )->once()->with( self::DELIVERY_ID );
		$this->deliveries->shouldNotReceive( 'claim_for_retry' );

		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( new ResolverResult( ResolverResult::REASON_UNKNOWN_EVENT ) );
		$this->deliveries->shouldReceive( 'mark_skipped' )->once();

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body(), 2 ) );

		$this->assertSame( 200, $response->get_status() );
	}

	// -------------------------------------------------------------------
	// Dedupe (received row, attempt=1 → claim_for_retry branch)
	// -------------------------------------------------------------------

	public function test_claims_for_retry_when_row_in_received_state_and_attempt_is_one(): void {
		// Concurrent receiver: attempt=1 + an existing 'received' row.
		// Either the other worker died (stale row) or is mid-apply
		// (fresh row). claim_for_retry returns true on the stale path
		// — we then proceed exactly as on the increment_attempt path.
		// (Apply uses the resolver result and goes through the normal
		// skip/apply flow.)
		$this->deliveries->shouldReceive( 'find' )->once()
			->andReturn( $this->make_delivery_row( 'received' ) );
		$this->deliveries->shouldReceive( 'claim_for_retry' )
			->once()
			->with( self::DELIVERY_ID, WebhookReceiver::DELIVERY_RETRY_IDLE_SECONDS )
			->andReturn( true );
		$this->deliveries->shouldNotReceive( 'increment_attempt' );

		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( new ResolverResult( ResolverResult::REASON_UNKNOWN_EVENT ) );
		$this->deliveries->shouldReceive( 'mark_skipped' )->once();

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body(), 1 ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_short_circuits_200_deduped_when_claim_for_retry_fails_in_progress(): void {
		// Concurrent receiver, attempt=1, existing 'received' row, but
		// the row is too fresh (another worker is mid-apply). The
		// atomic UPDATE matches zero rows → claim_for_retry returns
		// false → we MUST short-circuit 200 deduped WITHOUT touching
		// the order, without calling apply, without writing any state.
		// This closes the apply-time TOCTOU race (#227).
		$this->deliveries->shouldReceive( 'find' )->once()
			->andReturn( $this->make_delivery_row( 'received' ) );
		$this->deliveries->shouldReceive( 'claim_for_retry' )
			->once()
			->with( self::DELIVERY_ID, WebhookReceiver::DELIVERY_RETRY_IDLE_SECONDS )
			->andReturn( false );

		// Must NOT proceed to apply / increment / mark anything.
		$this->deliveries->shouldNotReceive( 'increment_attempt' );
		$this->deliveries->shouldNotReceive( 'insert_received' );
		$this->deliveries->shouldNotReceive( 'mark_applied' );
		$this->deliveries->shouldNotReceive( 'mark_skipped' );
		$this->deliveries->shouldNotReceive( 'mark_errored' );
		$this->order_sync->shouldNotReceive( 'apply' );

		// Logger contract: a single structured webhook.race_lost info
		// entry with reason=in_progress so ops can tell this branch
		// apart from the insert-collision branch.
		$this->logger = Mockery::mock( SpartLoggerInterface::class );
		$this->logger->shouldReceive( 'warning' )->never();
		$this->logger->shouldReceive( 'error' )->never();
		$this->logger->shouldReceive( 'info' )->with( 'webhook.received', Mockery::any() )->never();
		$this->logger->shouldReceive( 'info' )->with( 'webhook.applied', Mockery::any() )->never();
		$this->logger->shouldReceive( 'info' )->with(
			'webhook.race_lost',
			Mockery::on(
				static function ( $context ) {
					return is_array( $context )
						&& self::DELIVERY_ID === ( $context['delivery_id'] ?? null )
						&& 'order.completed' === ( $context['event_type'] ?? null )
						&& 'in_progress' === ( $context['reason'] ?? null );
				}
			)
		)->once();
		$this->logger->shouldReceive( 'debug' );

		// Resolver still runs (it's called before the existing-row
		// branch in handle()), but the short-circuit fires before
		// apply or any dedupe-row mutation.
		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( $this->resolved_order_stub() );

		$this->receiver = $this->build_receiver();

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body(), 1 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'deduped' => true ), $response->get_data() );
	}

	// -------------------------------------------------------------------
	// webhook.test → 204 + mark_applied + log webhook.applied
	// -------------------------------------------------------------------

	public function test_returns_204_for_webhook_test_and_marks_applied_and_logs_webhook_applied(): void {
		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->deliveries->shouldReceive( 'insert_received' )->once()
			->with( self::DELIVERY_ID, 'webhook.test', null )
			->andReturn( true );
		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( new ResolverResult( ResolverResult::REASON_TEST_EVENT ) );
		$this->deliveries->shouldReceive( 'mark_applied' )->once()->with( self::DELIVERY_ID, null );

		// Re-mock the logger to assert webhook.applied is called.
		$this->logger = Mockery::mock( SpartLoggerInterface::class );
		$this->logger->shouldReceive( 'warning' )->never();
		$this->logger->shouldReceive( 'error' )->never();
		$this->logger->shouldReceive( 'info' )->with( 'webhook.received', Mockery::type( 'array' ) )->once();
		$this->logger->shouldReceive( 'info' )->with(
			'webhook.applied',
			Mockery::on(
				static function ( $context ) {
					return is_array( $context )
						&& self::DELIVERY_ID === ( $context['delivery_id'] ?? null )
						&& 'webhook.test' === ( $context['event_type'] ?? null )
						&& array_key_exists( 'wc_order_id', $context )
						&& null === $context['wc_order_id'];
				}
			)
		)->once();
		$this->logger->shouldReceive( 'debug' );
		$this->receiver = $this->build_receiver();

		$response = $this->receiver->handle( $this->signed_request( $this->webhook_test_body() ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	// -------------------------------------------------------------------
	// Skip branches (200 {skipped:reason})
	// -------------------------------------------------------------------

	public function test_returns_200_skipped_for_unknown_event(): void {
		$this->run_skip_test( ResolverResult::REASON_UNKNOWN_EVENT );
	}

	public function test_returns_200_skipped_for_trashed_order(): void {
		$this->run_skip_test( ResolverResult::REASON_ORDER_TRASHED );
	}

	public function test_returns_400_for_sibling_site_with_error_body_and_no_dedupe_row(): void {
		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( new ResolverResult( ResolverResult::REASON_SIBLING_SITE ) );
		$this->deliveries->shouldNotReceive( 'insert_received' );
		$this->deliveries->shouldNotReceive( 'increment_attempt' );
		$this->deliveries->shouldNotReceive( 'claim_for_retry' );
		$this->deliveries->shouldNotReceive( 'mark_skipped' );
		$this->deliveries->shouldNotReceive( 'mark_applied' );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			array( 'error' => ResolverResult::REASON_SIBLING_SITE ),
			$response->get_data()
		);
	}

	public function test_returns_400_for_no_session_id_with_error_body_and_no_dedupe_row(): void {
		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( new ResolverResult( ResolverResult::REASON_NO_SESSION_ID ) );
		$this->deliveries->shouldNotReceive( 'insert_received' );
		$this->deliveries->shouldNotReceive( 'mark_skipped' );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			array( 'error' => ResolverResult::REASON_NO_SESSION_ID ),
			$response->get_data()
		);
	}

	public function test_returns_400_for_malformed_session_with_error_body_and_no_dedupe_row(): void {
		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( new ResolverResult( ResolverResult::REASON_MALFORMED_SESSION ) );
		$this->deliveries->shouldNotReceive( 'insert_received' );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			array( 'error' => ResolverResult::REASON_MALFORMED_SESSION ),
			$response->get_data()
		);
	}

	public function test_returns_404_for_order_not_found_with_error_body_and_no_dedupe_row(): void {
		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( new ResolverResult( ResolverResult::REASON_ORDER_NOT_FOUND ) );
		$this->deliveries->shouldNotReceive( 'insert_received' );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame(
			array( 'error' => ResolverResult::REASON_ORDER_NOT_FOUND ),
			$response->get_data()
		);
	}

	// -------------------------------------------------------------------
	// Defensive idempotency (order meta already records this delivery_id)
	// -------------------------------------------------------------------

	public function test_defensive_idempotency_returns_200_deduped_when_meta_matches(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( WebhookReceiver::ORDER_DEDUPE_META_KEY )
			->andReturn( self::DELIVERY_ID );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );

		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->deliveries->shouldReceive( 'insert_received' )->once()->andReturn( true );
		$this->resolver->shouldReceive( 'resolve' )->once()->andReturn( $order );
		$this->deliveries->shouldReceive( 'mark_skipped' )->once()
			->with( self::DELIVERY_ID, 'already_applied' );

		$this->order_sync->shouldNotReceive( 'apply' );
		$this->deliveries->shouldNotReceive( 'mark_applied' );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'deduped' => true ), $response->get_data() );
	}

	// -------------------------------------------------------------------
	// Happy path
	// -------------------------------------------------------------------

	public function test_happy_path_applies_saves_meta_and_returns_204(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( WebhookReceiver::ORDER_DEDUPE_META_KEY )
			->andReturn( '' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'update_meta_data' )->once()
			->with( WebhookReceiver::ORDER_DEDUPE_META_KEY, self::DELIVERY_ID );
		$order->shouldReceive( 'save' )->once();

		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->deliveries->shouldReceive( 'insert_received' )->once()
			->with( self::DELIVERY_ID, 'order.completed', null )
			->andReturn( true );
		$this->resolver->shouldReceive( 'resolve' )->once()->andReturn( $order );
		$this->order_sync->shouldReceive( 'apply' )->once()
			->with( $order, Mockery::type( Event::class ) );
		$this->deliveries->shouldReceive( 'mark_applied' )->once()
			->with( self::DELIVERY_ID, 42 );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	// -------------------------------------------------------------------
	// Apply throws → 500, dedupe row left in 'received' for retry
	// -------------------------------------------------------------------

	public function test_returns_500_when_apply_throws_and_does_not_mark_errored(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( WebhookReceiver::ORDER_DEDUPE_META_KEY )
			->andReturn( '' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );

		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->deliveries->shouldReceive( 'insert_received' )->once()->andReturn( true );
		$this->resolver->shouldReceive( 'resolve' )->once()->andReturn( $order );
		$this->order_sync->shouldReceive( 'apply' )->once()
			->andThrow( new \RuntimeException( 'DB unavailable' ) );

		// Spec: the dedupe row is LEFT in 'received' for retry.
		$this->deliveries->shouldNotReceive( 'mark_errored' );
		$this->deliveries->shouldNotReceive( 'mark_applied' );

		$this->logger = Mockery::mock( SpartLoggerInterface::class );
		$this->logger->shouldReceive( 'info' );
		$this->logger->shouldReceive( 'error' )->with(
			'webhook.handler_exception',
			Mockery::on(
				static function ( $context ) {
					return is_array( $context )
						&& self::DELIVERY_ID === ( $context['delivery_id'] ?? null )
						&& 'order.completed' === ( $context['event_type'] ?? null )
						&& isset( $context['error'] )
						&& str_contains( (string) $context['error'], 'RuntimeException' )
						&& str_contains( (string) $context['error'], 'DB unavailable' );
				}
			)
		)->once();
		$this->receiver = $this->build_receiver();

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array( 'error' => 'handler_exception' ), $response->get_data() );
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * Run the standard "skip reason → 200 {skipped:reason}" assertion.
	 *
	 * @param string $reason One of the ResolverResult::REASON_* constants.
	 */
	private function run_skip_test( string $reason ): void {
		$this->deliveries->shouldReceive( 'find' )->once()->andReturn( null );
		$this->deliveries->shouldReceive( 'insert_received' )->once()->andReturn( true );
		$this->resolver->shouldReceive( 'resolve' )->once()
			->andReturn( new ResolverResult( $reason ) );
		$this->deliveries->shouldReceive( 'mark_skipped' )->once()
			->with( self::DELIVERY_ID, $reason );

		$response = $this->receiver->handle( $this->signed_request( $this->order_completed_body() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'skipped' => $reason ), $response->get_data() );
	}

	/**
	 * Build a fresh WebhookReceiver wired to current $this collaborators.
	 *
	 * Used by tests that re-mock $this->logger after setUp().
	 */
	private function build_receiver(): WebhookReceiver {
		return new WebhookReceiver(
			new SignatureVerifier( self::SIGNING_SECRET ),
			$this->deliveries,
			$this->order_sync,
			$this->resolver,
			$this->logger
		);
	}

	/**
	 * Build a minimal WC_Order mock sufficient for the race-loss path.
	 *
	 * No order methods are called when insert_received returns false;
	 * this stub merely satisfies the resolver return type contract.
	 */
	private function resolved_order_stub(): \WC_Order {
		return Mockery::mock( \WC_Order::class );
	}

	/**
	 * Build a WP_REST_Request with a correctly HMAC-signed body.
	 */
	private function signed_request( string $body, int $attempt = 1 ): \WP_REST_Request {
		$t   = time();
		$sig = hash_hmac( 'sha256', "{$t}.{$body}", self::SIGNING_SECRET );

		return new \WP_REST_Request(
			$body,
			array(
				WebhookReceiver::HEADER_DELIVERY_ID => self::DELIVERY_ID,
				WebhookReceiver::HEADER_SIGNATURE   => "t={$t},v1={$sig}",
				WebhookReceiver::HEADER_ATTEMPT     => (string) $attempt,
			)
		);
	}

	/**
	 * Minimal valid `order.completed` JSON envelope.
	 *
	 * sessionId is `spart-wc-abcd1234-42` — well-formed, embeds WC order id 42.
	 */
	private function order_completed_body(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test helper; wp_json_encode() unavailable in unit tests.
		return (string) json_encode(
			array(
				'id'            => 'evt-completed-1',
				'type'          => 'order.completed',
				'createdAt'     => '2026-05-01T00:00:00Z',
				'apiVersion'    => '1',
				'merchantAppId' => 'app_1',
				'data'          => array(
					'order' => array(
						'shortId'       => 'ORD-001',
						'originalTotal' => array(
							'currency' => 'USD',
							'amount'   => 100.0,
						),
						'finalTotal'    => array(
							'currency' => 'USD',
							'amount'   => 100.0,
						),
						'lineItems'     => array(),
						'sparter'       => array(
							'fullName' => 'Test User',
							'email'    => 'test@example.com',
						),
						'sessionId'     => 'spart-wc-abcd1234-42',
						'status'        => 'completed',
						'countryCode'   => 'US',
						'createdAt'     => '2026-05-01T00:00:00Z',
					),
				),
			)
		);
	}

	/**
	 * Minimal valid `webhook.test` JSON envelope.
	 */
	private function webhook_test_body(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test helper; wp_json_encode() unavailable in unit tests.
		return (string) json_encode(
			array(
				'id'            => 'evt-test-1',
				'type'          => 'webhook.test',
				'createdAt'     => '2026-05-01T00:00:00Z',
				'apiVersion'    => '1',
				'merchantAppId' => 'app_1',
				'data'          => array(
					'test' => array(
						'merchantAppName' => 'Test App',
						'sentAt'          => '2026-05-01T00:00:00Z',
					),
				),
			)
		);
	}

	/**
	 * Build a DeliveryRow stub in the given state.
	 */
	private function make_delivery_row( string $state ): DeliveryRow {
		return new DeliveryRow(
			id:            1,
			delivery_id:   self::DELIVERY_ID,
			event_type:    'order.completed',
			wc_order_id:   null,
			state:         $state,
			attempt_count: 1,
			received_at:   '2026-05-01 00:00:00',
			applied_at:    null,
			error_message: null
		);
	}
}
