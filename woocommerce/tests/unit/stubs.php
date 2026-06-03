<?php
/**
 * WordPress function stubs for unit tests.
 *
 * IMPORTANT: Patchwork is NOT active when bootstrap.php requires this file —
 * Patchwork only loads when Brain\Monkey\setUp() is first called.  Any
 * function defined here is therefore "defined too early" from Patchwork's
 * perspective and CANNOT be mocked via Brain\Monkey\Functions\when().
 *
 * Define here only functions that are called as plain stubs (never mocked).
 * Functions that need to be overridden per-test (e.g. home_url,
 * trailingslashit) must NOT appear here; Brain\Monkey will create them via
 * eval() on the first when() call, after Patchwork is active.
 *
 * phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- this file
 * intentionally mixes WP function stubs and the WP_List_Table stub class to
 * keep all test-runtime polyfills in one place.
 */

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( array|string $args, array $defaults = array() ): array {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( string $classname, string $fallback = '' ): string {
		$sanitized = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $classname );
		if ( '' === $sanitized && '' !== $fallback ) {
			return sanitize_html_class( $fallback );
		}
		return $sanitized;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $data ): string {
		return $data;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- We ARE the wp_parse_url stub.
	}
}

// home_url(), trailingslashit(), get_option(), and update_option() are
// intentionally omitted. They must be undefined at test-load time so
// Brain\Monkey can define them via eval() (after Patchwork is active)
// when when() is called.

// WP_List_Table stub — required so WebhookDeliveriesTable can extend it
// in unit tests where the real WordPress admin include is unavailable.
if ( ! class_exists( 'WP_List_Table' ) ) {
	class WP_List_Table {
		public array $items = array();
		// phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- WP_List_Table convention; consumed by core display methods.
		protected array $_column_headers = array();

		public function __construct( array $args = array() ) {}

		public function set_pagination_args( array $args ): void {}

		public function get_columns(): array {
			return array();
		}

		public function display(): void {}

		public function search_box( string $text, string $input_id ): void {}
	}
}
