<?php
/**
 * Per-order meta box listing the Spart payees (payment parts) for an order.
 *
 * @package Spart\WooCommerce\Admin
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Admin;

use Spart\WooCommerce\Gateway\WC_Gateway_Spart;
use Spart\WooCommerce\Webhooks\OrderSync;

/**
 * Renders a per-order meta box listing the redacted payees (payment parts)
 * Spart reported for that order. The data is sourced entirely from the
 * {@see OrderSync::META_PAYMENT_PARTS} order-meta snapshot, which is written
 * by the webhook handler. Payee name/email are masked upstream — this box
 * never has access to, nor renders, real PII.
 *
 * HPOS-aware: registers on both the legacy `shop_order` post screen and the
 * HPOS `wc-orders` admin page screen. Rendering is defensive — corrupt or
 * missing meta degrades to an empty-state message, never a fatal.
 */
final class OrderPayeesMetaBox {

	private const META_BOX_ID = 'spart_payees';
	private const CAPABILITY  = 'edit_shop_orders';

	/**
	 * Wire the WordPress hook that registers this meta box.
	 */
	public function register(): void {
		\add_action( 'add_meta_boxes', array( $this, 'maybe_add' ), 10, 2 );
	}

	/**
	 * Conditionally register the meta box for the current order screen.
	 *
	 * @param string $screen_id Either 'shop_order' (legacy posts) or the HPOS screen ID.
	 * @param mixed  $order     Either a WP_Post (legacy) or a WC_Order (HPOS).
	 */
	public function maybe_add( string $screen_id, $order ): void {
		$allowed = array( 'shop_order' );
		if ( \function_exists( '\\wc_get_page_screen_id' ) ) {
			$hpos_screen = \wc_get_page_screen_id( 'shop-order' );
			if ( is_string( $hpos_screen ) && $hpos_screen !== '' ) {
				$allowed[] = $hpos_screen;
			}
		}
		if ( ! in_array( $screen_id, $allowed, true ) ) {
			return;
		}

		if ( ! \function_exists( '\\wc_get_order' ) ) {
			return;
		}

		$order = \wc_get_order( $order );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}

		if ( $order->get_payment_method() !== WC_Gateway_Spart::GATEWAY_ID ) {
			return;
		}

		\add_meta_box(
			self::META_BOX_ID,
			\esc_html__( 'Spart payees', 'spart-woocommerce' ),
			array( $this, 'render' ),
			$screen_id,
			'normal',
			'default'
		);
	}

	/**
	 * Render the meta box content for a given order.
	 *
	 * @param mixed $order Either a WP_Post (legacy) or a WC_Order (HPOS).
	 */
	public function render( $order ): void {
		if ( ! \current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! \function_exists( '\\wc_get_order' ) ) {
			return;
		}

		$order = \wc_get_order( $order );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}

		$parts = $this->read_parts( $order );

		if ( $parts === array() ) {
			echo '<p>' . \esc_html__( 'No payees for this order.', 'spart-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . \esc_html__( 'Payee', 'spart-woocommerce' ) . '</th>';
		echo '<th>' . \esc_html__( 'Status', 'spart-woocommerce' ) . '</th>';
		echo '<th>' . \esc_html__( 'Gross', 'spart-woocommerce' ) . '</th>';
		echo '<th>' . \esc_html__( 'Net', 'spart-woocommerce' ) . '</th>';
		echo '<th>' . \esc_html__( 'Fees', 'spart-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $parts as $part ) {
			echo '<tr>';
			echo '<td>' . \esc_html( $this->payee_label( $part ) ) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status_html() returns a fixed-palette inline-styled span; its label is esc_html__()/esc_html()-escaped and colours are esc_attr()-escaped literals.
			echo '<td>' . $this->status_html( $part ) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- money() returns wc_price() output (pre-escaped HTML).
			echo '<td>' . $this->money( $part, 'total' ) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- money() returns wc_price() output (pre-escaped HTML).
			echo '<td>' . $this->money( $part, 'net' ) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fees() returns esc_html()-escaped fee names concatenated with wc_price() HTML, joined by <br>; all fragments are individually escaped.
			echo '<td>' . $this->fees( $part ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Read and validate the payment-parts snapshot from order meta.
	 *
	 * Accepts the versioned document written by current writers
	 * (`{"v":1,"parts":[...]}`) as well as a legacy bare list of parts, so an
	 * order persisted before the version wrapper landed still renders. Any
	 * decode failure or malformed shape degrades to an empty list so the
	 * admin page never fatals on corrupt data.
	 *
	 * @param \WC_Order $order The order being rendered.
	 * @return array<int, array<string, mixed>>
	 */
	private function read_parts( \WC_Order $order ): array {
		$raw = $order->get_meta( OrderSync::META_PAYMENT_PARTS, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		if ( ! array_is_list( $decoded ) ) {
			$decoded = isset( $decoded['parts'] ) && is_array( $decoded['parts'] ) ? $decoded['parts'] : array();
			if ( ! array_is_list( $decoded ) ) {
				return array();
			}
		}

		$parts = array();
		foreach ( $decoded as $entry ) {
			if ( is_array( $entry ) && array_is_list( $entry ) === false ) {
				$parts[] = $entry;
			}
		}

		return $parts;
	}

	/**
	 * Build the payee label from the masked name, falling back to a dash. The
	 * payee email is never stored, so there is no email fallback.
	 *
	 * @param array<string, mixed> $part A single payment-part entry.
	 */
	private function payee_label( array $part ): string {
		$name = $this->string_field( $part, 'payeeName' );
		return $name !== '' ? $name : '—';
	}

	/**
	 * Render a merchant-friendly status pill for a payment part.
	 *
	 * The wire status vocabulary (`none`/`authorized`/`captured`/`released`)
	 * is collapsed into the three states a merchant cares about:
	 *  - `none`                     → Pending  (neutral grey)
	 *  - `authorized` | `captured`  → Paid     (green)
	 *  - `released`                 → Canceled (amber)
	 * An unrecognised value falls back to the raw status in a neutral pill so
	 * a future backend state is still surfaced rather than hidden.
	 *
	 * @param array<string, mixed> $part A single payment-part entry.
	 */
	private function status_html( array $part ): string {
		$raw = $this->string_field( $part, 'status' );

		switch ( strtolower( $raw ) ) {
			case '':
			case 'none':
				$label = \esc_html__( 'Pending', 'spart-woocommerce' );
				$bg    = '#f0f0f1';
				$fg    = '#3c434a';
				break;
			case 'authorized':
			case 'captured':
				$label = \esc_html__( 'Paid', 'spart-woocommerce' );
				$bg    = '#edfaef';
				$fg    = '#00650f';
				break;
			case 'released':
				$label = \esc_html__( 'Canceled', 'spart-woocommerce' );
				$bg    = '#fcf3e5';
				$fg    = '#8a5700';
				break;
			default:
				$label = \esc_html( $raw );
				$bg    = '#f0f0f1';
				$fg    = '#3c434a';
				break;
		}//end switch

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;background:%s;color:%s;">%s</span>',
			\esc_attr( $bg ),
			\esc_attr( $fg ),
			$label
		);
	}

	/**
	 * Safely read a string field from a part entry.
	 *
	 * @param array<string, mixed> $part A single payment-part entry.
	 * @param string               $key  Field name.
	 */
	private function string_field( array $part, string $key ): string {
		$value = $part[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Render a money sub-object (`amount`/`currency`) via wc_price.
	 *
	 * @param array<string, mixed> $part A single payment-part entry.
	 * @param string               $key  Either 'net' or 'total'.
	 */
	private function money( array $part, string $key ): string {
		$money = $part[ $key ] ?? null;
		if ( ! is_array( $money ) || ! isset( $money['amount'] ) || ! is_numeric( $money['amount'] ) ) {
			return \esc_html( '—' );
		}
		$currency = isset( $money['currency'] ) && is_string( $money['currency'] ) ? $money['currency'] : '';
		return \wc_price( (float) $money['amount'], array( 'currency' => $currency ) );
	}

	/**
	 * Render the fee breakdown as `name: amount` fragments.
	 *
	 * @param array<string, mixed> $part A single payment-part entry.
	 */
	private function fees( array $part ): string {
		$fees = $part['fees'] ?? null;
		if ( ! is_array( $fees ) || $fees === array() ) {
			return \esc_html( '—' );
		}

		$currency  = $this->currency_for( $part );
		$fragments = array();
		foreach ( $fees as $name => $amount ) {
			if ( ! is_numeric( $amount ) ) {
				continue;
			}
			$fragments[] = \esc_html( (string) $name ) . ': '
				. \wc_price( (float) $amount, array( 'currency' => $currency ) );
		}

		return $fragments === array() ? \esc_html( '—' ) : implode( '<br>', $fragments );
	}

	/**
	 * Resolve the currency for a part from its total then net sub-objects.
	 *
	 * @param array<string, mixed> $part A single payment-part entry.
	 */
	private function currency_for( array $part ): string {
		foreach ( array( 'total', 'net' ) as $key ) {
			$money = $part[ $key ] ?? null;
			if ( is_array( $money ) && isset( $money['currency'] ) && is_string( $money['currency'] ) ) {
				return $money['currency'];
			}
		}
		return '';
	}
}
