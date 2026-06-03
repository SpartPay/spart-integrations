<?php
/**
 * Timestamp formatter for the admin webhook deliveries surfaces.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

/**
 * Formats SQL timestamps for display in the admin webhook deliveries surfaces.
 *
 * The DeliveryRepository persists received_at and applied_at as UTC
 * `Y-m-d H:i:s` strings (see WebhookDeliveriesSchema). This helper renders
 * them consistently across the list table, detail view and per-order meta
 * box: parsed as UTC, formatted with wp_date() so the site's configured
 * timezone is honored, and rendered as an em-dash for null/empty/unparseable
 * inputs.
 *
 * Returns the raw formatted string — callers are responsible for escaping
 * (esc_html()) when emitting to HTML.
 */
final class Timestamp {

	/**
	 * Format a UTC `Y-m-d H:i:s` SQL datetime for display in the site's timezone.
	 *
	 * @param string|null $sql_datetime UTC SQL datetime string from the DB.
	 * @return string Formatted timestamp, or '—' if null/empty/unparseable.
	 */
	public static function format( ?string $sql_datetime ): string {
		if ( $sql_datetime === null || $sql_datetime === '' ) {
			return '—';
		}

		$ts = strtotime( $sql_datetime . ' UTC' );
		if ( $ts === false ) {
			return '—';
		}

		return (string) \wp_date( 'Y-m-d H:i:s', $ts );
	}
}
