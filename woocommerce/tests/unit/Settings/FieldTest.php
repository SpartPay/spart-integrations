<?php
/**
 * Unit tests for Settings\Field value object.
 *
 * @package Spart\WooCommerce\Tests\Unit\Settings
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Settings\Field;

/**
 * @covers \Spart\WooCommerce\Settings\Field
 */
final class FieldTest extends TestCase {

	public function test_text_field_round_trips_to_wc_array(): void {
		$field = Field::text(
			'api_key',
			'API Key',
			'',
			array(
				'description' => 'Your Spart merchant API key.',
				'desc_tip'    => true,
			)
		);

		$array = $field->to_wc_array();

		$this->assertSame( 'text', $array['type'] );
		$this->assertSame( 'API Key', $array['title'] );
		$this->assertSame( '', $array['default'] );
		$this->assertSame( 'Your Spart merchant API key.', $array['description'] );
		$this->assertTrue( $array['desc_tip'] );
	}

	public function test_password_field_uses_password_type(): void {
		$field = Field::password( 'webhook_secret', 'Webhook Secret', '' );
		$this->assertSame( 'password', $field->to_wc_array()['type'] );
	}

	public function test_checkbox_field_defaults_to_no(): void {
		$field = Field::checkbox( 'debug_logging', 'Debug logging' );
		$array = $field->to_wc_array();
		$this->assertSame( 'checkbox', $array['type'] );
		$this->assertSame( 'no', $array['default'] );
	}

	public function test_select_field_carries_options(): void {
		$field = Field::select(
			'environment',
			'Environment',
			'live',
			array(
				'live'    => 'Live',
				'sandbox' => 'Sandbox',
			)
		);
		$array = $field->to_wc_array();
		$this->assertSame( 'select', $array['type'] );
		$this->assertSame(
			array(
				'live'    => 'Live',
				'sandbox' => 'Sandbox',
			),
			$array['options']
		);
		$this->assertSame( 'live', $array['default'] );
	}

	public function test_disabled_select_carries_custom_attributes(): void {
		$field = Field::select(
			'environment',
			'Environment',
			'live',
			array( 'live' => 'Live' )
		)->disabled( 'Sandbox locked.' );

		$array = $field->to_wc_array();
		$this->assertTrue( $field->is_disabled() );
		$this->assertSame( 'Sandbox locked.', $field->disabled_reason() );
		$this->assertSame( 'disabled', $array['custom_attributes']['disabled'] ?? null );
	}

	public function test_disabled_reason_overrides_extras_description(): void {
		// Regression: previously array_merge($base, $extras) let the extras'
		// description silently mask the disabled reason, so the merchant
		// would see "Selects the Spart API environment." instead of
		// "Sandbox locked." next to a greyed-out control.
		$field = Field::select(
			'environment',
			'Environment',
			'live',
			array( 'live' => 'Live' ),
			array(
				'description' => 'Selects the Spart API environment.',
				'desc_tip'    => true,
			)
		)->disabled( 'Sandbox locked.' );

		$array = $field->to_wc_array();
		$this->assertSame( 'Sandbox locked.', $array['description'] );
		// Unrelated extras must still pass through.
		$this->assertTrue( $array['desc_tip'] );
		$this->assertSame( 'disabled', $array['custom_attributes']['disabled'] ?? null );
	}

	public function test_disabled_attribute_merges_with_extras_custom_attributes(): void {
		// Regression: extras' custom_attributes must not wipe out the
		// `disabled` HTML attribute. Both must coexist.
		$field = Field::text(
			'webhook_url',
			'Webhook URL',
			'',
			array(
				'custom_attributes' => array( 'readonly' => 'readonly' ),
			)
		)->disabled( 'Server-derived value.' );

		$array = $field->to_wc_array();
		$this->assertSame( 'disabled', $array['custom_attributes']['disabled'] ?? null );
		$this->assertSame( 'readonly', $array['custom_attributes']['readonly'] ?? null );
	}

	public function test_field_id_is_immutable(): void {
		$field = Field::text( 'api_key', 'API Key', '' );
		$this->assertSame( 'api_key', $field->id() );
	}

	public function test_sanitize_text_trims(): void {
		$field = Field::text( 'api_key', 'API Key', '' );
		$this->assertSame( 'abc', $field->sanitize( '  abc  ' ) );
	}

	public function test_sanitize_checkbox_normalises_yes_no(): void {
		$field = Field::checkbox( 'debug_logging', 'Debug logging' );
		$this->assertSame( 'yes', $field->sanitize( '1' ) );
		$this->assertSame( 'yes', $field->sanitize( 'yes' ) );
		$this->assertSame( 'no', $field->sanitize( '' ) );
		$this->assertSame( 'no', $field->sanitize( '0' ) );
	}

	public function test_sanitize_select_clamps_to_known_values(): void {
		$field = Field::select(
			'environment',
			'Environment',
			'live',
			array(
				'live'    => 'Live',
				'sandbox' => 'Sandbox',
			)
		);
		$this->assertSame( 'live', $field->sanitize( 'live' ) );
		$this->assertSame( 'sandbox', $field->sanitize( 'sandbox' ) );
		$this->assertSame( 'live', $field->sanitize( 'production' ) );
	}

	public function test_sanitize_disabled_select_clamps_to_default_regardless_of_input(): void {
		$field = Field::select(
			'environment',
			'Environment',
			'live',
			array(
				'live'    => 'Live',
				'sandbox' => 'Sandbox',
			)
		)->disabled( 'Sandbox locked.' );

		$this->assertSame( 'live', $field->sanitize( 'sandbox' ) );
	}

	public function test_title_field_has_title_type_and_no_options(): void {
		$field = Field::title(
			'debug_api_endpoint',
			'API endpoint'
		);

		$array = $field->to_wc_array();
		$this->assertSame( 'title', $array['type'] );
		$this->assertSame( 'API endpoint', $array['title'] );
		$this->assertSame( '', $array['default'] );
		$this->assertArrayNotHasKey( 'options', $array );
	}

	public function test_title_field_carries_extras_description(): void {
		$field = Field::title(
			'debug_api_endpoint',
			'API endpoint',
			array( 'description' => '<code>https://api.spartpay.com</code>' )
		);

		$array = $field->to_wc_array();
		$this->assertSame( '<code>https://api.spartpay.com</code>', $array['description'] );
	}

	public function test_title_field_sanitize_returns_default(): void {
		$field = Field::title( 'debug_api_endpoint', 'API endpoint' );

		// 'title' field never POSTs a value, but Schema::sanitize() may still
		// call sanitize() on its Field — must return the empty default.
		$this->assertSame( '', $field->sanitize( 'anything' ) );
		$this->assertSame( '', $field->sanitize( '' ) );
	}

	public function test_title_field_id_is_immutable(): void {
		$field = Field::title( 'debug_api_endpoint', 'API endpoint' );
		$this->assertSame( 'debug_api_endpoint', $field->id() );
	}

	public function test_number_field_renders_with_min_and_step_attributes(): void {
		$field = Field::number( 'default_order_duration_minutes', 'Default checkout window (minutes)', 10080, 5 );
		$array = $field->to_wc_array();

		$this->assertSame( 'number', $array['type'] );
		$this->assertSame( 10080, $array['default'] );
		$this->assertSame( 5, $array['custom_attributes']['min'] );
		$this->assertSame( 1, $array['custom_attributes']['step'] );
	}

	public function test_number_field_extras_custom_attributes_win_over_factory_defaults(): void {
		// Caller-supplied custom_attributes should be able to override step
		// (e.g. step=5) without losing the min defaulted in.
		$field = Field::number(
			'cooldown_minutes',
			'Cooldown',
			30,
			5,
			array(
				'custom_attributes' => array( 'step' => 5 ),
			)
		);
		$array = $field->to_wc_array();

		$this->assertSame( 5, $array['custom_attributes']['min'] );
		$this->assertSame( 5, $array['custom_attributes']['step'] );
	}

	public function test_sanitize_number_casts_numeric_to_int(): void {
		$field = Field::number( 'cooldown_minutes', 'Cooldown', 30, 5 );
		$this->assertSame( 60, $field->sanitize( '60' ) );
		$this->assertSame( 60, $field->sanitize( 60 ) );
	}

	public function test_sanitize_number_returns_default_when_non_numeric(): void {
		$field = Field::number( 'cooldown_minutes', 'Cooldown', 30, 5 );
		$this->assertSame( 30, $field->sanitize( 'not-a-number' ) );
		$this->assertSame( 30, $field->sanitize( '' ) );
	}

	public function test_sanitize_number_clamps_below_min_to_default(): void {
		$field = Field::number( 'cooldown_minutes', 'Cooldown', 30, 5 );
		$this->assertSame( 30, $field->sanitize( '2' ) );
		$this->assertSame( 30, $field->sanitize( 2 ) );
		$this->assertSame( 30, $field->sanitize( '0' ) );
		$this->assertSame( 30, $field->sanitize( '-100' ) );
	}

	public function test_sanitize_number_accepts_value_equal_to_min(): void {
		$field = Field::number( 'cooldown_minutes', 'Cooldown', 30, 5 );
		$this->assertSame( 5, $field->sanitize( '5' ) );
		$this->assertSame( 5, $field->sanitize( 5 ) );
	}

	public function test_sanitize_number_passes_through_value_above_min(): void {
		$field = Field::number( 'cooldown_minutes', 'Cooldown', 30, 5 );
		$this->assertSame( 60, $field->sanitize( '60' ) );
	}
}
