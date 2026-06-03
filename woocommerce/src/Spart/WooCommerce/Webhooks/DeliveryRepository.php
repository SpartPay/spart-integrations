<?php
/**
 * Webhooks\DeliveryRepository — $wpdb wrapper for the dedupe table.
 *
 * @package Spart\WooCommerce\Webhooks
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Webhooks;

use Spart\WooCommerce\Persistence\WebhookDeliveriesSchema;

/**
 * Persists and queries rows in `{prefix}spart_webhook_deliveries`.
 *
 * Every SQL statement goes through `$wpdb->prepare()`. The class hides the
 * dedupe table's column layout from `WebhookReceiver` and surfaces a small,
 * intent-revealing API (find / insert_received / increment_attempt /
 * claim_for_retry / mark_applied / mark_skipped / mark_errored /
 * cleanup_older_than).
 *
 * The unique key `uk_delivery_id` is the safety net for concurrent
 * deliveries of the same `delivery_id`; `insert_received()` swallows the
 * duplicate-key error and re-reads the row so the caller can proceed
 * along the "row already exists" branch. `claim_for_retry()` is the
 * apply-time TOCTOU close: when two receivers reach the existing-row
 * branch with the same delivery_id, only one wins the atomic UPDATE
 * (the other sees rows_affected=0 and short-circuits with 200 deduped).
 */
class DeliveryRepository {

	/**
	 * Construct with a $wpdb-compatible handle.
	 *
	 * @param \wpdb $wpdb The WordPress database access object.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {
	}

	/**
	 * Look up a delivery row by its Spart-issued delivery id.
	 *
	 * @param string $delivery_id Unique Spart delivery id.
	 * @return DeliveryRow|null Hydrated row, or null when no row exists.
	 */
	public function find( string $delivery_id ): ?DeliveryRow {
		$table = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );
		$sql   = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- table name is built from a constant + $wpdb->prefix; delivery_id is bound via prepare().
			"SELECT * FROM {$table} WHERE delivery_id = %s LIMIT 1",
			$delivery_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- this IS the dedupe lookup; caching would defeat the purpose.
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Insert a fresh `received` row.
	 *
	 * On unique-index race (concurrent insert of same delivery_id) the
	 * duplicate-key error is absorbed and we re-read the row to confirm
	 * the winning insert succeeded. The wpdb error output is suppressed
	 * around the insert because the race is an expected, handled
	 * condition — not a programming error to log via wpdb::print_error.
	 * If even the re-read finds nothing, the database is in an
	 * unexpected state and we surface the original error to the caller.
	 *
	 * @param string   $delivery_id Unique Spart delivery id.
	 * @param string   $event_type  Event type string (e.g. 'order.completed').
	 * @param int|null $wc_order_id Resolved WC order id, or null when unresolvable.
	 * @return bool True when we inserted the row; false when a concurrent
	 *              receiver beat us to the unique index and the row now
	 *              exists. Callers should treat false as "another worker
	 *              owns this delivery" and not enter the apply path.
	 * @throws \RuntimeException When the insert fails AND a follow-up
	 *                           find() still does not see the row (real
	 *                           database error, not a race).
	 */
	public function insert_received( string $delivery_id, string $event_type, ?int $wc_order_id ): bool {
		$table   = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );
		$data    = array(
			'delivery_id'   => $delivery_id,
			'event_type'    => $event_type,
			'wc_order_id'   => $wc_order_id,
			'state'         => 'received',
			'attempt_count' => 1,
			'received_at'   => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%d', '%s' );

		// Silence wpdb::print_error() around the insert so the legitimate
		// race-loss path doesn't write a noisy "Duplicate entry ..." line
		// to error_log when WP_DEBUG_LOG is on. $wpdb->last_error is
		// still populated regardless of suppression and is used below.
		$prev_suppress = $this->wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- writing to the dedupe table.
			$result = $this->wpdb->insert( $table, $data, $formats );
			$error  = (string) $this->wpdb->last_error;
		} finally {
			$this->wpdb->suppress_errors( $prev_suppress );
		}

		if ( false !== $result ) {
			return true;
		}

		// Insert failed. Most likely cause: another concurrent receiver
		// inserted the same delivery_id first (unique-key race). Re-read
		// to confirm — if the row exists, this worker lost the race.
		if ( null !== $this->find( $delivery_id ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message is internal; not rendered to output.
		throw new \RuntimeException(
			sprintf(
				'DeliveryRepository::insert_received failed for delivery_id %s: %s',
				$delivery_id, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				$error // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			)
		);
	}

	/**
	 * Bump `attempt_count` by 1 for an existing row.
	 *
	 * Used on the sequential retry path (dispatcher-driven, attempt>1):
	 * the dispatcher's retry intervals (minutes) are orders of magnitude
	 * larger than any apply-time race window, so we trust the signed
	 * attempt header and bump the counter without further interlocking.
	 *
	 * For the concurrent attempt=1 race window, use
	 * {@see DeliveryRepository::claim_for_retry()} instead — it is the
	 * atomic, idle-threshold-guarded variant that short-circuits when
	 * another worker is mid-apply.
	 *
	 * @param string $delivery_id Unique Spart delivery id.
	 */
	public function increment_attempt( string $delivery_id ): void {
		$table = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );
		$sql   = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from constant; delivery_id bound via prepare().
			"UPDATE {$table} SET attempt_count = attempt_count + 1 WHERE delivery_id = %s",
			$delivery_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- writing to the dedupe table.
		$this->wpdb->query( $sql );
	}

	/**
	 * Atomically claim a `received` row for retry, but only if it has
	 * been idle longer than $max_idle_seconds.
	 *
	 * This is the apply-time TOCTOU close for issue #227: when two
	 * receivers race with the same delivery_id and both arrive at the
	 * existing-row branch (one inserted, the other observed a row in
	 * state='received'), the second one must NOT proceed to apply while
	 * the first is mid-apply — otherwise both would call
	 * payment_complete() and append duplicate order notes.
	 *
	 * The UPDATE is a single atomic statement guarded by `received_at
	 * < cutoff` (UTC, gmdate). Within the idle window the WHERE clause
	 * matches no rows → false. Outside the window (legitimate crashed-
	 * worker recovery — the first worker died before transitioning the
	 * row) the UPDATE bumps attempt_count, refreshes received_at, and
	 * returns true. Caller proceeds to apply.
	 *
	 * Concurrency note: because `received` is the only state matched,
	 * once the first claimer's UPDATE commits and the apply path
	 * eventually calls mark_applied()/mark_skipped()/mark_errored(), a
	 * subsequent claim_for_retry() against that delivery_id will also
	 * miss (state != 'received') and return false. The terminal-state
	 * short-circuit at the top of {@see WebhookReceiver::handle()}
	 * catches that case first anyway; this is just defence in depth.
	 *
	 * Pathological edge case NOT covered: a worker that takes longer
	 * than $max_idle_seconds to apply without crashing. That would
	 * allow a second claimer through. In practice apply finishes in
	 * tens of milliseconds and PHP-FPM defaults kill requests well
	 * before 30s anyway, so the protection holds.
	 *
	 * @param string $delivery_id     Unique Spart delivery id.
	 * @param int    $max_idle_seconds Minimum age the row must have
	 *                                 (received_at older than NOW - this)
	 *                                 to be claimable. Use a value larger
	 *                                 than the typical apply duration.
	 * @return bool True when exactly one row was updated (claim
	 *              successful); false when no row matched (row too
	 *              fresh, missing, or already in a non-received state).
	 */
	public function claim_for_retry( string $delivery_id, int $max_idle_seconds ): bool {
		$table  = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $max_idle_seconds );
		$sql    = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from constant; values bound via prepare().
			"UPDATE {$table}
			 SET attempt_count = attempt_count + 1, received_at = %s
			 WHERE delivery_id = %s AND state = 'received' AND received_at < %s",
			$now,
			$delivery_id,
			$cutoff
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- writing to the dedupe table.
		$result = $this->wpdb->query( $sql );

		return false !== $result && 1 === (int) $result;
	}

	/**
	 * Transition a row to `applied` and stamp `applied_at`.
	 *
	 * @param string   $delivery_id Unique Spart delivery id.
	 * @param int|null $wc_order_id Optional WC order id to backfill (e.g. when only resolved on this attempt).
	 */
	public function mark_applied( string $delivery_id, ?int $wc_order_id = null ): void {
		$table = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );

		$data    = array(
			'state'      => 'applied',
			'applied_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%s', '%s' );

		if ( null !== $wc_order_id ) {
			$data['wc_order_id'] = $wc_order_id;
			$formats[]           = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- writing to the dedupe table.
		$this->wpdb->update(
			$table,
			$data,
			array( 'delivery_id' => $delivery_id ),
			$formats,
			array( '%s' )
		);
	}

	/**
	 * Transition a row to `skipped` with a recorded reason.
	 *
	 * The reason is stored in `error_message` (the only free-text column
	 * available); the `state` column makes it unambiguous that the row
	 * is a deliberate skip rather than a failure.
	 *
	 * @param string $delivery_id Unique Spart delivery id.
	 * @param string $reason      Machine-readable skip reason (e.g. ResolverResult constant).
	 */
	public function mark_skipped( string $delivery_id, string $reason ): void {
		$table = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- writing to the dedupe table.
		$this->wpdb->update(
			$table,
			array(
				'state'         => 'skipped',
				'applied_at'    => gmdate( 'Y-m-d H:i:s' ),
				'error_message' => $reason,
			),
			array( 'delivery_id' => $delivery_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Transition a row to `errored` and record the error message.
	 *
	 * @param string $delivery_id Unique Spart delivery id.
	 * @param string $message     Sanitized error message (no PII).
	 */
	public function mark_errored( string $delivery_id, string $message ): void {
		$table = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- writing to the dedupe table.
		$this->wpdb->update(
			$table,
			array(
				'state'         => 'errored',
				'error_message' => $message,
			),
			array( 'delivery_id' => $delivery_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Delete rows whose `received_at` is older than $days days.
	 *
	 * @param int $days Retention window in days.
	 * @return int Number of rows deleted (0 when none matched).
	 */
	public function cleanup_older_than( int $days ): int {
		$table  = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$sql    = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from constant; cutoff bound via prepare().
			"DELETE FROM {$table} WHERE received_at < %s",
			$cutoff
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- writing to the dedupe table.
		$result = $this->wpdb->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Return deliveries for a single WooCommerce order, newest first.
	 *
	 * @param int $wc_order_id WooCommerce order ID.
	 * @param int $limit       Maximum rows to return; clamped to [1, 200].
	 * @return DeliveryRow[]
	 */
	public function list_for_order( int $wc_order_id, int $limit = 50 ): array {
		$limit = max( 1, min( 200, $limit ) );
		$table = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe, constructed internally; values bound via prepare().
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$table} WHERE wc_order_id = %d ORDER BY received_at DESC LIMIT %d",
			$wc_order_id,
			$limit
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reading deliveries for an order.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		return array_map( fn( $row ) => $this->hydrate( $row ), $rows );
	}

	/**
	 * Count deliveries matching admin list-page filters.
	 *
	 * @param array{state?: string, event_type?: string, search?: string} $filters Filter spec from the admin list page.
	 */
	public function count_for_admin( array $filters ): int {
		$table = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );
		$where = $this->build_where_clause( $filters );
		$sql   = "SELECT COUNT(*) FROM {$table}" . $where['sql'];

		// Skip wpdb::prepare() when there are no placeholders to bind:
		// since WP 5.3, prepare() emits `_doing_it_wrong` and (on WP 6.2+)
		// may return an empty string when called with no args, which would
		// zero out pagination on the default no-filter list view.
		if ( empty( $where['args'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from constant + $wpdb->prefix; SQL has no user input or placeholders; read-only admin query, no caching needed.
			return (int) $this->wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- table name is built from a constant + $wpdb->prefix; values bound via prepare()
		$prepared = $this->wpdb->prepare( $sql, $where['args'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $prepared already built by $wpdb->prepare() above; read-only admin query, no caching needed
		return (int) $this->wpdb->get_var( $prepared );
	}

	/**
	 * List deliveries for the admin list page with pagination, filtering, sorting.
	 *
	 * @param int                                                         $page       1-based.
	 * @param int                                                         $per_page   clamped to [1, 200].
	 * @param array{state?: string, event_type?: string, search?: string} $filters    Filter spec from the admin list page.
	 * @param string                                                      $orderby    Only 'received_at' is accepted; anything else falls back to it.
	 * @param string                                                      $order      'ASC' or 'DESC'; anything else falls back to DESC.
	 *
	 * @return DeliveryRow[]
	 */
	public function list_for_admin(
		int $page,
		int $per_page,
		array $filters,
		string $orderby = 'received_at',
		string $order = 'DESC'
	): array {
		$allowed_orderby = array( 'received_at' );
		$allowed_order   = array( 'ASC', 'DESC' );

		$orderby  = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'received_at';
		$order    = in_array( strtoupper( $order ), $allowed_order, true ) ? strtoupper( $order ) : 'DESC';
		$per_page = max( 1, min( 200, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		$table = WebhookDeliveriesSchema::table_name( $this->wpdb->prefix );
		$where = $this->build_where_clause( $filters );

		$sql = "SELECT * FROM {$table}"
			. $where['sql']
			. " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$args = array_merge( $where['args'], array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- table/orderby/order are validated against fixed whitelists; values bound via prepare()
		$prepared = $this->wpdb->prepare( $sql, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $prepared already built by $wpdb->prepare() above; read-only admin query, no caching needed
		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		return array_map( fn( array $row ): DeliveryRow => $this->hydrate( $row ), $rows );
	}

	/**
	 * Hydrate a DeliveryRow from an associative `$wpdb->get_row` result.
	 *
	 * @param array<string, mixed> $row Raw column values keyed by column name.
	 */
	private function hydrate( array $row ): DeliveryRow {
		return new DeliveryRow(
			id:             (int) $row['id'],
			delivery_id:    (string) $row['delivery_id'],
			event_type:     (string) $row['event_type'],
			wc_order_id:    null === $row['wc_order_id'] ? null : (int) $row['wc_order_id'],
			state:          (string) $row['state'],
			attempt_count:  (int) $row['attempt_count'],
			received_at:    (string) $row['received_at'],
			applied_at:     null === $row['applied_at'] ? null : (string) $row['applied_at'],
			error_message:  null === $row['error_message'] ? null : (string) $row['error_message'],
		);
	}

	/**
	 * Build a WHERE clause and bound args array from admin filter params.
	 *
	 * @param array{state?: string, event_type?: string, search?: string} $filters Filter spec from the admin list page.
	 * @return array{sql: string, args: list<string>}
	 */
	private function build_where_clause( array $filters ): array {
		$valid_states = array( 'received', 'applied', 'skipped', 'errored' );
		$conditions   = array();
		$args         = array();

		if ( ! empty( $filters['state'] ) && in_array( $filters['state'], $valid_states, true ) ) {
			$conditions[] = 'state = %s';
			$args[]       = $filters['state'];
		}

		if ( ! empty( $filters['event_type'] ) ) {
			$conditions[] = 'event_type = %s';
			$args[]       = $filters['event_type'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$conditions[] = 'delivery_id LIKE %s';
			$args[]       = $this->wpdb->esc_like( $filters['search'] ) . '%';
		}

		$sql = empty( $conditions ) ? '' : ' WHERE ' . implode( ' AND ', $conditions );

		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}
}
