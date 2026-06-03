<?php
/**
 * Settings field value object.
 *
 * @package Spart\WooCommerce
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Settings;

/**
 * Immutable value object describing a single WC settings field.
 */
final class Field {

	/**
	 * Constructor.
	 *
	 * @param string                     $id              Field key.
	 * @param string                     $type            WC field type (text, checkbox, …).
	 * @param string                     $title           Human-readable label.
	 * @param mixed                      $default         Default value.
	 * @param array<string, mixed>       $extras          Extra WC field keys (description, desc_tip, …).
	 * @param array<string, string>|null $options     Select options map, or null for non-select fields.
	 * @param bool                       $disabled        Whether the field is disabled.
	 * @param string|null                $disabled_reason Human-readable reason shown when disabled.
	 */
	private function __construct(
		private readonly string $id,
		private readonly string $type,
		private readonly string $title,
		private readonly mixed $default,
		private readonly array $extras = array(),
		private readonly ?array $options = null,
		private readonly bool $disabled = false,
		private readonly ?string $disabled_reason = null,
	) {
	}

	/**
	 * Create a text field.
	 *
	 * @param string               $id      Field key.
	 * @param string               $title   Human-readable label.
	 * @param string               $default Default value.
	 * @param array<string, mixed> $extras  Extra WC field keys.
	 * @return self
	 */
	public static function text( string $id, string $title, string $default = '', array $extras = array() ): self {
		return new self( $id, 'text', $title, $default, $extras );
	}

	/**
	 * Create a password field.
	 *
	 * @param string               $id      Field key.
	 * @param string               $title   Human-readable label.
	 * @param string               $default Default value.
	 * @param array<string, mixed> $extras  Extra WC field keys.
	 * @return self
	 */
	public static function password( string $id, string $title, string $default = '', array $extras = array() ): self {
		return new self( $id, 'password', $title, $default, $extras );
	}

	/**
	 * Create a textarea field.
	 *
	 * @param string               $id      Field key.
	 * @param string               $title   Human-readable label.
	 * @param string               $default Default value.
	 * @param array<string, mixed> $extras  Extra WC field keys.
	 * @return self
	 */
	public static function textarea( string $id, string $title, string $default = '', array $extras = array() ): self {
		return new self( $id, 'textarea', $title, $default, $extras );
	}

	/**
	 * Create a checkbox field.
	 *
	 * @param string               $id      Field key.
	 * @param string               $title   Human-readable label.
	 * @param string               $default Default value ('yes' or 'no').
	 * @param array<string, mixed> $extras  Extra WC field keys.
	 * @return self
	 */
	public static function checkbox( string $id, string $title, string $default = 'no', array $extras = array() ): self {
		return new self( $id, 'checkbox', $title, $default, $extras );
	}

	/**
	 * Create a select field.
	 *
	 * @param string                $id      Field key.
	 * @param string                $title   Human-readable label.
	 * @param string                $default Default option key.
	 * @param array<string, string> $options Key-label option pairs.
	 * @param array<string, mixed>  $extras  Extra WC field keys.
	 * @return self
	 */
	public static function select( string $id, string $title, string $default, array $options, array $extras = array() ): self {
		return new self( $id, 'select', $title, $default, $extras, $options );
	}

	/**
	 * Create a number field.
	 *
	 * @param string               $id      Field key.
	 * @param string               $title   Human-readable label.
	 * @param int                  $default Default integer value.
	 * @param int                  $min     Minimum allowed value (rendered as the HTML5 `min` attribute).
	 * @param array<string, mixed> $extras  Extra WC field keys (description, desc_tip, …).
	 * @return self
	 */
	public static function number( string $id, string $title, int $default, int $min, array $extras = array() ): self {
		$merged_attrs                = array_merge(
			array(
				'min'  => $min,
				'step' => 1,
			),
			is_array( $extras['custom_attributes'] ?? null ) ? $extras['custom_attributes'] : array()
		);
		$extras['custom_attributes'] = $merged_attrs;
		return new self( $id, 'number', $title, $default, $extras );
	}

	/**
	 * Create a title field — renders as a section heading + description with
	 * no input element. Used for read-only labels in the settings page.
	 *
	 * @param string               $id     Field key.
	 * @param string               $title  Human-readable label.
	 * @param array<string, mixed> $extras Extra WC field keys (e.g. description).
	 * @return self
	 */
	public static function title( string $id, string $title, array $extras = array() ): self {
		return new self( $id, 'title', $title, '', $extras );
	}

	/**
	 * Return a copy of this field marked as disabled for the given reason.
	 *
	 * @param string $reason Human-readable explanation shown to the merchant.
	 * @return self
	 */
	public function disabled( string $reason ): self {
		return new self(
			$this->id,
			$this->type,
			$this->title,
			$this->default,
			$this->extras,
			$this->options,
			true,
			$reason,
		);
	}

	/**
	 * Return the field key.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Return the field's WC type (e.g. 'text', 'password', 'title').
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Return the field's default value.
	 *
	 * @return mixed
	 */
	public function default(): mixed {
		return $this->default;
	}

	/**
	 * Return whether the field is disabled.
	 *
	 * @return bool
	 */
	public function is_disabled(): bool {
		return $this->disabled;
	}

	/**
	 * Return the reason the field is disabled, or null if not disabled.
	 *
	 * @return string|null
	 */
	public function disabled_reason(): ?string {
		return $this->disabled_reason;
	}

	/**
	 * Project to WC settings array form.
	 *
	 * @return array<string, mixed>
	 */
	public function to_wc_array(): array {
		$base = array(
			'title'   => $this->title,
			'type'    => $this->type,
			'default' => $this->default,
		);

		if ( $this->options !== null ) {
			$base['options'] = $this->options;
		}

		// Apply caller-supplied extras first, then layer the disabled overrides
		// on top so they win. Without this, an extras `description` would mask
		// the disabled reason and an extras `custom_attributes` could wipe out
		// the `disabled` HTML attribute entirely.
		$merged = array_merge( $base, $this->extras );

		if ( $this->disabled ) {
			$existing_attrs              = is_array( $merged['custom_attributes'] ?? null ) ? $merged['custom_attributes'] : array();
			$merged['custom_attributes'] = array_merge( $existing_attrs, array( 'disabled' => 'disabled' ) );
			if ( $this->disabled_reason !== null ) {
				$merged['description'] = $this->disabled_reason;
			}
		}

		return $merged;
	}

	/**
	 * Sanitize a raw value for this field.
	 *
	 * Disabled fields always return their default. Checkbox normalises to
	 * 'yes'/'no'. Select rejects unknown keys. Everything else is trimmed.
	 *
	 * @param mixed $raw Untrusted input.
	 * @return mixed
	 */
	public function sanitize( mixed $raw ): mixed {
		if ( $this->disabled ) {
			return $this->default;
		}

		return match ( $this->type ) {
			'checkbox' => in_array( $raw, array( true, '1', 'yes', 1 ), true ) ? 'yes' : 'no',
			'select'   => is_string( $raw ) && $this->options !== null && array_key_exists( $raw, $this->options )
				? $raw
				: $this->default,
			'number'   => $this->sanitize_number( $raw ),
			'title'    => $this->default,
			default    => is_string( $raw ) ? trim( $raw ) : $raw,
		};
	}

	/**
	 * Sanitize a raw value for a number field.
	 *
	 * Non-numeric input returns the default. Numeric values are cast to int;
	 * values below the field's declared `min` attribute are clamped to the
	 * default rather than persisted, mirroring the .NET
	 * MerchantAppOptionsDto.EnsureValid floor enforcement.
	 *
	 * @param mixed $raw Untrusted input.
	 * @return mixed
	 */
	private function sanitize_number( mixed $raw ): mixed {
		if ( ! is_numeric( $raw ) ) {
			return $this->default;
		}
		$value = (int) $raw;
		$min   = isset( $this->extras['custom_attributes']['min'] ) && is_numeric( $this->extras['custom_attributes']['min'] )
			? (int) $this->extras['custom_attributes']['min']
			: null;
		if ( null !== $min && $value < $min ) {
			return $this->default;
		}
		return $value;
	}
}
