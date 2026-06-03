<?php
/**
 * Unit tests for WC_Gateway_Spart.
 *
 * @package Spart\WooCommerce\Tests\Unit\Gateway
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Tests\Unit\Gateway;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Settings\Schema;

/**
 * Tests for WC_Gateway_Spart.
 */
final class WC_Gateway_SpartTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Schema::reset_for_tests();
		// Stub WP functions called by the constructor + per-field validators.
		Monkey\Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'http://localhost' . (string) $path );
		// Default stub: pretty-permalink form. Individual tests may override
		// to assert plain-permalink behavior (?rest_route=/...).
		Monkey\Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'http://localhost/wp-json/' . ltrim( (string) $path, '/' )
		);
		Monkey\Functions\when( 'add_action' )->justReturn( null );
		Monkey\Functions\when( 'add_filter' )->justReturn( null );
		Monkey\Functions\when( 'get_option' )->justReturn( array() );
		Monkey\Functions\when( 'esc_html' )->returnArg();
		Monkey\Functions\when( 'wp_unslash' )->alias( static fn ( $v ) => $v );
		Monkey\Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		// __() is polyfilled as an identity function in tests/unit/bootstrap.php
		// and cannot be redefined here (Patchwork: "DefinedTooEarly").
	}

	protected function tearDown(): void {
		Schema::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The gateway id must be 'spart' so WooCommerce recognises the integration.
	 */
	public function test_gateway_id_is_spart(): void {
		$gateway = new WC_Gateway_Spart();
		$this->assertSame( 'spart', $gateway->id );
	}

	/**
	 * A non-empty method_title is required for the WC payments list.
	 */
	public function test_method_title_is_set(): void {
		$gateway = new WC_Gateway_Spart();
		$this->assertNotSame( '', $gateway->method_title );
	}

	/**
	 * The gateway must support the standard 'products' feature.
	 */
	public function test_default_supports_includes_products(): void {
		$gateway = new WC_Gateway_Spart();
		$this->assertContains( 'products', $gateway->supports );
	}

	/**
	 * The gateway must extend WC_Payment_Gateway for WooCommerce discovery.
	 */
	public function test_extends_wc_payment_gateway(): void {
		$this->assertInstanceOf( \WC_Payment_Gateway::class, new WC_Gateway_Spart() );
	}

	/**
	 * Regression test for PR3: the webhook URL must point at the REST API
	 * endpoint. With pretty permalinks (the WordPress default on most
	 * production sites) it resolves to /wp-json/spart/v1/webhook.
	 */
	public function test_webhook_url_default_uses_rest_api_path_with_pretty_permalinks(): void {
		$gateway = new WC_Gateway_Spart();
		$gateway->init_form_fields();

		$this->assertSame(
			'http://localhost/wp-json/spart/v1/webhook',
			$gateway->form_fields['webhook_url']['default']
		);
	}

	/**
	 * On sites with Plain permalinks (no permalink_structure set), WP's
	 * rest_url() returns the ?rest_route= query-string form so the REST
	 * route resolves without mod_rewrite. The gateway must surface that
	 * exact URL — not the /wp-json/ form which would 404 on such hosts
	 * (e.g. shared hosting without rewrite, wp-env's default config).
	 */
	public function test_webhook_url_default_uses_query_param_form_with_plain_permalinks(): void {
		Monkey\Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'http://localhost/?rest_route=/' . ltrim( (string) $path, '/' )
		);

		$gateway = new WC_Gateway_Spart();
		$gateway->init_form_fields();

		$this->assertSame(
			'http://localhost/?rest_route=/spart/v1/webhook',
			$gateway->form_fields['webhook_url']['default']
		);
	}

	public function test_init_form_fields_renders_live_default_when_no_override(): void {
		// No saved option → environment falls back to 'live'.
		// No WP_SPART_BASE_URL constant → factory returns the live URL.

		$gateway = new WC_Gateway_Spart();

		$this->assertArrayHasKey( Schema::DEBUG_API_ENDPOINT_FIELD, $gateway->form_fields );
		$description = (string) $gateway->form_fields[ Schema::DEBUG_API_ENDPOINT_FIELD ]['description'];
		$this->assertStringContainsString( '<code>https://api.spartpay.com</code>', $description );
		$this->assertStringContainsString( 'Live default', $description );
	}

	public function test_init_form_fields_reflects_saved_sandbox_environment(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) {
				if ( 'woocommerce_spart_settings' === $name ) {
					return array( 'environment' => 'sandbox' );
				}
				return $default_value;
			}
		);

		$gateway = new WC_Gateway_Spart();

		$description = (string) $gateway->form_fields[ Schema::DEBUG_API_ENDPOINT_FIELD ]['description'];
		$this->assertStringContainsString( '<code>https://sandbox-api.spartpay.com</code>', $description );
		$this->assertStringContainsString( 'Sandbox default', $description );
	}

	public function test_init_form_fields_surfaces_unrecognised_environment_in_label(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) {
				if ( 'woocommerce_spart_settings' === $name ) {
					// Anything other than 'live' or 'sandbox' is unrecognised
					// and must be surfaced in the label, not silently treated
					// as live.
					return array( 'environment' => 'production' );
				}
				return $default_value;
			}
		);

		$gateway = new WC_Gateway_Spart();

		$description = (string) $gateway->form_fields[ Schema::DEBUG_API_ENDPOINT_FIELD ]['description'];
		$this->assertStringContainsString( '<code>https://api.spartpay.com</code>', $description );
		$this->assertStringContainsString( 'unrecognised env: production', $description );
		$this->assertStringNotContainsString( 'WP_SPART_BASE_URL constant', $description );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_init_form_fields_attributes_source_to_wp_spart_base_url_constant(): void {
		// Defining WP_SPART_BASE_URL would otherwise pollute the process and
		// break WpHttpClientFactoryTest tests that assume the constant is unset.
		// Run in a child process so the define() stays local to this test.
		if ( ! defined( 'WP_SPART_BASE_URL' ) ) {
			define( 'WP_SPART_BASE_URL', 'http://stub-spart:8080' );
		}

		$gateway = new WC_Gateway_Spart();

		$description = (string) $gateway->form_fields[ Schema::DEBUG_API_ENDPOINT_FIELD ]['description'];
		$this->assertStringContainsString( WP_SPART_BASE_URL, $description );
		$this->assertStringContainsString( 'WP_SPART_BASE_URL constant', $description );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_init_form_fields_reports_default_when_wp_spart_base_url_is_non_string(): void {
		// WpHttpClientFactory::base_url_for() ignores WP_SPART_BASE_URL unless it
		// is a non-empty string. The gateway label must apply the SAME check so
		// it never claims the constant is the source while the factory silently
		// falls back to the live default. Defining the constant is one-way, so
		// this test runs in a child process to avoid polluting the suite.
		if ( ! defined( 'WP_SPART_BASE_URL' ) ) {
			define( 'WP_SPART_BASE_URL', true );
		}

		$gateway = new WC_Gateway_Spart();

		$description = (string) $gateway->form_fields[ Schema::DEBUG_API_ENDPOINT_FIELD ]['description'];
		$this->assertStringContainsString( '<code>https://api.spartpay.com</code>', $description );
		$this->assertStringContainsString( 'Live default', $description );
		$this->assertStringNotContainsString( 'WP_SPART_BASE_URL constant', $description );
	}

	public function test_init_form_fields_escapes_special_chars_in_resolved_url(): void {
		// Defence in depth — verify the description has exactly one <code>/</code>
		// pair (no extra HTML smuggled in via the URL or source string).
		$gateway     = new WC_Gateway_Spart();
		$description = (string) $gateway->form_fields[ Schema::DEBUG_API_ENDPOINT_FIELD ]['description'];

		$this->assertSame( 1, substr_count( $description, '<code>' ) );
		$this->assertSame( 1, substr_count( $description, '</code>' ) );
	}

	/**
	 * Existing stored API key + POSTed mask sentinel => keep stored.
	 * This is the masked-keeps-existing path that makes the password fields
	 * usable across multiple saves.
	 */
	public function test_validate_password_field_keeps_existing_when_value_matches_mask(): void {
		$gateway                      = new WC_Gateway_Spart();
		$gateway->settings['api_key'] = 'sk_live_abcdef1234567890';

		$mask = \Spart\WooCommerce\Settings\SecretMask::mask( 'sk_live_abcdef1234567890' );

		$this->assertSame(
			'sk_live_abcdef1234567890',
			$gateway->validate_password_field( 'api_key', $mask )
		);
	}

	/**
	 * Explicit empty POST => clear the stored secret. This is the
	 * affordance for "I want to remove the API key from this store".
	 */
	public function test_validate_password_field_clears_when_explicitly_blanked(): void {
		$gateway                      = new WC_Gateway_Spart();
		$gateway->settings['api_key'] = 'sk_live_abcdef1234567890';

		$this->assertSame(
			'',
			$gateway->validate_password_field( 'api_key', '' )
		);
	}

	/**
	 * Null POST (field absent from $_POST entirely) => keep existing.
	 * Defensive: a browser would never omit a password input that is in
	 * the rendered form, but if WC calls the validator with null we must
	 * not clobber the stored secret.
	 */
	public function test_validate_password_field_keeps_existing_when_value_is_null(): void {
		$gateway                      = new WC_Gateway_Spart();
		$gateway->settings['api_key'] = 'sk_live_abcdef1234567890';

		$this->assertSame(
			'sk_live_abcdef1234567890',
			$gateway->validate_password_field( 'api_key', null )
		);
	}

	/**
	 * generate_password_html must render the masked sentinel of the
	 * stored value into the input's `value` attribute so the merchant
	 * sees the field as "filled in" but the raw secret never leaves the
	 * server in plaintext.
	 */
	public function test_generate_password_html_renders_masked_existing_value(): void {
		$gateway                      = new WC_Gateway_Spart();
		$gateway->settings['api_key'] = 'sk_live_abcdef1234567890';

		$expected_mask = \Spart\WooCommerce\Settings\SecretMask::mask( 'sk_live_abcdef1234567890' );
		$html          = $gateway->generate_password_html(
			'api_key',
			array(
				'title'       => 'API Key',
				'description' => 'Your Spart API key.',
			)
		);

		$this->assertStringContainsString( 'value="' . htmlspecialchars( $expected_mask, ENT_QUOTES, 'UTF-8' ) . '"', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'autocomplete="new-password"', $html );
	}

	/**
	 * When no value is stored yet, the input renders empty so the
	 * merchant can paste a new key without first clearing a sentinel.
	 */
	public function test_generate_password_html_renders_empty_when_no_stored_value(): void {
		$gateway = new WC_Gateway_Spart();
		// settings['api_key'] deliberately unset; get_option falls back to schema default ('').
		$html = $gateway->generate_password_html(
			'api_key',
			array(
				'title' => 'API Key',
			)
		);

		$this->assertStringContainsString( 'value=""', $html );
	}

	/**
	 * Verifies short stored values (< 12 chars) render as all bullets and never leak.
	 */
	public function test_generate_password_html_renders_full_bullets_for_short_stored_value(): void {
		$gateway                      = new WC_Gateway_Spart();
		$gateway->settings['api_key'] = 'short_key';

		$html = $gateway->generate_password_html( 'api_key', array( 'title' => 'API Key' ) );

		$expected_mask = \Spart\WooCommerce\Settings\SecretMask::mask( 'short_key' );
		$this->assertStringContainsString(
			'value="' . htmlspecialchars( $expected_mask, ENT_QUOTES, 'UTF-8' ) . '"',
			$html
		);
		$this->assertStringNotContainsString( 'short_key', $html );
	}

	/**
	 * A new secret with surrounding whitespace (e.g. copy-pasted with a
	 * trailing newline) is trimmed before persistence. trim() is used
	 * rather than sanitize_text_field() to avoid mangling non-ASCII
	 * characters that some API key formats include.
	 */
	public function test_validate_password_field_saves_trimmed_new_value(): void {
		$gateway                      = new WC_Gateway_Spart();
		$gateway->settings['api_key'] = 'sk_live_OLD';

		$this->assertSame(
			'sk_live_NEW_VALUE_xyz789',
			$gateway->validate_password_field( 'api_key', '  sk_live_NEW_VALUE_xyz789  ' )
		);
	}

	/**
	 * A value that contains the bullet glyph (U+2022) is treated as a
	 * partially-edited mask sentinel — the edit is discarded and the
	 * existing stored secret is preserved. This prevents a merchant who
	 * accidentally edits the masked input from clobbering the stored
	 * key with bullet-contaminated bytes.
	 */
	public function test_validate_password_field_keeps_existing_when_bullet_glyph_present(): void {
		$gateway                      = new WC_Gateway_Spart();
		$gateway->settings['api_key'] = 'sk_live_abcdef1234567890';

		$bullet_contaminated = \Spart\WooCommerce\Settings\SecretMask::BULLET . 'edited_suffix';

		$this->assertSame(
			'sk_live_abcdef1234567890',
			$gateway->validate_password_field( 'api_key', $bullet_contaminated )
		);
	}

	/**
	 * The filter callback must (a) run Schema::sanitize() so disabled fields
	 * like environment are clamped to their schema default, and (b)
	 * unconditionally overwrite webhook_url with the canonical server-derived
	 * URL regardless of whatever value was in $_POST.
	 */
	public function test_enforce_schema_invariants_overwrites_webhook_url_and_sanitizes(): void {
		$gateway = new WC_Gateway_Spart();

		$result = $gateway->enforce_schema_invariants(
			array(
				'api_key'     => 'sk_live_abcdef1234567890',
				'webhook_url' => 'https://attacker.example/',
				'environment' => 'sandbox',
			)
		);

		$this->assertSame( 'http://localhost/wp-json/spart/v1/webhook', $result['webhook_url'] );
		$this->assertSame( 'sk_live_abcdef1234567890', $result['api_key'] );
		$this->assertSame( 'live', $result['environment'] );
	}

	/**
	 * The constructor must register a priority-20 callback on the
	 * woocommerce_settings_api_sanitized_fields_spart filter that purges
	 * the EligibilityChecker transient cache. Priority MUST be > 10 so it
	 * runs AFTER enforce_schema_invariants() — purging based on the
	 * about-to-be-saved sanitised settings, not the raw POST.
	 */
	public function test_constructor_registers_priority_20_purge_filter(): void {
		$registered = array();
		Monkey\Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$registered ) {
				$registered[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
				);
				return true;
			}
		);

		$gateway = new WC_Gateway_Spart();

		$purge = null;
		foreach ( $registered as $row ) {
			if (
				'woocommerce_settings_api_sanitized_fields_' . WC_Gateway_Spart::GATEWAY_ID === $row['hook']
				&& 20 === $row['priority']
			) {
				$purge = $row;
				break;
			}
		}
		$this->assertNotNull( $purge, 'No priority-20 callback registered on the spart sanitised-fields filter' );
		$this->assertSame( array( $gateway, 'purge_eligibility_cache_on_save' ), $purge['callback'] );
	}

	/**
	 * The purge callback must (a) delete the three EligibilityChecker
	 * transients so the next storefront request re-probes, and (b) return
	 * the settings array unchanged — it's wired as a filter, so anything
	 * other than a passthrough would corrupt the persisted settings.
	 */
	public function test_purge_eligibility_cache_on_save_clears_transients_and_passes_through(): void {
		$deleted = array();
		Monkey\Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$gateway = new WC_Gateway_Spart();

		$input  = array(
			'enabled' => 'yes',
			'api_key' => 'sk_live_new',
		);
		$result = $gateway->purge_eligibility_cache_on_save( $input );

		$this->assertSame( $input, $result, 'Filter callback must return its input unchanged' );
		$this->assertContains( 'spart_eligibility_positive', $deleted );
		$this->assertContains( 'spart_eligibility_negative', $deleted );
		$this->assertContains( 'spart_eligibility_error', $deleted );
	}
}
