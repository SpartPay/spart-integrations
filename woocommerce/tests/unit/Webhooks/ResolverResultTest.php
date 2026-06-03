<?php
/**
 * Unit tests for Webhooks\ResolverResult.
 *
 * @package Spart\WooCommerce\Tests\Unit\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Webhooks\ResolverResult;

/**
 * @covers \Spart\WooCommerce\Webhooks\ResolverResult
 */
final class ResolverResultTest extends TestCase {

	public function test_stores_reason_string_verbatim(): void {
		$r = new ResolverResult( ResolverResult::REASON_NO_SESSION_ID );
		$this->assertSame( 'no_session_id', $r->reason );
	}

	public function test_reason_constants_are_unique(): void {
		$reasons = array(
			ResolverResult::REASON_UNKNOWN_EVENT,
			ResolverResult::REASON_NO_SESSION_ID,
			ResolverResult::REASON_SIBLING_SITE,
			ResolverResult::REASON_MALFORMED_SESSION,
			ResolverResult::REASON_ORDER_NOT_FOUND,
			ResolverResult::REASON_ORDER_TRASHED,
			ResolverResult::REASON_TEST_EVENT,
		);
		$this->assertSame( count( $reasons ), count( array_unique( $reasons ) ) );
	}

	/**
	 * @dataProvider reason_provider
	 */
	public function test_each_reason_constant_resolves_to_its_string( string $constant, string $expected ): void {
		$r = new ResolverResult( $constant );
		$this->assertSame( $expected, $r->reason );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function reason_provider(): array {
		return array(
			'unknown_event_type' => array( ResolverResult::REASON_UNKNOWN_EVENT, 'unknown_event_type' ),
			'no_session_id'      => array( ResolverResult::REASON_NO_SESSION_ID, 'no_session_id' ),
			'sibling_site'       => array( ResolverResult::REASON_SIBLING_SITE, 'sibling_site' ),
			'malformed_session'  => array( ResolverResult::REASON_MALFORMED_SESSION, 'malformed_session' ),
			'order_not_found'    => array( ResolverResult::REASON_ORDER_NOT_FOUND, 'order_not_found' ),
			'order_trashed'      => array( ResolverResult::REASON_ORDER_TRASHED, 'order_trashed' ),
			'webhook_test'       => array( ResolverResult::REASON_TEST_EVENT, 'webhook_test' ),
		);
	}
}
