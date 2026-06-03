<?php
/**
 * Admin\WcVersionFloorNotice — dismissible admin notice surfaced when the
 * installed WooCommerce version is below the blocks-supported floor.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

use Spart\WooCommerce\Constants;

/**
 * Renders an `notice-warning is-dismissible` admin notice when the
 * installed WooCommerce version is below {@see Constants::WC_VERSION_FLOOR_FOR_BLOCKS}.
 * Below that floor the cart and product messaging blocks may render
 * inconsistently because the WC Blocks subsystem doesn't reliably
 * honour `render_callback` for server-rendered block types.
 *
 * Behaviour:
 *  - Silent when WC_VERSION is undefined (WC not active, or activated
 *    after admin_notices fires — let the standard "WC required" notices
 *    handle that case).
 *  - Silent when WC_VERSION >= floor.
 *  - Warning notice otherwise, with both the installed and required
 *    versions interpolated so the merchant can confirm what they need
 *    to upgrade to.
 */
final class WcVersionFloorNotice {

	/**
	 * Register the render handler on `admin_notices`.
	 *
	 * Called from {@see \Spart\WooCommerce\Plugin::on_plugins_loaded()}.
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render the admin notice if the WC version is below the blocks floor.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			return;
		}

		$installed = (string) constant( 'WC_VERSION' );
		$required  = Constants::WC_VERSION_FLOOR_FOR_BLOCKS;

		if ( version_compare( $installed, $required, '>=' ) ) {
			return;
		}

		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong>
					<?php \esc_html_e( 'Spart messaging blocks require a newer WooCommerce.', 'spart-woocommerce' ); ?>
				</strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: installed WooCommerce version, 2: required WooCommerce version. */
					\esc_html__( 'You are running WooCommerce %1$s. Spart messaging requires WooCommerce %2$s or newer to register the cart and product messaging blocks correctly. Please update WooCommerce.', 'spart-woocommerce' ),
					\esc_html( $installed ),
					\esc_html( $required )
				);
				?>
			</p>
		</div>
		<?php
	}
}
