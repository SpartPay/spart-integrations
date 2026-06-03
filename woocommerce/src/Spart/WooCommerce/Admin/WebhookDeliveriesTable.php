<?php
/**
 * WP_List_Table for Spart webhook deliveries.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

use Spart\WooCommerce\Webhooks\DeliveryRepository;
use Spart\WooCommerce\Webhooks\DeliveryRow;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table subclass rendering the webhook deliveries admin list.
 *
 * Column-rendering methods are public to make them unit-testable; data
 * fetching is delegated to DeliveryRepository::list_for_admin /
 * count_for_admin via prepare_items().
 */
final class WebhookDeliveriesTable extends \WP_List_Table {

	private const PER_PAGE              = 20;
	private const CAPABILITY            = 'manage_woocommerce';
	private const MAX_EVENT_TYPE_LENGTH = 64;
	private const MAX_SEARCH_LENGTH     = 128;

	/**
	 * Repository used to fetch deliveries; null when the table is constructed
	 * without one (e.g. by core list-table machinery probing column metadata).
	 *
	 * @var DeliveryRepository|null
	 */
	private ?DeliveryRepository $repository;

	/**
	 * Construct the list table.
	 *
	 * @param DeliveryRepository|null $repository Optional repository for data fetching.
	 */
	public function __construct( ?DeliveryRepository $repository = null ) {
		parent::__construct(
			array(
				'singular' => 'spart_webhook_delivery',
				'plural'   => 'spart_webhook_deliveries',
				'ajax'     => false,
			)
		);
		$this->repository = $repository;
	}

	/**
	 * Return the column id => header label map.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'delivery_id'   => \esc_html__( 'Delivery ID', 'spart-woocommerce' ),
			'event_type'    => \esc_html__( 'Event type', 'spart-woocommerce' ),
			'wc_order_id'   => \esc_html__( 'Order', 'spart-woocommerce' ),
			'state'         => \esc_html__( 'State', 'spart-woocommerce' ),
			'attempt_count' => \esc_html__( 'Attempts', 'spart-woocommerce' ),
			'received_at'   => \esc_html__( 'Received at', 'spart-woocommerce' ),
			'error_message' => \esc_html__( 'Error', 'spart-woocommerce' ),
		);
	}

	/**
	 * Return the WP_List_Table sortable-columns spec.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array( 'received_at' => array( 'received_at', true ) );
	}

	/**
	 * Populate $items from the repository, applying pagination/sort/filters
	 * from sanitized $_GET parameters.
	 *
	 * WP_List_Table list-page query args (paged/orderby/order, plus the
	 * page's own filter inputs) are read directly from $_GET; nonce checks
	 * are not applicable here because none of these are state-changing
	 * actions — they are URL-driven view selectors. Each value is
	 * individually sanitized (absint / sanitize_key / sanitize_text_field).
	 */
	public function prepare_items(): void {
		if ( $this->repository === null ) {
			$this->items = array();
			return;
		}

		if ( ! \current_user_can( self::CAPABILITY ) ) {
			$this->items = array();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table pagination is GET-driven, not a state change.
		$page = isset( $_GET['paged'] ) ? max( 1, \absint( \wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table sort is GET-driven, not a state change.
		$orderby = isset( $_GET['orderby'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table sort is GET-driven, not a state change.
			? \sanitize_key( (string) \wp_unslash( $_GET['orderby'] ) )
			: 'received_at';
		$orderby = $orderby !== '' ? $orderby : 'received_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table sort is GET-driven, not a state change.
		$order = isset( $_GET['order'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table sort is GET-driven, not a state change.
			? strtoupper( \sanitize_key( (string) \wp_unslash( $_GET['order'] ) ) )
			: 'DESC';
		$order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table filter is GET-driven, not a state change.
		$state = isset( $_GET['state'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table filter is GET-driven, not a state change.
			? \sanitize_key( (string) \wp_unslash( $_GET['state'] ) )
			: '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table filter is GET-driven, not a state change.
		$event_type = isset( $_GET['event_type'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table filter is GET-driven, not a state change.
			? \sanitize_text_field( (string) \wp_unslash( $_GET['event_type'] ) )
			: '';
		if ( strlen( $event_type ) > self::MAX_EVENT_TYPE_LENGTH ) {
			$event_type = '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table search is GET-driven, not a state change.
		$search = isset( $_GET['s'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list-table search is GET-driven, not a state change.
			? \sanitize_text_field( (string) \wp_unslash( $_GET['s'] ) )
			: '';
		$has_mbstring  = \function_exists( 'mb_strlen' ) && \function_exists( 'mb_substr' );
		$search_length = $has_mbstring ? mb_strlen( $search, 'UTF-8' ) : strlen( $search );
		if ( $search_length > self::MAX_SEARCH_LENGTH ) {
			$search = $has_mbstring
				? mb_substr( $search, 0, self::MAX_SEARCH_LENGTH, 'UTF-8' )
				: substr( $search, 0, self::MAX_SEARCH_LENGTH );
		}

		$filters = array(
			'state'      => $state,
			'event_type' => $event_type,
			'search'     => $search,
		);

		$this->items = $this->repository->list_for_admin( $page, self::PER_PAGE, $filters, $orderby, $order );
		$total       = $this->repository->count_for_admin( $filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Render the `delivery_id` column as a link to the detail view.
	 *
	 * @param DeliveryRow $row Row whose column is being rendered.
	 * @return string Pre-escaped HTML.
	 */
	public function column_delivery_id( DeliveryRow $row ): string {
		$url = \admin_url( 'admin.php?page=spart-webhook-deliveries&view=' . rawurlencode( $row->delivery_id ) );
		return sprintf(
			'<a href="%s">%s</a>',
			\esc_url( $url ),
			\esc_html( $row->delivery_id )
		);
	}

	/**
	 * Render the `event_type` column.
	 *
	 * @param DeliveryRow $row Row whose column is being rendered.
	 * @return string Pre-escaped text.
	 */
	public function column_event_type( DeliveryRow $row ): string {
		return \esc_html( $row->event_type );
	}

	/**
	 * Render the `wc_order_id` column as a link to the order edit screen.
	 *
	 * HPOS-aware: uses OrderUtil::get_order_admin_edit_url when WC's
	 * order-utility class is present, falling back to the legacy
	 * post.php?action=edit URL otherwise.
	 *
	 * @param DeliveryRow $row Row whose column is being rendered.
	 * @return string Pre-escaped HTML, or em-dash for no order.
	 */
	public function column_wc_order_id( DeliveryRow $row ): string {
		if ( $row->wc_order_id === null ) {
			return '—';
		}

		if ( \class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ) {
			$url = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $row->wc_order_id );
		} else {
			$url = \admin_url( 'post.php?action=edit&post=' . $row->wc_order_id );
		}

		return sprintf(
			'<a href="%s">#%s</a>',
			\esc_url( $url ),
			\esc_html( (string) $row->wc_order_id )
		);
	}

	/**
	 * Render the `state` column as a StateBadge.
	 *
	 * @param DeliveryRow $row Row whose column is being rendered.
	 * @return string Pre-escaped HTML.
	 */
	public function column_state( DeliveryRow $row ): string {
		return StateBadge::markup( $row->state );
	}

	/**
	 * Render the `attempt_count` column.
	 *
	 * @param DeliveryRow $row Row whose column is being rendered.
	 * @return string Pre-escaped text.
	 */
	public function column_attempt_count( DeliveryRow $row ): string {
		return \esc_html( (string) $row->attempt_count );
	}

	/**
	 * Render the `received_at` column.
	 *
	 * @param DeliveryRow $row Row whose column is being rendered.
	 * @return string Pre-escaped text.
	 */
	public function column_received_at( DeliveryRow $row ): string {
		return \esc_html( Timestamp::format( $row->received_at ) );
	}

	/**
	 * Render the `error_message` column, truncated to 80 chars.
	 *
	 * @param DeliveryRow $row Row whose column is being rendered.
	 * @return string Pre-escaped text, or em-dash for null/empty.
	 */
	public function column_error_message( DeliveryRow $row ): string {
		if ( $row->error_message === null || $row->error_message === '' ) {
			return '—';
		}
		$msg          = (string) $row->error_message;
		$has_mbstring = \function_exists( 'mb_strlen' ) && \function_exists( 'mb_substr' );
		$len          = $has_mbstring ? mb_strlen( $msg, 'UTF-8' ) : strlen( $msg );
		if ( $len > 80 ) {
			$msg  = $has_mbstring ? mb_substr( $msg, 0, 80, 'UTF-8' ) : substr( $msg, 0, 80 );
			$msg .= '…';
		}
		return \esc_html( $msg );
	}

	/**
	 * Default column renderer (returns empty string; explicit per-column
	 * methods above handle every column in get_columns()).
	 *
	 * @param DeliveryRow $item        Row whose column is being rendered.
	 * @param string      $column_name Column id from get_columns().
	 * @return string Empty string.
	 */
	public function column_default( $item, $column_name ): string {
		return '';
	}
}
