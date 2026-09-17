<?php
/**
 * Checkout loading screen and settings adapter.
 *
 * @package Spart\WooCommerce
 */

declare(strict_types=1);

namespace Spart\WooCommerce\Checkout;

use Spart\WooCommerce\Plugin;

/** Loading-screen validation and WordPress assets. */
final class LoadingScreen {

	/**
	 * Set asset URL and cache version.
	 *
	 * @param string $assets_url Plugin assets URL, including trailing slash.
	 * @param string $version    Asset cache version.
	 */
	public function __construct(
		private readonly string $assets_url,
		private readonly string $version
	) {}

	/** Defer until checkout conditionals and translations are ready. */
	public static function register(): void {
		add_action(
			'wp_enqueue_scripts',
			static function (): void {
				$screen = new self( plugins_url( 'assets/', Plugin::plugin_file() ), Plugin::VERSION );
				$screen->enqueue();
			}
		);
		add_action(
			'admin_enqueue_scripts',
			static function ( string $hook ): void {
				$screen = new self( plugins_url( 'assets/', Plugin::plugin_file() ), Plugin::VERSION );
				$screen->enqueue_admin( $hook );
			}
		);
	}

	/**
	 * Validate before schema integer coercion; invalid values use defaults.
	 *
	 * @param array<string, mixed> $settings Untrusted settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $settings ): array {
		$color   = $settings['loading_screen_backdrop_color'] ?? '#192a23';
		$opacity = $settings['loading_screen_backdrop_opacity'] ?? 55;
		$image   = $settings['loading_screen_image_id'] ?? 0;
		$opacity = is_int( $opacity ) || is_string( $opacity ) ? filter_var( $opacity, FILTER_VALIDATE_INT ) : false;
		$image   = is_int( $image ) || is_string( $image ) ? filter_var( $image, FILTER_VALIDATE_INT ) : false;
		if ( false === $image || $image < 1
			|| ! wp_attachment_is_image( $image )
			|| ! in_array( get_post_mime_type( $image ), array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/tiff', 'image/x-icon', 'image/vnd.microsoft.icon' ), true )
			|| ! wp_get_attachment_image_url( $image, 'full' ) ) {
			$image = 0;
		}
		return array(
			'loading_screen_enabled'          => ( $settings['loading_screen_enabled'] ?? 'yes' ) === 'yes' ? 'yes' : 'no',
			'loading_screen_backdrop_color'   => is_string( $color ) && preg_match( '/^#[0-9a-fA-F]{6}$/D', $color ) ? strtolower( $color ) : '#192a23',
			'loading_screen_backdrop_opacity' => false !== $opacity && $opacity >= 0 && $opacity <= 100 ? $opacity : 55,
			'loading_screen_image_id'         => $image,
		);
	}

	/**
	 * Share checkout eligibility with Blocks dependency registration.
	 *
	 * @param array<string, mixed> $settings Saved settings.
	 */
	public static function enabled_for_checkout( array $settings ): bool {
		return ( $settings['enabled'] ?? 'no' ) === 'yes'
			&& ( $settings['loading_screen_enabled'] ?? 'yes' ) === 'yes'
			&& ! is_admin()
			&& function_exists( 'is_checkout' ) && is_checkout()
			&& ! is_wc_endpoint_url( 'order-pay' )
			&& ! is_wc_endpoint_url( 'order-received' );
	}

	/**
	 * Shared frontend config; revalidate attachments on every read.
	 *
	 * @param array<string, mixed> $settings Saved settings.
	 * @return array<string, mixed>
	 */
	public function config( array $settings ): array {
		$settings = self::sanitize( $settings );
		return array(
			'backdropColor'   => $settings['loading_screen_backdrop_color'],
			'backdropOpacity' => $settings['loading_screen_backdrop_opacity'],
			'imageUrl'        => $settings['loading_screen_image_id'] ? esc_url_raw( (string) wp_get_attachment_image_url( $settings['loading_screen_image_id'], 'full' ) ) : '',
			'title'           => __( 'SPART_LOADING_TITLE', 'spart-woocommerce' ),
			'description'     => __( 'SPART_LOADING_DESCRIPTION', 'spart-woocommerce' ),
		);
	}

	/**
	 * Register before Blocks resolves dependencies or assets are enqueued.
	 *
	 * @param array<string, mixed> $settings Saved settings.
	 */
	public function register_shared( array $settings ): void {
		wp_register_script( 'spart-checkout-loading', $this->assets_url . 'js/checkout-loading.js', array(), $this->version, true );
		// JSON preserves numeric opacity; wp_localize_script would stringify it.
		wp_add_inline_script(
			'spart-checkout-loading',
			'window.spartCheckoutLoadingConfig = ' . wp_json_encode( $this->config( $settings ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
	}

	/**
	 * Share presentation assets across checkout and preview.
	 *
	 * @param array<string, mixed> $settings Saved settings.
	 */
	private function enqueue_shared( array $settings ): void {
		$this->register_shared( $settings );
		wp_enqueue_style( 'spart-checkout-loading', $this->assets_url . 'css/checkout-loading.css', array(), $this->version );
		wp_enqueue_script( 'spart-checkout-loading' );
	}

	/** Limit assets to enabled checkout, excluding order-pay and thank-you. */
	public function enqueue(): void {
		$settings = (array) get_option( 'woocommerce_spart_settings', array() );
		if ( ! self::enabled_for_checkout( $settings ) ) {
			return;
		}
		$this->enqueue_shared( $settings );
		// Blocks may register at init, before checkout conditionals are available.
		$blocks_script = wp_scripts()->registered['spart-blocks-checkout'] ?? null;
		if ( $blocks_script && ! in_array( 'spart-checkout-loading', $blocks_script->deps, true ) ) {
			$blocks_script->deps[] = 'spart-checkout-loading';
		}
		if ( ! has_block( 'woocommerce/checkout' ) ) {
			wp_enqueue_script( 'spart-classic-checkout-loading', $this->assets_url . 'js/classic-checkout-loading.js', array( 'jquery', 'wc-checkout', 'spart-checkout-loading' ), $this->version, true );
		}
	}

	/**
	 * Allow preview while disabled; WooCommerce's capability/nonce checks guard saves.
	 *
	 * @param string $hook WordPress admin page hook suffix.
	 */
	public function enqueue_admin( string $hook ): void {
		// Exact allowlisted routing values: never rendered or persisted.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'woocommerce_page_wc-settings' !== $hook
			|| ( $_GET['page'] ?? '' ) !== 'wc-settings'
			|| ( $_GET['tab'] ?? '' ) !== 'checkout'
			|| ( $_GET['section'] ?? '' ) !== 'spart'
			|| ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
		wp_enqueue_media();
		$this->enqueue_shared( (array) get_option( 'woocommerce_spart_settings', array() ) );
		wp_enqueue_script( 'spart-loading-screen-admin', $this->assets_url . 'js/loading-screen-admin.js', array( 'jquery', 'media-editor', 'spart-checkout-loading' ), $this->version, true );
		wp_localize_script(
			'spart-loading-screen-admin',
			'spartLoadingScreenAdminConfig',
			array(
				'chooseImage'  => __( 'SPART_LOADING_CHOOSE_IMAGE', 'spart-woocommerce' ),
				'clearImage'   => __( 'SPART_LOADING_CLEAR_IMAGE', 'spart-woocommerce' ),
				'preview'      => __( 'SPART_LOADING_PREVIEW', 'spart-woocommerce' ),
				'closeLabel'   => __( 'SPART_LOADING_CLOSE', 'spart-woocommerce' ),
				'invalidImage' => __( 'SPART_LOADING_INVALID_IMAGE', 'spart-woocommerce' ),
			)
		);
	}
}
