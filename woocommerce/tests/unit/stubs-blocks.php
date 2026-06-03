<?php
/**
 * Minimal stand-in for WooCommerce Blocks' AbstractPaymentMethodType so unit
 * tests can extend SpartBlocksSupport without booting WC Blocks. Exposes the
 * subset of the contract Spart actually relies on (name accessor + protected
 * settings array initialisable from get_option in initialize()).
 *
 * Lives in its own file because it needs the
 * Automattic\WooCommerce\Blocks\Payments\Integrations namespace; bootstrap.php
 * itself is non-namespaced.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

abstract class AbstractPaymentMethodType {

	/** @var string */
	protected $name = '';

	/** @var array<string, mixed>|null */
	protected $settings = null;

	public function get_name(): string {
		return $this->name;
	}

	abstract public function initialize();

	abstract public function is_active();

	abstract public function get_payment_method_script_handles();

	/** @return array<string, mixed> */
	public function get_payment_method_data() {
		return array();
	}
}
