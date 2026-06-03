<?php
/**
 * Admin list & detail page for Spart webhook deliveries.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

use Spart\Sdk\Webhooks\EventType;
use Spart\WooCommerce\Checkout\CheckoutSession;
use Spart\WooCommerce\Webhooks\DeliveryRepository;

/**
 * WooCommerce admin submenu page that lists webhook deliveries and renders
 * the per-delivery detail view.
 *
 * Mounted under WooCommerce > Spart Webhooks. The list view delegates to
 * WebhookDeliveriesTable (a WP_List_Table). The detail view is rendered
 * inline when a `view=<delivery_id>` query arg is present.
 */
final class WebhookDeliveriesListPage {

	private const CAPABILITY      = 'manage_woocommerce';
	private const SLUG            = 'spart-webhook-deliveries';
	private const MAX_VIEW_LENGTH = 128;

	/**
	 * Construct the page with its delivery repository dependency.
	 *
	 * @param DeliveryRepository $repository Repository used to fetch deliveries for list & detail views.
	 */
	public function __construct( private readonly DeliveryRepository $repository ) {
	}

	/**
	 * Wire the WordPress hook that registers this admin submenu page.
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the WooCommerce submenu entry that mounts this page.
	 */
	public function register_menu(): void {
		\add_submenu_page(
			'woocommerce',
			\esc_html__( 'Spart Webhooks', 'spart-woocommerce' ),
			\esc_html__( 'Spart Webhooks', 'spart-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the admin page (list view, or detail view when `view=` is present).
	 */
	public function render(): void {
		if ( ! \current_user_can( self::CAPABILITY ) ) {
			\wp_die( \esc_html__( 'You do not have permission to view this page.', 'spart-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector; sanitized + length-clamped below.
		$view = isset( $_GET['view'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector; sanitized + length-clamped below.
			? \sanitize_text_field( (string) \wp_unslash( $_GET['view'] ) )
			: '';
		if ( $view !== '' && strlen( $view ) <= self::MAX_VIEW_LENGTH ) {
			$this->render_detail( $view );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the paginated list of webhook deliveries.
	 */
	private function render_list(): void {
		$table = new WebhookDeliveriesTable( $this->repository );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__( 'Spart Webhook Deliveries', 'spart-woocommerce' ) . '</h1>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static <style> literal.
		echo StateBadge::style_block();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . \esc_attr( self::SLUG ) . '" />';
		$this->render_filters();
		$table->search_box( \esc_html__( 'Search delivery ID', 'spart-woocommerce' ), 'spart-webhook-search' );
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render the state + event_type filter selects above the list table.
	 *
	 * The selected option is preserved from $_GET so the form survives
	 * pagination clicks.
	 */
	private function render_filters(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state, no state change.
		$selected_state = isset( $_GET['state'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
			? \sanitize_key( (string) \wp_unslash( $_GET['state'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state, no state change.
		$selected_event = isset( $_GET['event_type'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
			? \sanitize_text_field( (string) \wp_unslash( $_GET['event_type'] ) )
			: '';

		echo '<div class="alignleft actions">';

		echo '<label class="screen-reader-text" for="filter-by-state">'
			. \esc_html__( 'Filter by state', 'spart-woocommerce' ) . '</label>';
		echo '<select name="state" id="filter-by-state">';
		echo '<option value="">' . \esc_html__( 'All states', 'spart-woocommerce' ) . '</option>';
		foreach ( array( 'received', 'applied', 'skipped', 'errored' ) as $state ) {
			echo '<option value="' . \esc_attr( $state ) . '"'
				. \selected( $selected_state, $state, false )
				. '>' . \esc_html( $state ) . '</option>';
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="filter-by-event-type">'
			. \esc_html__( 'Filter by event type', 'spart-woocommerce' ) . '</label>';
		echo '<select name="event_type" id="filter-by-event-type">';
		echo '<option value="">' . \esc_html__( 'All event types', 'spart-woocommerce' ) . '</option>';
		foreach ( EventType::cases() as $case ) {
			echo '<option value="' . \esc_attr( $case->value ) . '"'
				. \selected( $selected_event, $case->value, false )
				. '>' . \esc_html( $case->value ) . '</option>';
		}
		echo '</select>';

		\submit_button( \__( 'Filter', 'spart-woocommerce' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Render the detail view for a single delivery, or a not-found notice.
	 *
	 * @param string $delivery_id The sanitized delivery identifier from the `view` query arg.
	 */
	private function render_detail( string $delivery_id ): void {
		$row = $this->repository->find( $delivery_id );

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__( 'Webhook Delivery', 'spart-woocommerce' ) . '</h1>';

		$list_url = \admin_url( 'admin.php?page=' . self::SLUG );
		echo '<p><a href="' . \esc_url( $list_url ) . '">&larr; '
			. \esc_html__( 'Back to deliveries', 'spart-woocommerce' )
			. '</a></p>';

		if ( $row === null ) {
			echo '<div class="notice notice-error"><p>'
				. \esc_html__( 'Delivery not found.', 'spart-woocommerce' )
				. '</p></div></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static <style> literal.
		echo StateBadge::style_block();
		echo '<table class="form-table"><tbody>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns pre-escaped HTML.
		echo $this->render_field( \esc_html__( 'Delivery ID', 'spart-woocommerce' ), \esc_html( $row->delivery_id ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns pre-escaped HTML.
		echo $this->render_field( \esc_html__( 'Event type', 'spart-woocommerce' ), \esc_html( $row->event_type ) );
		$order_value = $row->wc_order_id === null ? '—' : $this->order_edit_link( $row->wc_order_id );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns pre-escaped HTML; order_edit_link() pre-escapes its output and '—' is a static literal.
		echo $this->render_field( \esc_html__( 'Order', 'spart-woocommerce' ), $order_value );

		$intent_short_id = $this->lookup_intent_short_id( $row->wc_order_id );
		if ( $intent_short_id !== '' ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns pre-escaped HTML.
			echo $this->render_field(
				\esc_html__( 'Intent short ID', 'spart-woocommerce' ),
				\esc_html( $intent_short_id )
			);
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StateBadge::markup() returns pre-escaped HTML; render_field() preserves escaping.
		echo $this->render_field( \esc_html__( 'State', 'spart-woocommerce' ), StateBadge::markup( $row->state ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns pre-escaped HTML.
		echo $this->render_field( \esc_html__( 'Attempts', 'spart-woocommerce' ), \esc_html( (string) $row->attempt_count ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns pre-escaped HTML.
		echo $this->render_field( \esc_html__( 'Received at', 'spart-woocommerce' ), \esc_html( Timestamp::format( $row->received_at ) ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns pre-escaped HTML.
		echo $this->render_field( \esc_html__( 'Applied at', 'spart-woocommerce' ), \esc_html( Timestamp::format( $row->applied_at ) ) );
		$error_value = ( $row->error_message === null || $row->error_message === '' )
			? '—'
			: \esc_html( (string) $row->error_message );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() returns pre-escaped HTML; $error_value is the em-dash literal or esc_html() output.
		echo $this->render_field( \esc_html__( 'Error', 'spart-woocommerce' ), $error_value );
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Best-effort lookup of the Spart Intent short ID for the order.
	 *
	 * @param int|null $wc_order_id WooCommerce order ID, or null.
	 * @return string The intent short ID, or empty string if unavailable.
	 */
	private function lookup_intent_short_id( ?int $wc_order_id ): string {
		if ( $wc_order_id === null || ! \function_exists( '\\wc_get_order' ) ) {
			return '';
		}
		$order = \wc_get_order( $wc_order_id );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return '';
		}
		return (string) $order->get_meta( CheckoutSession::META_INTENT_SHORT_ID, true );
	}

	/**
	 * Build an HPOS-aware edit link for a WooCommerce order.
	 *
	 * Mirrors WebhookDeliveriesTable::column_wc_order_id so the detail view's
	 * Order field is consistent with the list table's Order column.
	 *
	 * @param int $wc_order_id WooCommerce order ID (caller guarantees non-null).
	 * @return string Pre-escaped HTML anchor.
	 */
	private function order_edit_link( int $wc_order_id ): string {
		if ( \class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ) {
			$url = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $wc_order_id );
		} else {
			$url = \admin_url( 'post.php?action=edit&post=' . $wc_order_id );
		}

		return sprintf(
			'<a href="%s">#%s</a>',
			\esc_url( $url ),
			\esc_html( (string) $wc_order_id )
		);
	}

	/**
	 * Build a single <tr><th>label</th><td>value</td></tr> row.
	 *
	 * Both args MUST be pre-escaped by the caller — this helper does not
	 * apply additional escaping (because callers sometimes pass
	 * StateBadge::markup(), which is HTML, not text).
	 *
	 * @param string $label Pre-escaped row label.
	 * @param string $value Pre-escaped row value (text or markup).
	 * @return string The pre-escaped HTML row markup.
	 */
	private function render_field( string $label, string $value ): string {
		return sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', $label, $value );
	}
}
