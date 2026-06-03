<?php

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Admin\Timestamp;

final class TimestampTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_format_returns_dash_for_null(): void {
		$this->assertSame( '—', Timestamp::format( null ) );
	}

	public function test_format_returns_dash_for_empty_string(): void {
		$this->assertSame( '—', Timestamp::format( '' ) );
	}

	public function test_format_returns_dash_for_unparseable_input(): void {
		$this->assertSame( '—', Timestamp::format( 'not-a-date' ) );
	}

	public function test_format_delegates_to_wp_date_with_utc_parsed_timestamp(): void {
		$expected_ts = strtotime( '2026-05-27 09:00:00 UTC' );
		Functions\expect( 'wp_date' )
			->once()
			->with( 'Y-m-d H:i:s', $expected_ts )
			->andReturn( '2026-05-27 11:00:00' );

		$this->assertSame( '2026-05-27 11:00:00', Timestamp::format( '2026-05-27 09:00:00' ) );
	}

	public function test_format_parses_sql_input_as_utc_not_server_local(): void {
		// The repository stores timestamps as UTC. If the helper parsed them
		// as server-local time, sites running in non-UTC timezones would see
		// the displayed time double-shifted. Lock the contract: strtotime is
		// called with the trailing ' UTC' suffix.
		$expected_ts = strtotime( '2026-01-15 14:30:00 UTC' );
		Functions\expect( 'wp_date' )
			->once()
			->with( 'Y-m-d H:i:s', $expected_ts )
			->andReturn( 'formatted' );

		$this->assertSame( 'formatted', Timestamp::format( '2026-01-15 14:30:00' ) );
	}
}
