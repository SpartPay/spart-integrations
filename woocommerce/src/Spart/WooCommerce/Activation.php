<?php
/**
 * Plugin activation hook handler.
 *
 * @package Spart\WooCommerce
 */

declare(strict_types=1);

namespace Spart\WooCommerce;

use Spart\WooCommerce\Checkout\SessionIdComposer;
use Spart\WooCommerce\Persistence\WebhookDeliveriesSchema;
use Spart\WooCommerce\Webhooks\CleanupCron;

/**
 * Plugin activation hook handler.
 *
 * Idempotent — `dbDelta` is the canonical WP idiom for "create table if
 * not exists, otherwise upgrade in place". Re-activating the plugin is a
 * no-op against an already-correct schema.
 */
final class Activation {

	/**
	 * Run on plugin activation.
	 *
	 * Creates (or upgrades) the Spart webhook deliveries table via dbDelta,
	 * then persists the site token used to namespace Spart session IDs.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wpdb;

		require_once \ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = WebhookDeliveriesSchema::create_table_sql(
			$wpdb->prefix,
			$wpdb->get_charset_collate()
		);

		\dbDelta( $sql );

		// Persist the site token once; never overwrite an existing value so that
		// existing Spart session IDs remain valid after re-activation.
		$existing         = \get_option( 'spart_site_token', '' );
		$is_fresh_install = '' === $existing;
		if ( $is_fresh_install ) {
			\update_option(
				'spart_site_token',
				SessionIdComposer::derive_site_token( \home_url() ),
				false
			);
		}

		// Register the daily cleanup job for the webhook deliveries table.
		// Idempotent — CleanupCron::schedule() is a no-op if a future
		// run is already scheduled, so re-activation is safe.
		CleanupCron::schedule();

		// Suppress the webhook-URL migration admin notice on fresh installs only.
		// The notice is only meaningful for sites that installed PR2 (which
		// shipped the legacy `wc-api/spart_webhook` URL); a brand-new install
		// already has the correct REST URL configured in Spart's dashboard, so
		// the merchant has nothing to migrate.
		//
		// We key the suppression on whether spart_site_token was just created
		// (the canonical first-install signal) rather than on the absence of
		// the dismissal option itself. Otherwise an existing PR2 site that is
		// deactivated and re-activated after a file-level upgrade would also
		// have the option absent, and we would wrongly suppress the notice for
		// a merchant who genuinely needs to update their dashboard URL.
		if ( $is_fresh_install
			&& false === \get_option( 'spart_webhook_url_migration_dismissed', false )
		) {
			\update_option( 'spart_webhook_url_migration_dismissed', true, false );
		}

		// Suppress the destroy-on-failure upgrade notice on fresh installs only.
		// The notice exists to inform existing merchants about a behavior
		// change in this release (failed Spart checkouts now destroy their
		// pending orders rather than leaving them visible). New merchants
		// experience the destroy behavior as the documented default — they
		// have no prior behavior to be surprised by, so the notice would be
		// pure noise. Keyed on the same first-install signal as the webhook
		// URL migration suppression above for the same reason: re-activation
		// after a file-level upgrade must still surface the notice to
		// genuinely upgrading merchants.
		if ( $is_fresh_install
			&& false === \get_option( 'spart_destroy_orders_upgrade_notice_dismissed', false )
		) {
			\update_option( 'spart_destroy_orders_upgrade_notice_dismissed', true, false );
		}
	}
}
