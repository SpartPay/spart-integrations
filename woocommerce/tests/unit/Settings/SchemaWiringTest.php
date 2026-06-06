<?php
// tests/unit/Settings/SchemaWiringTest.php
declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Settings\Schema;

final class SchemaWiringTest extends TestCase {

	protected function setUp(): void {
		\Brain\Monkey\setUp();
		Schema::reset_for_tests();
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );
		\Brain\Monkey\Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
	}

	public function test_init_form_fields_registers_all_schema_fields(): void {
		\Brain\Monkey\Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'http://localhost' . (string) $path );
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'http://localhost/wp-json/' . ltrim( (string) $path, '/' )
		);
		\Brain\Monkey\Functions\when( 'add_action' )->justReturn( null );
		$gateway = new WC_Gateway_Spart();
		$this->assertCount( 14, $gateway->form_fields );
		$this->assertArrayHasKey( 'api_key', $gateway->form_fields );
		$this->assertArrayHasKey( 'debug_api_endpoint', $gateway->form_fields );
		$this->assertSame( 'password', $gateway->form_fields['api_key']['type'] );
	}
}
