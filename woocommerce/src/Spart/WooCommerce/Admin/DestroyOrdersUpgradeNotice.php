<?php
/**
 * Admin\DestroyOrdersUpgradeNotice — one-time dismissable admin notice
 * informing merchants that failed Spart checkouts now destroy their
 * pending orders instead of leaving them visible in the orders list.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

/**
 * One-time, dismissable admin notice that explains the destroy-on-failure
 * behavior introduced by the destroy-orders-on-checkout-failure feature.
 *
 * Lifecycle:
 *  - Activation::activate() persists `spart_destroy_orders_upgrade_notice_dismissed`
 *    = true on fresh installs so brand-new merchants never see this notice
 *    (the destroy behavior is the documented default they signed up for).
 *  - Existing installs upgrading from a pre-feature version get the notice
 *    on next admin page load until the merchant clicks the dismiss button
 *    — a nonce-protected GET link to admin-post.php (handle_dismiss() also
 *    enforces the manage_woocommerce capability so shop_manager users can
 *    dismiss the notice in addition to administrators).
 *  - Once dismissed, the option is set to true and render() short-circuits.
 *
 * Notice tone: informational (`notice-info`) — this is a behavior change
 * merchants benefit from (no more orphan pending orders cluttering the
 * orders list), not a regression they need to remediate. The copy points
 * merchants at WooCommerce → Status → Logs → spart-* for the failure
 * trace in case they were relying on visible pending orders to spot
 * recurring checkout failures.
 */
final class DestroyOrdersUpgradeNotice {

	/**
	 * Option key persisting whether the notice has been dismissed.
	 *
	 * Mirrors the option set by Activation::activate() for fresh installs.
	 */
	public const OPTION_NAME = 'spart_destroy_orders_upgrade_notice_dismissed';

	/**
	 * `admin_post_*` action slug for the dismiss handler.
	 *
	 * Doubles as the wp_nonce_url action — the nonce is keyed on the same
	 * string for symmetry with check_admin_referer().
	 */
	public const DISMISS_ACTION = 'spart_dismiss_destroy_orders_upgrade_notice';

	/**
	 * Register the notice render + dismiss handlers with WP.
	 *
	 * Called from Plugin::on_plugins_loaded(). Both registrations are cheap
	 * (a single add_action call each); the render itself short-circuits on
	 * non-admin or already-dismissed cases, and the admin-post handler only
	 * fires for admin-post.php requests.
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_notices', array( $this, 'render' ) );
		\add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Render the admin notice if it has not been dismissed.
	 *
	 * Short-circuits for users lacking `manage_woocommerce`: low-privilege
	 * users (subscribers, contributors) who reach an admin page like
	 * /wp-admin/profile.php would otherwise see a merchant-targeted notice
	 * they cannot dismiss, and the dismiss link would expose a valid
	 * nonce. The notice's audience is shop managers and administrators —
	 * gating on `manage_woocommerce` matches that audience and the
	 * capability enforced by handle_dismiss().
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( false !== \get_option( self::OPTION_NAME, false ) ) {
			return;
		}

		$dismiss_url = \wp_nonce_url(
			\admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ),
			self::DISMISS_ACTION
		);

		?>
		<div class="notice notice-info">
			<p>
				<strong>
					<?php \esc_html_e( 'Spart now destroys pending orders when checkout fails.', 'spart-woocommerce' ); ?>
				</strong>
			</p>
			<p>
				<?php \esc_html_e( 'When a shopper\'s Spart checkout attempt fails (network error, declined authorization, validation error, etc.), the matching pending order is now deleted and any reserved stock and applied coupons are released. Failed attempts will no longer accumulate as orphan pending orders in the orders list.', 'spart-woocommerce' ); ?>
			</p>
			<p>
				<?php \esc_html_e( 'If you previously relied on seeing failed attempts in the orders list, you can still inspect the failure trace under WooCommerce → Status → Logs (look for the spart-* log sources).', 'spart-woocommerce' ); ?>
			</p>
			<p>
				<a href="<?php echo \esc_url( $dismiss_url ); ?>" class="button">
					<?php \esc_html_e( 'Got it, dismiss', 'spart-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Persist the dismissal and redirect back to the referring admin page.
	 *
	 * Wired to `admin_post_spart_dismiss_destroy_orders_upgrade_notice`.
	 * Validates the nonce first (CSRF gate, per the WP Security handbook
	 * ordering: a missing or invalid nonce is rejected before any capability
	 * decision is exposed), then confirms the caller has `manage_woocommerce`
	 * (the WC-canonical capability for site/store configuration — matches
	 * the audience for a WC behavior-change notice and includes shop
	 * managers, who would otherwise be trapped seeing the notice forever),
	 * then sets the option to true (mirroring the fresh-install branch of
	 * Activation::activate()).
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		\check_admin_referer( self::DISMISS_ACTION );

		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			\wp_die(
				\esc_html__( 'You do not have permission to dismiss this notice.', 'spart-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		\update_option( self::OPTION_NAME, true, false );

		$referer = \wp_get_referer();
		\wp_safe_redirect( false !== $referer ? $referer : \admin_url() );
		exit;
	}
}
