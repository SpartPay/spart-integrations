<?php
/**
 * Spart payment gateway class.
 *
 * @package Spart\WooCommerce\Gateway
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Gateway;

use Spart\WooCommerce\Eligibility\EligibilityChecker;
use Spart\WooCommerce\Http\WpHttpClientFactory;
use Spart\WooCommerce\Logging\LogEvents;
use Spart\WooCommerce\Plugin;
use Spart\WooCommerce\Settings\Schema;
use Spart\WooCommerce\Settings\SecretMask;

/**
 * WooCommerce payment gateway for Spart.
 *
 * Handles gateway registration, settings-page rendering, settings
 * persistence, and delegates to the Spart SDK for payment processing.
 *
 * Class name MUST stay PascalCase-with-underscores (`WC_Gateway_Spart`) —
 * that is the convention WooCommerce documents and the convention WC's
 * gateway discovery code requires.
 */
class WC_Gateway_Spart extends \WC_Payment_Gateway {

	public const GATEWAY_ID = 'spart';

	/**
	 * Initialises gateway properties and hooks.
	 */
	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'Spart', 'spart-woocommerce' );
		$this->method_description = __( 'Let customers split their payment into multiple parts via Spart.', 'spart-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		add_filter(
			'woocommerce_settings_api_sanitized_fields_' . self::GATEWAY_ID,
			array( $this, 'enforce_schema_invariants' )
		);

		// Priority 20 — runs AFTER enforce_schema_invariants (default 10) so
		// the about-to-be-saved settings are already canonicalised. Any
		// merchant-visible settings change can flip the eligibility verdict
		// (new API key, different environment, etc.), so we invalidate the
		// transient cache here rather than waiting for it to expire.
		add_filter(
			'woocommerce_settings_api_sanitized_fields_' . self::GATEWAY_ID,
			array( $this, 'purge_eligibility_cache_on_save' ),
			20
		);
	}

	/**
	 * Register the WC settings fields from the schema.
	 */
	public function init_form_fields(): void {
		$this->form_fields                           = Schema::as_wc_settings_array();
		$this->form_fields['webhook_url']['default'] = $this->webhook_url();

		$this->seed_checkout_window_defaults();

		if ( isset( $this->form_fields[ Schema::DEBUG_API_ENDPOINT_FIELD ] ) ) {
			$env = $this->saved_environment();
			$url = WpHttpClientFactory::base_url_for( $env );
			$src = $this->resolve_endpoint_source( $env );
			$this->form_fields[ Schema::DEBUG_API_ENDPOINT_FIELD ]['description'] = sprintf(
				'<code>%s</code> &mdash; %s',
				esc_html( $url ),
				esc_html( $src )
			);
		}
	}

	/**
	 * Return the saved environment value.
	 *
	 * Reads the option directly because init_form_fields() runs in the
	 * constructor BEFORE init_settings() populates $this->settings.
	 */
	private function saved_environment(): string {
		$saved = get_option( $this->get_option_key(), array() );
		if ( is_array( $saved ) && isset( $saved['environment'] ) && is_string( $saved['environment'] ) ) {
			return $saved['environment'];
		}
		return 'live';
	}

	/**
	 * Seed the day/hour/minute field defaults from a legacy install's stored
	 * total minutes, so the settings page shows the merchant's real current
	 * window before they re-save.
	 *
	 * No-op once the split components have been persisted (the merchant has
	 * saved at least once under the new schema), and for fresh installs, which
	 * keep the schema's 7/0/0 field defaults. Runs in the constructor BEFORE
	 * init_settings() populates $this->settings, so it reads the option row
	 * directly like {@see saved_environment()}.
	 */
	private function seed_checkout_window_defaults(): void {
		$saved = get_option( $this->get_option_key(), array() );
		if ( ! is_array( $saved ) ) {
			return;
		}

		$has_components = isset( $saved[ Schema::FIELD_WINDOW_DAYS ] )
			|| isset( $saved[ Schema::FIELD_WINDOW_HOURS ] )
			|| isset( $saved[ Schema::FIELD_WINDOW_MINUTES ] );
		if ( $has_components ) {
			return;
		}

		if ( ! isset( $saved[ Schema::DERIVED_DURATION_MINUTES_KEY ] ) || ! is_numeric( $saved[ Schema::DERIVED_DURATION_MINUTES_KEY ] ) ) {
			return;
		}

		$parts = Schema::decompose_minutes(
			Schema::clamp_minutes( (int) $saved[ Schema::DERIVED_DURATION_MINUTES_KEY ] )
		);

		$this->form_fields[ Schema::FIELD_WINDOW_DAYS ]['default']    = $parts['days'];
		$this->form_fields[ Schema::FIELD_WINDOW_HOURS ]['default']   = $parts['hours'];
		$this->form_fields[ Schema::FIELD_WINDOW_MINUTES ]['default'] = $parts['minutes'];
	}

	/**
	 * Human-readable label describing which input produced the base URL.
	 *
	 * If the saved environment is neither 'live' nor 'sandbox', the label
	 * surfaces the unrecognised value rather than silently labelling the
	 * fallback as "Live default" — the whole point of this diagnostic
	 * field is to flag anomalies in the persisted settings. The returned
	 * string is escaped by the caller via esc_html(), so $env must not
	 * be pre-escaped here.
	 *
	 * @param string $env The saved environment string.
	 */
	private function resolve_endpoint_source( string $env ): string {
		if ( defined( 'WP_SPART_BASE_URL' ) && is_string( \WP_SPART_BASE_URL ) && '' !== \WP_SPART_BASE_URL ) {
			return __( 'WP_SPART_BASE_URL constant', 'spart-woocommerce' );
		}
		if ( 'sandbox' === $env ) {
			return __( 'Sandbox default', 'spart-woocommerce' );
		}
		if ( 'live' === $env ) {
			return __( 'Live default', 'spart-woocommerce' );
		}
		return sprintf(
			/* translators: %s: unrecognised environment value read from wp_options. */
			__( 'Live default (unrecognised env: %s)', 'spart-woocommerce' ),
			$env
		);
	}


	/**
	 * Validate (and conditionally preserve) a password-type settings field.
	 *
	 * WC calls `validate_{type}_field( $key, $value )` for every field in the
	 * settings form during {@see process_admin_options()}. For password fields
	 * the browser never sends the stored secret back — instead the form
	 * renders a mask sentinel via {@see generate_password_html()}. This method
	 * handles all five meaningful POST states:
	 *
	 * - null         : field was absent from $_POST entirely → keep existing.
	 * - exact mask   : merchant submitted the mask unchanged → keep existing.
	 * - bullet glyph : submitted value contains the bullet character used in
	 *                  the mask (partially-edited mask) → keep existing.
	 * - empty        : merchant cleared the field → persist the empty string.
	 * - other        : merchant typed a new secret → trim and persist it.
	 *
	 * @param string      $key   Settings field key (without the option prefix).
	 * @param string|null $value Raw POST value for this field, or null if absent.
	 * @return string
	 */
	public function validate_password_field( $key, $value ): string {
		$existing = (string) $this->get_option( (string) $key, '' );

		if ( null === $value ) {
			return $existing;
		}

		$unslashed = wp_unslash( (string) $value );

		if ( $unslashed === SecretMask::mask( $existing ) ) {
			return $existing;
		}

		if ( str_contains( $unslashed, SecretMask::BULLET ) ) {
			return $existing;
		}

		if ( '' === $unslashed ) {
			return '';
		}

		return trim( $unslashed );
	}

	/**
	 * Render the password input with a partial-reveal mask of the stored
	 * secret. The mask is the actual string the merchant POSTs back if
	 * they do not edit the field — `validate_password_field()` detects
	 * an unchanged mask and keeps the existing stored value.
	 *
	 * @param string              $key  Field key (used as the input `name`/`id`).
	 * @param array<string,mixed> $data Merged field definition array.
	 * @return string
	 */
	public function generate_password_html( $key, $data ): string {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'description'       => '',
			'placeholder'       => '',
			'class'             => '',
			'css'               => '',
			'desc_tip'          => false,
			'custom_attributes' => array(),
		);
		$data      = wp_parse_args( $data, $defaults );

		$rendered = SecretMask::mask( (string) $this->get_option( (string) $key, '' ) );

		ob_start();
		?>
<tr valign="top">
	<th scope="row" class="titledesc">
		<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
			<input
				class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>"
				type="password"
				name="<?php echo esc_attr( $field_key ); ?>"
				id="<?php echo esc_attr( $field_key ); ?>"
				style="<?php echo esc_attr( $data['css'] ); ?>"
				value="<?php echo esc_attr( $rendered ); ?>"
				placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>"
				autocomplete="new-password"
			/>
			<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</fieldset>
	</td>
</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Filter callback for woocommerce_settings_api_sanitized_fields_spart.
	 *
	 * Runs the entire saved array through {@see Schema::sanitize()} — which
	 * re-applies per-field sanitisation, clamps disabled fields (e.g.
	 * environment → 'live' while {@see Schema::SANDBOX_AVAILABLE} is false),
	 * and strips unknown keys — then unconditionally overwrites webhook_url
	 * with the canonical server-derived URL, which must never be accepted
	 * from POST. Wired in the constructor.
	 *
	 * @param array<string,mixed> $settings Settings array WC is about to persist.
	 * @return array<string,mixed>
	 */
	public function enforce_schema_invariants( array $settings ): array {
		$settings                = Schema::sanitize( $settings );
		$settings                = $this->resolve_checkout_window( $settings );
		$settings['webhook_url'] = $this->webhook_url();
		return $settings;
	}

	/**
	 * Fold the three checkout-window components (days/hours/minutes) into the
	 * canonical derived {@see Schema::DERIVED_DURATION_MINUTES_KEY} value,
	 * enforcing the [5 minute, 7 day] range.
	 *
	 * Out-of-range input is rejected rather than persisted: a WC settings
	 * error is surfaced and the previously-saved window (clamped) is restored
	 * so the invalid combination never reaches wp_options. Fresh installs with
	 * no prior value fall back to the 7-day default. WooCommerce's
	 * `process_admin_options()` cannot hard-abort the POST, so "blocking" the
	 * save means reverting the offending values in place.
	 *
	 * @param array<string, mixed> $settings Sanitised settings array.
	 * @return array<string, mixed>
	 */
	private function resolve_checkout_window( array $settings ): array {
		$total = Schema::total_minutes( $settings );

		if ( $total < Schema::MIN_ORDER_DURATION_MINUTES || $total > Schema::MAX_ORDER_DURATION_MINUTES ) {
			$saved      = get_option( $this->get_option_key(), array() );
			$saved      = is_array( $saved ) ? $saved : array();
			$prev_total = isset( $saved[ Schema::DERIVED_DURATION_MINUTES_KEY ] ) && is_numeric( $saved[ Schema::DERIVED_DURATION_MINUTES_KEY ] )
				? Schema::clamp_minutes( (int) $saved[ Schema::DERIVED_DURATION_MINUTES_KEY ] )
				: Schema::DEFAULT_ORDER_DURATION_MINUTES;

			$parts = Schema::decompose_minutes( $prev_total );

			$settings[ Schema::FIELD_WINDOW_DAYS ]            = $parts['days'];
			$settings[ Schema::FIELD_WINDOW_HOURS ]           = $parts['hours'];
			$settings[ Schema::FIELD_WINDOW_MINUTES ]         = $parts['minutes'];
			$settings[ Schema::DERIVED_DURATION_MINUTES_KEY ] = $prev_total;

			if ( class_exists( '\WC_Admin_Settings' ) ) {
				$message = $total > Schema::MAX_ORDER_DURATION_MINUTES
					? __( 'The Spart checkout window must be at most 7 days. Your previous value was kept.', 'spart-woocommerce' )
					: __( 'The Spart checkout window must be at least 5 minutes. Your previous value was kept.', 'spart-woocommerce' );
				\WC_Admin_Settings::add_error( $message );
			}

			return $settings;
		}//end if

		$settings[ Schema::DERIVED_DURATION_MINUTES_KEY ] = $total;
		return $settings;
	}

	/**
	 * Invalidate the eligibility transient cache when the merchant saves
	 * settings.
	 *
	 * Wired as a priority-20 filter callback so it runs AFTER
	 * {@see self::enforce_schema_invariants()} has already canonicalised
	 * the settings array. Returns its input unchanged — this is a side-effect
	 * filter, NOT a transformer; anything other than a passthrough would
	 * corrupt persisted settings.
	 *
	 * Always purges, even when settings haven't visibly changed: a re-save
	 * with identical values is often a merchant retry after fixing a typo
	 * in their dashboard upstream, and stale cache entries would mask it.
	 *
	 * @param array<string,mixed> $settings Sanitised settings array WC is about to persist.
	 * @return array<string,mixed>
	 */
	public function purge_eligibility_cache_on_save( array $settings ): array {
		EligibilityChecker::purge_cache();
		return $settings;
	}

	/**
	 * Decide whether the Spart gateway should appear on the storefront.
	 *
	 * Layers three short-circuits, each strictly cheaper than the next:
	 *
	 *   1. {@see \WC_Payment_Gateway::is_available()} — the merchant flipped
	 *      "Enable Spart" to "yes". Reads $this->enabled in memory.
	 *   2. API key is configured. Reads a saved option in memory.
	 *   3. {@see EligibilityChecker::is_eligible()} — server says the merchant
	 *      is approved. Backed by a 3-key WP transient cache so storefront
	 *      requests never block on the API for more than the timeout, and
	 *      typically never call out at all.
	 *
	 * Skipping the eligibility probe when the gateway is disabled or has no
	 * API key isn't an optimisation — it's correctness: those paths can't
	 * possibly succeed and there's no reason to spend the merchant's API
	 * budget proving it.
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( '' === (string) $this->get_option( 'api_key', '' ) ) {
			return false;
		}
		return Plugin::eligibility_checker()->is_eligible();
	}

	/**
	 * Derive the absolute webhook URL for this site.
	 *
	 * Uses {@see rest_url()} so the URL adapts to the site's permalink
	 * structure: pretty permalinks yield `/wp-json/spart/v1/webhook`,
	 * while Plain permalinks yield `/?rest_route=/spart/v1/webhook`.
	 * The latter is critical for shared hosts that don't run mod_rewrite,
	 * where `/wp-json/...` would 404 at the web-server layer before WP
	 * could route the REST request.
	 *
	 * @return string
	 */
	private function webhook_url(): string {
		return rest_url( 'spart/v1/webhook' );
	}

	/**
	 * Process a payment order.
	 *
	 * Hands the order to {@see CheckoutSession} and translates the result
	 * into WooCommerce's `['result' => …, 'redirect' => …]` contract. On
	 * any failure path a customer-facing notice is added so the message
	 * renders on the checkout page after WC's redirect-back.
	 *
	 * @param mixed $order_id WooCommerce order ID (int|string accepted by WC).
	 * @return array{result: string, redirect: string}
	 */
	public function process_payment( $order_id ): array {
		$order = function_exists( 'wc_get_order' ) ? \wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				\wc_add_notice( __( 'We could not load your order. Please try again.', 'spart-woocommerce' ), 'error' );
			}
			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		$correlation_id = function_exists( 'wp_generate_uuid4' ) ? \wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$base_context   = array(
			'correlation_id' => $correlation_id,
			'order_id'       => $order->get_id(),
			'environment'    => $this->environment_for_logs(),
		);

		Plugin::logger()->info(
			'Spart checkout started.',
			array_merge( $base_context, array( 'event' => LogEvents::CHECKOUT_STARTED ) )
		);

		$result = Plugin::checkout_session()->checkout( $order, $correlation_id );

		if ( ! $result->is_success() ) {
			Plugin::order_disposer()->dispose( $order, $result, $correlation_id );

			if ( function_exists( 'wc_add_notice' ) ) {
				\wc_add_notice( $result->customer_message(), 'error' );
			}
			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		Plugin::logger()->info(
			'Spart checkout succeeded; redirecting shopper.',
			array_merge( $base_context, array( 'event' => LogEvents::CHECKOUT_SUCCEEDED ) )
		);

		return array(
			'result'   => 'success',
			'redirect' => $result->redirect_url(),
		);
	}

	/**
	 * Resolve the configured Spart environment for log-context purposes only.
	 *
	 * Defaults to 'live'. Raw base_url is intentionally NOT logged — bespoke
	 * hosts via `WP_SPART_BASE_URL` would otherwise leak into wc-logs.
	 *
	 * @return string 'live' | 'sandbox' | configured value
	 */
	private function environment_for_logs(): string {
		$settings = (array) \get_option( 'woocommerce_spart_settings', array() );
		return (string) ( $settings['environment'] ?? 'live' );
	}
}
