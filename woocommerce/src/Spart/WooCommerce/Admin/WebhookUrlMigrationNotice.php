<?php
/**
 * Admin\WebhookUrlMigrationNotice — one-time dismissable admin notice
 * surfacing the PR3 webhook-URL change to merchants who installed PR2.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

/**
 * One-time, dismissable admin notice that prompts merchants to update
 * the webhook URL configured in their Spart merchant dashboard from the
 * legacy PR2 endpoint (`wc-api/spart_webhook`) to the canonical PR3 REST
 * endpoint (`/wp-json/spart/v1/webhook`).
 *
 * Lifecycle:
 *  - Activation::activate() persists `spart_webhook_url_migration_dismissed`
 *    = true on fresh installs so brand-new merchants never see this notice
 *    (they configure the correct URL from the start).
 *  - Existing PR2 installs get the notice on next admin page load until
 *    the merchant clicks the dismiss button — a nonce-protected GET link
 *    to admin-post.php (handle_dismiss() also enforces the manage_options
 *    capability).
 *  - Once dismissed, the option is set to true and render() short-circuits.
 */
final class WebhookUrlMigrationNotice {

	/**
	 * Option key persisting whether the notice has been dismissed.
	 *
	 * Mirrors the option set by Activation::activate() for fresh installs.
	 */
	public const OPTION_NAME = 'spart_webhook_url_migration_dismissed';

	/**
	 * `admin_post_*` action slug for the dismiss handler.
	 *
	 * Doubles as the wp_nonce_url action — the nonce is keyed on the same
	 * string for symmetry with check_admin_referer().
	 */
	public const DISMISS_ACTION = 'spart_dismiss_webhook_url_notice';

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
	 * @return void
	 */
	public function render(): void {
		if ( false !== \get_option( self::OPTION_NAME, false ) ) {
			return;
		}

		$webhook_url = \rest_url( 'spart/v1/webhook' );
		$dismiss_url = \wp_nonce_url(
			\admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ),
			self::DISMISS_ACTION
		);

		?>
		<div class="notice notice-warning">
			<p>
				<strong>
					<?php \esc_html_e( 'Spart webhook URL has changed.', 'spart-woocommerce' ); ?>
				</strong>
			</p>
			<p>
				<?php \esc_html_e( 'Please update the webhook URL in your Spart merchant dashboard to:', 'spart-woocommerce' ); ?>
			</p>
			<p><code><?php echo \esc_url( $webhook_url ); ?></code></p>
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
	 * Wired to `admin_post_spart_dismiss_webhook_url_notice`. Validates the
	 * nonce first (CSRF gate, per the WP Security handbook ordering: a missing
	 * or invalid nonce is rejected before any capability decision is exposed),
	 * then confirms the caller has `manage_options` (the WP capability for
	 * plugin/site config), then sets the option to true (mirroring the
	 * fresh-install branch of Activation::activate()).
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		\check_admin_referer( self::DISMISS_ACTION );

		if ( ! \current_user_can( 'manage_options' ) ) {
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
