<?php
// tests/unit/Settings/SchemaTest.php
declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Settings\Field;
use Spart\WooCommerce\Settings\Schema;

final class SchemaTest extends TestCase {

	protected function setUp(): void {
		Schema::reset_for_tests();
	}

	protected function tearDown(): void {
		Schema::reset_for_tests();
		parent::tearDown();
	}

	public function test_field_count_is_fourteen(): void {
		$fields = Schema::fields();

		$this->assertCount( 14, $fields );
	}

	public function test_field_ids_match_expected_set(): void {
		$ids = array_map( static fn ( Field $f ) => $f->id(), Schema::fields() );

		$this->assertSame(
			array(
				'enabled',
				'title',
				'description',
				'api_key',
				'webhook_secret',
				'webhook_url',
				'default_order_window_days',
				'default_order_window_hours',
				'default_order_window_minutes',
				'messaging_enabled_product',
				'messaging_enabled_cart',
				'environment',
				'debug_logging',
				'debug_api_endpoint',
			),
			$ids
		);
	}

	public function test_environment_field_is_disabled_when_sandbox_unavailable(): void {
		$this->assertFalse( Schema::SANDBOX_AVAILABLE );
		$env = Schema::field( 'environment' );
		$this->assertTrue( $env->is_disabled() );
	}

	public function test_as_wc_settings_array_keys_by_field_id(): void {
		$array = Schema::as_wc_settings_array();
		$this->assertArrayHasKey( 'api_key', $array );
		$this->assertSame( 'password', $array['api_key']['type'] );
		$this->assertSame( 'text', $array['title']['type'] );
		$this->assertSame( 'checkbox', $array['enabled']['type'] );
	}

	public function test_sanitize_clamps_sandbox_post_tampering_to_live(): void {
		$sanitised = Schema::sanitize(
			array(
				'enabled'        => '1',
				'title'          => '  Pay with Spart ',
				'description'    => 'Pay in installments.',
				'api_key'        => '  sk_live_xyz  ',
				'webhook_secret' => 'whsec_abc',
				'environment'    => 'sandbox', // attacker-supplied
				'debug_logging'  => '1',
			)
		);

		$this->assertSame( 'live', $sanitised['environment'] ); // clamped
		$this->assertSame( 'yes', $sanitised['enabled'] );
		$this->assertSame( 'Pay with Spart', $sanitised['title'] );
		$this->assertSame( 'sk_live_xyz', $sanitised['api_key'] );
		$this->assertSame( 'yes', $sanitised['debug_logging'] );
	}

	public function test_sanitize_drops_unknown_keys(): void {
		$sanitised = Schema::sanitize(
			array(
				'enabled' => '1',
				'evil'    => '<script>',
			)
		);
		$this->assertArrayNotHasKey( 'evil', $sanitised );
	}

	public function test_sanitize_preserves_webhook_url_passthrough(): void {
		// webhook_url is rendered server-side; we never accept it from POST.
		$sanitised = Schema::sanitize( array( 'webhook_url' => 'https://attacker.example/' ) );
		$this->assertArrayNotHasKey( 'webhook_url', $sanitised );
	}

	public function test_field_lookup_throws_for_unknown_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		Schema::field( 'does_not_exist' );
	}

	public function test_description_default_uses_friends_copy(): void {
		$description = Schema::field( 'description' );
		$this->assertSame(
			'Split the payment with your friends!',
			$description->default()
		);
	}

	public function test_messaging_toggles_default_to_no(): void {
		$defaults = Schema::defaults();

		$this->assertSame( 'no', $defaults['messaging_enabled_product'] );
		$this->assertSame( 'no', $defaults['messaging_enabled_cart'] );
	}

	public function test_debug_endpoint_field_is_present_and_is_title_type(): void {
		$ids = array_map( static fn ( Field $f ) => $f->id(), Schema::fields() );

		$this->assertContains( Schema::DEBUG_API_ENDPOINT_FIELD, $ids );
		$this->assertSame( 'title', Schema::field( Schema::DEBUG_API_ENDPOINT_FIELD )->to_wc_array()['type'] );
	}

	public function test_debug_api_endpoint_field_appears_last(): void {
		$ids = array_map( static fn ( Field $f ) => $f->id(), Schema::fields() );

		$this->assertSame(
			Schema::DEBUG_API_ENDPOINT_FIELD,
			end( $ids ),
			'debug_api_endpoint must be the LAST field so its WC <h3> title does not visually swallow subsequent fields.'
		);
	}

	public function test_debug_field_constant_matches_declared_field_id(): void {
		$found = false;
		foreach ( Schema::fields() as $field ) {
			if ( $field->id() === Schema::DEBUG_API_ENDPOINT_FIELD ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Schema::DEBUG_API_ENDPOINT_FIELD constant must match a real field id.' );
	}

	public function test_reset_for_tests_clears_fields_memo(): void {
		// Build once to populate the memo.
		Schema::fields();

		// Reset, then call fields() again — it must return a fresh array
		// rebuilt from scratch rather than the memoised reference.
		Schema::reset_for_tests();
		$rebuilt = Schema::fields();
		$this->assertCount( 14, $rebuilt );
		$this->assertContains( Schema::DEBUG_API_ENDPOINT_FIELD, array_map( static fn ( Field $f ) => $f->id(), $rebuilt ) );
	}

	public function test_sanitize_drops_debug_endpoint_post_data(): void {
		// Title-type fields (display-only headings) must never appear as
		// keys in the sanitised array. WC's init_settings() pre-fills
		// every declared form_field — including titles — with the empty
		// default whenever the option row is missing, so a lenient
		// filter would land `debug_api_endpoint => ''` in the saved
		// option and pollute the schema with display artefacts.
		$sanitised = Schema::sanitize(
			array(
				'enabled'                        => '1',
				Schema::DEBUG_API_ENDPOINT_FIELD => 'https://attacker.example/',
			)
		);

		$this->assertArrayNotHasKey( Schema::DEBUG_API_ENDPOINT_FIELD, $sanitised );
		$this->assertSame( 'yes', $sanitised['enabled'] );
	}

	public function test_window_day_field_default_is_seven_others_zero(): void {
		$this->assertSame( 7, Schema::field( 'default_order_window_days' )->default() );
		$this->assertSame( 0, Schema::field( 'default_order_window_hours' )->default() );
		$this->assertSame( 0, Schema::field( 'default_order_window_minutes' )->default() );
	}

	public function test_window_fields_are_number_type_with_zero_min(): void {
		foreach ( array( 'default_order_window_days', 'default_order_window_hours', 'default_order_window_minutes' ) as $id ) {
			$array = Schema::field( $id )->to_wc_array();
			$this->assertSame( 'number', $array['type'], $id );
			$this->assertSame( 0, $array['custom_attributes']['min'], $id );
		}
	}

	public function test_sanitize_coerces_window_components_to_ints(): void {
		$sanitised = Schema::sanitize(
			array(
				'default_order_window_days'    => '2',
				'default_order_window_hours'   => '3',
				'default_order_window_minutes' => '4',
			)
		);
		$this->assertSame( 2, $sanitised['default_order_window_days'] );
		$this->assertSame( 3, $sanitised['default_order_window_hours'] );
		$this->assertSame( 4, $sanitised['default_order_window_minutes'] );
	}

	public function test_max_order_duration_minutes_is_seven_days(): void {
		$this->assertSame( 10080, Schema::MAX_ORDER_DURATION_MINUTES );
	}

	public function test_total_minutes_sums_days_hours_minutes(): void {
		$total = Schema::total_minutes(
			array(
				'default_order_window_days'    => 1,
				'default_order_window_hours'   => 2,
				'default_order_window_minutes' => 3,
			)
		);
		$this->assertSame( ( 1 * 1440 ) + ( 2 * 60 ) + 3, $total );
	}

	public function test_total_minutes_treats_missing_and_negative_as_zero(): void {
		$total = Schema::total_minutes(
			array(
				'default_order_window_hours'   => '-5',
				'default_order_window_minutes' => 'x',
			)
		);
		$this->assertSame( 0, $total );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function round_trip_minutes(): array {
		return array(
			'five minutes' => array( 5 ),
			'three hours'  => array( 180 ),
			'one day'      => array( 1440 ),
			'seven days'   => array( 10080 ),
		);
	}

	/**
	 * @dataProvider round_trip_minutes
	 *
	 * @param int $minutes Total minutes to round-trip.
	 */
	public function test_decompose_round_trips_with_total_minutes( int $minutes ): void {
		$parts       = Schema::decompose_minutes( $minutes );
		$reassembled = Schema::total_minutes(
			array(
				'default_order_window_days'    => $parts['days'],
				'default_order_window_hours'   => $parts['hours'],
				'default_order_window_minutes' => $parts['minutes'],
			)
		);
		$this->assertSame( $minutes, $reassembled );
	}

	public function test_clamp_minutes_enforces_both_bounds(): void {
		$this->assertSame( 5, Schema::clamp_minutes( 0 ) );
		$this->assertSame( 10080, Schema::clamp_minutes( 999999 ) );
		$this->assertSame( 180, Schema::clamp_minutes( 180 ) );
	}
}
