<?php
/**
 * Settings schema.
 *
 * @package Spart\WooCommerce\Settings
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Settings;

/**
 * Owns the canonical ordered list of WC settings fields for the Spart gateway.
 *
 * `init_form_fields()` projects this to WC's settings array.
 * `WC_Gateway_Spart::enforce_schema_invariants()` pipes every saved value through
 * `sanitize()`. The `environment` field is disabled in the UI while
 * `SANDBOX_AVAILABLE` is false; sanitisation clamps any tampered POST back to
 * the default.
 */
final class Schema {

	public const SANDBOX_AVAILABLE = false;

	/**
	 * Canonical id for the read-only "API endpoint" field that surfaces
	 * the resolved Spart API base URL in the gateway settings page.
	 *
	 * Always rendered (no WP_DEBUG gate) so merchants can confirm at a
	 * glance which Spart API their site is talking to and where that URL
	 * came from (live default, sandbox default, or WP_SPART_BASE_URL).
	 *
	 * Cross-file contract: Schema declares the field with this id;
	 * WC_Gateway_Spart::init_form_fields() fills in its description on
	 * the same id. Tests reference this constant rather than the raw
	 * string to keep the contract visible to refactors. The id is kept
	 * historical ("debug_") so existing test expectations and any saved
	 * options remain stable; the user-visible label is just "API endpoint".
	 */
	public const DEBUG_API_ENDPOINT_FIELD = 'debug_api_endpoint';

	/**
	 * Default checkout window in minutes: 7 days (60 × 24 × 7).
	 *
	 * Cross-file contract: this is the merchant-facing "Default checkout
	 * window (minutes)" field's default AND the fallback value used by
	 * {@see Plugin::checkout_session()} when the option row is missing
	 * the `default_order_duration_minutes` key (legacy installs upgrading
	 * before they re-save the settings). Both call sites reference this
	 * constant so the default can change in one place.
	 */
	public const DEFAULT_ORDER_DURATION_MINUTES = 10080;

	/**
	 * Minimum checkout window in minutes.
	 *
	 * Cross-file contract: the day/hour/minute settings fields each use
	 * `min=0` (no single component carries the floor), so the 5-minute
	 * minimum is enforced on the *folded* total by the gateway on save —
	 * {@see WC_Gateway_Spart::resolve_checkout_window()} reverts and reports
	 * out-of-range windows — and by {@see clamp_minutes()} /
	 * {@see IntentRequestBuilder} as a defensive floor in case Schema
	 * sanitisation was bypassed (WP-CLI, migration, raw SQL).
	 */
	public const MIN_ORDER_DURATION_MINUTES = 5;

	/**
	 * Maximum checkout window in minutes: 7 days (60 × 24 × 7).
	 *
	 * Stripe authorization holds expire after ~7 days, so a window longer
	 * than this would let the auth lapse before capture. Enforced when the
	 * gateway folds the day/hour/minute components on save, and applied as a
	 * defensive ceiling in {@see clamp_minutes()} / {@see IntentRequestBuilder}.
	 */
	public const MAX_ORDER_DURATION_MINUTES = 10080;

	/** Minutes in one day. */
	public const MINUTES_PER_DAY = 1440;

	/** Minutes in one hour. */
	public const MINUTES_PER_HOUR = 60;

	/** Settings field id: days component of the checkout window. */
	public const FIELD_WINDOW_DAYS = 'default_order_window_days';

	/** Settings field id: hours component of the checkout window. */
	public const FIELD_WINDOW_HOURS = 'default_order_window_hours';

	/** Settings field id: minutes component of the checkout window. */
	public const FIELD_WINDOW_MINUTES = 'default_order_window_minutes';

	/**
	 * Derived (non-rendered) option key holding the canonical total checkout
	 * window in minutes. Computed from the three window components on save and
	 * read by {@see Plugin::checkout_session()}. Never declared as a Field, so
	 * it does not appear in {@see fields()} / {@see sanitize()}.
	 */
	public const DERIVED_DURATION_MINUTES_KEY = 'default_order_duration_minutes';

	/**
	 * Memoised field list.
	 *
	 * @var array<int, Field>|null
	 */
	private static ?array $fields = null;

	/**
	 * Return all settings fields in display order.
	 *
	 * @return array<int, Field>
	 */
	public static function fields(): array {
		if ( self::$fields !== null ) {
			return self::$fields;
		}

		$environment = Field::select(
			'environment',
			__( 'Environment', 'spart-woocommerce' ),
			'live',
			array(
				'live'    => __( 'Live', 'spart-woocommerce' ),
				'sandbox' => __( 'Sandbox', 'spart-woocommerce' ),
			),
			array(
				'description' => __( 'Selects the Spart API environment.', 'spart-woocommerce' ),
				'desc_tip'    => true,
			)
		);

		if ( ! self::SANDBOX_AVAILABLE ) {
			$environment = $environment->disabled(
				__( 'Sandbox environment coming soon. Currently locked to Live.', 'spart-woocommerce' )
			);
		}

		$fields = array(
			Field::checkbox(
				'enabled',
				__( 'Enable/Disable', 'spart-woocommerce' ),
				'no',
				array(
					'label' => __( 'Enable Spart payment method', 'spart-woocommerce' ),
				)
			),
			Field::text(
				'title',
				__( 'Title', 'spart-woocommerce' ),
				__( 'Pay with Spart', 'spart-woocommerce' ),
				array(
					'description' => __( 'Title shown to customers during checkout.', 'spart-woocommerce' ),
					'desc_tip'    => true,
				)
			),
			Field::textarea(
				'description',
				__( 'Description', 'spart-woocommerce' ),
				__( 'Split the payment with your friends!', 'spart-woocommerce' ),
				array(
					'description' => __( 'Description shown to customers during checkout.', 'spart-woocommerce' ),
					'desc_tip'    => true,
				)
			),
			Field::password(
				'api_key',
				__( 'API Key', 'spart-woocommerce' ),
				'',
				array(
					'description' => __( 'Your Spart merchant API key. Stored securely; never shown after save.', 'spart-woocommerce' ),
					'desc_tip'    => true,
				)
			),
			Field::password(
				'webhook_secret',
				__( 'Webhook Secret', 'spart-woocommerce' ),
				'',
				array(
					'description' => __( 'HMAC signing secret from your Spart dashboard.', 'spart-woocommerce' ),
					'desc_tip'    => true,
				)
			),
			Field::text(
				'webhook_url',
				__( 'Webhook URL', 'spart-woocommerce' ),
				'',
				array(
					'description'       => __( 'Copy this URL into your Spart dashboard webhook settings.', 'spart-woocommerce' ),
					'desc_tip'          => true,
					'custom_attributes' => array( 'readonly' => 'readonly' ),
				)
			),
			Field::number(
				self::FIELD_WINDOW_DAYS,
				__( 'Checkout window — days', 'spart-woocommerce' ),
				7,
				0,
				array(
					'description' => __( 'How long a Spart checkout stays valid before it expires. The combined days + hours + minutes window must be between 5 minutes and 7 days; default 7 days.', 'spart-woocommerce' ),
					'desc_tip'    => true,
				)
			),
			Field::number(
				self::FIELD_WINDOW_HOURS,
				__( 'Checkout window — hours', 'spart-woocommerce' ),
				0,
				0
			),
			Field::number(
				self::FIELD_WINDOW_MINUTES,
				__( 'Checkout window — minutes', 'spart-woocommerce' ),
				0,
				0
			),
			Field::checkbox(
				'messaging_enabled_product',
				__( 'SPART_SETTINGS_MESSAGING_PRODUCT_TITLE', 'spart-woocommerce' ),
				'no',
				array(
					'label'       => __( 'SPART_SETTINGS_MESSAGING_PRODUCT_LABEL', 'spart-woocommerce' ),
					'description' => __( 'Renders on classic product templates (single-product.php). For block-based product pages, place the "Spart Product Messaging" block instead.', 'spart-woocommerce' ),
					'desc_tip'    => true,
				)
			),
			Field::checkbox(
				'messaging_enabled_cart',
				__( 'SPART_SETTINGS_MESSAGING_CART_TITLE', 'spart-woocommerce' ),
				'no',
				array(
					'label'       => __( 'SPART_SETTINGS_MESSAGING_CART_LABEL', 'spart-woocommerce' ),
					'description' => __( 'Renders on the classic cart shortcode only. If your store uses the WooCommerce Cart block, place the "Spart Cart Messaging" block on the cart page instead.', 'spart-woocommerce' ),
					'desc_tip'    => true,
				)
			),
			$environment,
		);

		$fields[] = Field::checkbox(
			'debug_logging',
			__( 'Verbose logging', 'spart-woocommerce' ),
			'no',
			array(
				'description' => __( 'Also write INFO and DEBUG checkout trace lines to WooCommerce → Status → Logs. WARNING and ERROR messages are always written, regardless of this setting.', 'spart-woocommerce' ),
				'desc_tip'    => true,
			)
		);

		// Must be LAST: WC's 'title' field type emits </table><h3>...</h3><table>,
		// which would visually swallow any field rendered after it under the
		// "API endpoint" heading.
		$fields[] = Field::title(
			self::DEBUG_API_ENDPOINT_FIELD,
			__( 'API endpoint', 'spart-woocommerce' )
		);

		self::$fields = $fields;

		return self::$fields;
	}

	/**
	 * Look up a single field by ID.
	 *
	 * @param string $id Field key.
	 * @return Field
	 * @throws \InvalidArgumentException If the field ID is unknown.
	 */
	public static function field( string $id ): Field {
		foreach ( self::fields() as $field ) {
			if ( $field->id() === $id ) {
				return $field;
			}
		}
		throw new \InvalidArgumentException(
			sprintf( 'Unknown Spart settings field "%s".', $id ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		);
	}

	/**
	 * Total checkout window in minutes derived from the three window
	 * components. Missing, non-numeric, or negative components count as 0.
	 *
	 * @param array<string, mixed> $settings Settings array (raw or sanitised).
	 * @return int
	 */
	public static function total_minutes( array $settings ): int {
		$days    = self::non_negative_int( $settings[ self::FIELD_WINDOW_DAYS ] ?? 0 );
		$hours   = self::non_negative_int( $settings[ self::FIELD_WINDOW_HOURS ] ?? 0 );
		$minutes = self::non_negative_int( $settings[ self::FIELD_WINDOW_MINUTES ] ?? 0 );

		return ( $days * self::MINUTES_PER_DAY ) + ( $hours * self::MINUTES_PER_HOUR ) + $minutes;
	}

	/**
	 * Split a total minute count into day / hour / minute components.
	 * Negative input is treated as 0. Round-trips with {@see total_minutes()}.
	 *
	 * @param int $minutes Total minutes.
	 * @return array{days:int, hours:int, minutes:int}
	 */
	public static function decompose_minutes( int $minutes ): array {
		$minutes = max( 0, $minutes );
		$rem     = $minutes % self::MINUTES_PER_DAY;

		return array(
			'days'    => intdiv( $minutes, self::MINUTES_PER_DAY ),
			'hours'   => intdiv( $rem, self::MINUTES_PER_HOUR ),
			'minutes' => $rem % self::MINUTES_PER_HOUR,
		);
	}

	/**
	 * Clamp a minute count into the valid [MIN, MAX] checkout-window range.
	 *
	 * @param int $minutes Candidate minutes.
	 * @return int
	 */
	public static function clamp_minutes( int $minutes ): int {
		return min( self::MAX_ORDER_DURATION_MINUTES, max( self::MIN_ORDER_DURATION_MINUTES, $minutes ) );
	}

	/**
	 * Coerce an arbitrary value to a non-negative integer, saturated at
	 * {@see MAX_ORDER_DURATION_MINUTES}. Negatives and non-numerics become 0;
	 * absurd tampered values are capped so that {@see total_minutes()} cannot
	 * overflow its strict int return into a float (which would fatal). Capping
	 * never reduces an in-range component, since each component of a valid
	 * window is itself <= MAX.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function non_negative_int( mixed $value ): int {
		$int = is_numeric( $value ) ? (int) $value : 0;
		return min( self::MAX_ORDER_DURATION_MINUTES, max( 0, $int ) );
	}

	/**
	 * Project every field to a WC settings-array keyed by field ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function as_wc_settings_array(): array {
		$out = array();
		foreach ( self::fields() as $field ) {
			$out[ $field->id() ] = $field->to_wc_array();
		}
		return $out;
	}

	/**
	 * Return [field_id => default_value] for every schema field.
	 *
	 * Mirrors the lazy default-fallback behaviour the classic gateway
	 * gets via WC's `get_option()` (which fills in the field default
	 * for any key the saved option doesn't define), but materialised
	 * as a flat array so consumers that snapshot settings into a
	 * payload — Blocks `SpartBlocksSupport::initialize()` in
	 * particular — can `array_merge( Schema::defaults(), $saved )`
	 * to avoid divergence between classic and Blocks checkout when
	 * the saved option is partial (e.g., from WP-CLI or migration).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( self::fields() as $field ) {
			$out[ $field->id() ] = $field->default();
		}
		return $out;
	}

	/**
	 * Sanitise raw POST values, keeping only known field keys.
	 *
	 * Display-only fields (type `title`, type `sectionend`) are dropped
	 * even when present in `$raw`. WC's `init_settings()` pre-fills every
	 * declared form_field key with its default — including title fields
	 * that have no data semantics — so they reach this filter with an
	 * empty-string value. Persisting that key would surface as `''` in
	 * the saved option, polluting the schema with display artefacts
	 * (`debug_api_endpoint` is the canonical example: a display-only
	 * <h3> heading that must never become an option key).
	 *
	 * `webhook_url` is also dropped here: it is server-rendered from
	 * `home_url()` after this filter via `enforce_schema_invariants()`,
	 * and must not be accepted from POST under any circumstances.
	 *
	 * @param array<string, mixed> $raw Untrusted POST data.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$out = array();
		foreach ( self::fields() as $field ) {
			$id = $field->id();

			if ( $id === 'webhook_url' ) {
				continue;
			}

			if ( in_array( $field->type(), array( 'title', 'sectionend' ), true ) ) {
				continue;
			}

			if ( array_key_exists( $id, $raw ) ) {
				$out[ $id ] = $field->sanitize( $raw[ $id ] );
			}
		}
		return $out;
	}

	/**
	 * Reset the memoised field list. Test-only.
	 *
	 * Lets a test that mutates schema-adjacent state (or that wants a
	 * fresh build for assertion stability) discard the cached fields()
	 * result so the next call rebuilds from scratch.
	 */
	public static function reset_for_tests(): void {
		self::$fields = null;
	}
}
