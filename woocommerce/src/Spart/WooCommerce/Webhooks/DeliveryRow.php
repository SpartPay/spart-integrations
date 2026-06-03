<?php
/**
 * Webhooks\DeliveryRow — immutable row of the spart_webhook_deliveries table.
 *
 * @package Spart\WooCommerce\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Webhooks;

/**
 * Snapshot of a dedupe row used by Webhooks\DeliveryRepository.
 *
 * Property names match the database column names (snake_case) so callers
 * can hydrate via `new DeliveryRow( ...$row )` from an associative
 * `$wpdb->get_row( ARRAY_A )` result without remapping.
 */
final class DeliveryRow {

	/**
	 * Construct a DeliveryRow from raw column values.
	 *
	 * @param int         $id             Auto-increment primary key.
	 * @param string      $delivery_id    Spart-issued delivery UUID (unique key).
	 * @param string      $event_type     Event type string (e.g. 'order.completed').
	 * @param int|null    $wc_order_id    Resolved WC order id, or null when not resolvable.
	 * @param string      $state          One of 'received', 'applied', 'skipped', 'errored'.
	 * @param int         $attempt_count  Number of delivery attempts seen so far.
	 * @param string      $received_at    MySQL DATETIME of first receipt.
	 * @param string|null $applied_at     MySQL DATETIME of terminal application, or null while pending.
	 * @param string|null $error_message  Last error message (errored state only).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $delivery_id,
		public readonly string $event_type,
		public readonly ?int $wc_order_id,
		public readonly string $state,
		public readonly int $attempt_count,
		public readonly string $received_at,
		public readonly ?string $applied_at,
		public readonly ?string $error_message,
	) {
	}
}
