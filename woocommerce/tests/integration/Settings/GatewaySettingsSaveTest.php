<?php
/**
 * Integration tests for the Spart gateway settings save round-trip.
 *
 * Exercises real WC_Payment_Gateway::process_admin_options() against a
 * realistic $_POST shape (flat top-level keys named
 * woocommerce_spart_<field_id>) and asserts that the saved option
 * round-trips every field exactly. Closes the test gap that allowed
 * the v0.5.x "settings not persisting" regression to ship.
 *
 * @package Spart\WooCommerce\Tests\Integration\Settings
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Integration\Settings;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Settings\Schema;
use Spart\WooCommerce\Settings\SecretMask;
use Spart\WooCommerce\Tests\Integration\WC_Spart_IntegrationTestCase;

/**
 * Tests for WC_Gateway_Spart settings persistence via process_admin_options().
 */
final class GatewaySettingsSaveTest extends WC_Spart_IntegrationTestCase {

	private string $option_key;

	/**
	 * Reset $_POST between tests so leakage between cases is impossible.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->option_key = ( new WC_Gateway_Spart() )->get_option_key();
		delete_option( $this->option_key );
		$_POST = array();
	}

	protected function tearDown(): void {
		delete_option( $this->option_key );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * The basic regression repro: edit description + title via the
	 * admin form, click save, expect the option to actually contain
	 * the submitted values on reload.
	 */
	public function test_save_persists_text_fields(): void {
		$_POST['woocommerce_spart_title']       = 'Split your payment';
		$_POST['woocommerce_spart_description'] = 'Pay in parts with Spart!';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertIsArray( $saved );
		$this->assertSame( 'Split your payment', $saved['title'] );
		$this->assertSame( 'Pay in parts with Spart!', $saved['description'] );
	}

	/**
	 * New API key + webhook secret are persisted verbatim.
	 */
	public function test_save_persists_new_api_key_and_webhook_secret(): void {
		$_POST['woocommerce_spart_api_key']        = 'sk_live_freshkey0123456789';
		$_POST['woocommerce_spart_webhook_secret'] = 'whsec_freshsecret9876543210';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertIsArray( $saved );
		$this->assertSame( 'sk_live_freshkey0123456789', $saved['api_key'] );
		$this->assertSame( 'whsec_freshsecret9876543210', $saved['webhook_secret'] );
	}

	/**
	 * Merchant did not edit the API key field. The masked sentinel is
	 * POSTed back unchanged. The stored key must NOT be clobbered.
	 */
	public function test_save_keeps_existing_api_key_when_mask_unchanged(): void {
		update_option(
			$this->option_key,
			array(
				'enabled'        => 'yes',
				'title'          => 'Pay with Spart',
				'description'    => 'Installments via Spart',
				'api_key'        => 'sk_live_preexisting1234abcd',
				'webhook_secret' => 'whsec_preexisting',
				'environment'    => 'live',
				'debug_logging'  => 'no',
			)
		);

		$_POST['woocommerce_spart_api_key']        = SecretMask::mask( 'sk_live_preexisting1234abcd' );
		$_POST['woocommerce_spart_webhook_secret'] = SecretMask::mask( 'whsec_preexisting' );
		$_POST['woocommerce_spart_title']          = 'Pay with Spart';
		$_POST['woocommerce_spart_description']    = 'Updated description';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertSame( 'sk_live_preexisting1234abcd', $saved['api_key'] );
		$this->assertSame( 'whsec_preexisting', $saved['webhook_secret'] );
		$this->assertSame( 'Updated description', $saved['description'] );
	}

	/**
	 * Merchant POSTs an explicit empty string for the API key — they
	 * want the stored key removed.
	 */
	public function test_save_clears_api_key_when_explicitly_blank(): void {
		update_option(
			$this->option_key,
			array(
				'api_key' => 'sk_live_preexisting1234abcd',
			)
		);

		$_POST['woocommerce_spart_api_key'] = '';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertSame( '', $saved['api_key'] );
	}

	/**
	 * Browser does NOT submit unchecked checkboxes. The saved option
	 * must contain 'no' for the corresponding field, not the previous
	 * 'yes'.
	 */
	public function test_save_normalises_unchecked_checkbox_to_no(): void {
		update_option(
			$this->option_key,
			array(
				'enabled' => 'yes',
			)
		);

		$_POST['woocommerce_spart_title'] = 'Pay with Spart';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertSame( 'no', $saved['enabled'] );
	}

	/**
	 * The webhook_url is server-derived. Any tampered value in $_POST
	 * must be discarded (the inject_webhook_url filter rewrites it).
	 */
	public function test_save_always_sets_canonical_webhook_url(): void {
		$_POST['woocommerce_spart_webhook_url'] = 'https://attacker.example/webhook';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertSame( rest_url( 'spart/v1/webhook' ), $saved['webhook_url'] );
	}

	/**
	 * The "merchant repro": fully populated POST -> save -> reload ->
	 * every field reflects what the merchant submitted. This is the
	 * test that would have caught the original regression.
	 */
	public function test_save_then_reload_full_round_trip(): void {
		$_POST['woocommerce_spart_enabled']                   = '1';
		$_POST['woocommerce_spart_title']                     = 'Split with Spart';
		$_POST['woocommerce_spart_description']               = 'Pay in installments.';
		$_POST['woocommerce_spart_api_key']                   = 'sk_live_NEWAPIKEY12345678';
		$_POST['woocommerce_spart_webhook_secret']            = 'whsec_NEWSECRET12345678';
		$_POST['woocommerce_spart_messaging_enabled_product'] = '1';
		$_POST['woocommerce_spart_messaging_enabled_cart']    = '1';
		$_POST['woocommerce_spart_debug_logging']             = '1';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$reloaded = new WC_Gateway_Spart();

		$this->assertSame( 'yes', $reloaded->get_option( 'enabled' ) );
		$this->assertSame( 'Split with Spart', $reloaded->get_option( 'title' ) );
		$this->assertSame( 'Pay in installments.', $reloaded->get_option( 'description' ) );
		$this->assertSame( 'sk_live_NEWAPIKEY12345678', $reloaded->get_option( 'api_key' ) );
		$this->assertSame( 'whsec_NEWSECRET12345678', $reloaded->get_option( 'webhook_secret' ) );
		$this->assertSame( 'yes', $reloaded->get_option( 'messaging_enabled_product' ) );
		$this->assertSame( 'yes', $reloaded->get_option( 'messaging_enabled_cart' ) );
		$this->assertSame( 'yes', $reloaded->get_option( 'debug_logging' ) );
		$this->assertSame( rest_url( 'spart/v1/webhook' ), $reloaded->get_option( 'webhook_url' ) );
	}

	/**
	 * Environment is a disabled field (Schema::SANDBOX_AVAILABLE === false). A
	 * crafted POST that submits 'sandbox' must be clamped to 'live' by the
	 * enforce_schema_invariants filter.
	 */
	public function test_save_clamps_tampered_environment_to_live(): void {
		$_POST['woocommerce_spart_environment'] = 'sandbox';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertIsArray( $saved );
		$this->assertSame( 'live', $saved['environment'] );
	}

	/**
	 * A bullet-contaminated API key POST (partially-edited mask) must not
	 * clobber the stored key.
	 */
	public function test_save_keeps_existing_api_key_when_bullet_glyph_submitted(): void {
		update_option(
			$this->option_key,
			array(
				'api_key' => 'sk_live_bullettest12345678',
			)
		);

		$_POST['woocommerce_spart_api_key'] = SecretMask::BULLET . 'edited_suffix';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertSame( 'sk_live_bullettest12345678', $saved['api_key'] );
	}

	/**
	 * The same bullet-glyph guard applies to the webhook_secret field.
	 */
	public function test_save_keeps_existing_webhook_secret_when_bullet_glyph_submitted(): void {
		update_option(
			$this->option_key,
			array(
				'webhook_secret' => 'whsec_bullettest12345678',
			)
		);

		$_POST['woocommerce_spart_webhook_secret'] = SecretMask::BULLET . 'tampered';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertSame( 'whsec_bullettest12345678', $saved['webhook_secret'] );
	}

	/**
	 * Surrounding whitespace in text fields (copy-paste artefacts) is stripped
	 * before persistence by Field::sanitize() via enforce_schema_invariants().
	 */
	public function test_save_trims_whitespace_from_title(): void {
		$_POST['woocommerce_spart_title'] = '  Pay with Spart  ';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertSame( 'Pay with Spart', $saved['title'] );
	}

	/**
	 * A new API key submitted with leading/trailing whitespace (copy-paste
	 * from the dashboard) is trimmed before persistence.
	 */
	public function test_save_new_api_key_is_trimmed(): void {
		$_POST['woocommerce_spart_api_key'] = '  sk_live_trimmedkey123  ';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertSame( 'sk_live_trimmedkey123', $saved['api_key'] );
	}

	/**
	 * Every field declared in Schema::as_wc_settings_array() (excluding
	 * server-derived and disabled fields) survives a full save round-trip.
	 * This is the structural guard: adding a new field to the schema without
	 * a corresponding save test will be caught here.
	 */
	public function test_every_schema_field_persists_through_save(): void {
		foreach ( Schema::as_wc_settings_array() as $field ) {
			if ( ! isset( $field['id'] ) ) {
				continue;
			}
			$type = $field['type'] ?? 'text';
			if ( in_array( $type, array( 'title', 'sectionend' ), true ) ) {
				continue;
			}
			$id  = $field['id'];
			$key = 'woocommerce_spart_' . $id;
			if ( 'webhook_url' === $id ) {
				continue;
			}
			$_POST[ $key ] = ( 'number' === $type ) ? '42' : ( 'test_value_' . $id );
		}

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertIsArray( $saved );

		foreach ( Schema::as_wc_settings_array() as $field ) {
			if ( ! isset( $field['id'] ) ) {
				continue;
			}
			$type = $field['type'] ?? 'text';
			if ( in_array( $type, array( 'title', 'sectionend' ), true ) ) {
				continue;
			}
			$id = $field['id'];
			if ( in_array( $id, array( 'webhook_url', 'environment' ), true ) ) {
				continue;
			}
			$this->assertArrayHasKey( $id, $saved, "Field '{$id}' was not persisted." );
		}
	}

	public function test_save_persists_default_order_duration_minutes(): void {
		$_POST['woocommerce_spart_default_order_duration_minutes'] = '60';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertIsArray( $saved );
		$this->assertSame( 60, $saved['default_order_duration_minutes'] );
	}

	public function test_save_clamps_below_five_minute_default_order_duration_to_default(): void {
		$_POST['woocommerce_spart_default_order_duration_minutes'] = '2';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertIsArray( $saved );
		$this->assertSame( 10080, $saved['default_order_duration_minutes'] );
	}

	/**
	 * Schema::DEBUG_API_ENDPOINT_FIELD is a title/display-only field.
	 * It must never appear as a key in the saved option array.
	 */
	public function test_save_never_persists_debug_title_field(): void {
		$_POST[ 'woocommerce_spart_' . Schema::DEBUG_API_ENDPOINT_FIELD ] = 'https://attacker.example/api';

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertIsArray( $saved );
		$this->assertArrayNotHasKey( Schema::DEBUG_API_ENDPOINT_FIELD, $saved );
	}

	/**
	 * Submitting an empty POST (no woocommerce_spart_* keys at all) must
	 * not clobber pre-existing password fields stored in the option.
	 */
	public function test_save_with_empty_post_preserves_password_fields(): void {
		update_option(
			$this->option_key,
			array(
				'api_key'        => 'sk_live_preserved1234abcd',
				'webhook_secret' => 'whsec_preserved5678efgh',
			)
		);

		$gateway = new WC_Gateway_Spart();
		$gateway->process_admin_options();

		$saved = get_option( $this->option_key );
		$this->assertIsArray( $saved );
		$this->assertSame( 'sk_live_preserved1234abcd', $saved['api_key'] );
		$this->assertSame( 'whsec_preserved5678efgh', $saved['webhook_secret'] );
	}
}
