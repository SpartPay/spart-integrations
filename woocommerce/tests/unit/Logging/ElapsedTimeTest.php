<?php

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Logging\ElapsedTime;
use Spart\WooCommerce\Tests\Unit\Fixtures\RecordingSpartLogger;

final class ElapsedTimeTest extends TestCase {

	public function test_elapsed_time_is_a_non_negative_millisecond_float(): void {
		$started_at = ElapsedTime::start();
		$elapsed    = ElapsedTime::milliseconds_since( $started_at );

		$this->assertIsFloat( $elapsed );
		$this->assertGreaterThanOrEqual( 0.0, $elapsed );
		$this->assertSame( round( $elapsed, 3 ), $elapsed );
	}

	public function test_recording_spart_logger_returns_calls_for_a_specific_event(): void {
		$logger = new RecordingSpartLogger();

		$logger->info( 'first', array( 'event' => LogEvents::CHECKOUT_STARTED ) );
		$logger->warning( 'second', array( 'event' => LogEvents::API_REQUEST_COMPLETED ) );
		$logger->error( 'third', array( 'event' => LogEvents::API_REQUEST_COMPLETED ) );

		$calls = $logger->calls_for_event( LogEvents::API_REQUEST_COMPLETED );

		$this->assertCount( 2, $calls );
		$this->assertSame( 'warning', $calls[0]['level'] );
		$this->assertSame( 'error', $calls[1]['level'] );
	}

	public function test_log_events_expose_the_new_telemetry_constants(): void {
		$this->assertSame( 'spart_api_request_completed', LogEvents::API_REQUEST_COMPLETED );
		$this->assertSame( 'spart_checkout_profile', LogEvents::CHECKOUT_PROFILE );
	}
}
