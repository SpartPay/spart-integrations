<?php
/**
 * PHPUnit unit-test bootstrap.
 *
 * Loads Composer autoload + Brain Monkey, then defines minimal class stubs
 * for any WooCommerce parent classes the plugin extends. We do NOT load
 * WordPress or WooCommerce here — that's the integration tier's job.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! is_dir( '/tmp/wp/wp-admin/includes' ) ) {
    @mkdir( '/tmp/wp/wp-admin/includes', 0777, true );
}
if ( ! file_exists( '/tmp/wp/wp-admin/includes/upgrade.php' ) ) {
    file_put_contents( '/tmp/wp/wp-admin/includes/upgrade.php', "<?php // stub for unit tests\n" );
}

// Stub WordPress functions that Plugin::boot() calls but are not provided by
// Brain Monkey's automatic patching.
if ( ! function_exists( 'register_activation_hook' ) ) {
    // phpcs:ignore
    function register_activation_hook( string $file, callable $callback ): void {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
    // phpcs:ignore
    function register_deactivation_hook( string $file, callable $callback ): void {}
}

if ( ! function_exists( '__' ) ) {
	// phpcs:ignore
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

// Load WP function stubs that are used as plain no-ops (never mocked).
// NOTE: Patchwork is NOT yet active here — it loads lazily inside
// Brain\Monkey\setUp().  Functions defined in stubs.php cannot be
// redefined by Brain\Monkey\when().  Only add functions here that
// tests never need to override.
require_once __DIR__ . '/stubs.php';

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    /**
     * Abstract stand-in for WooCommerce's WC_Payment_Gateway so unit tests
     * can instantiate WC_Gateway_Spart without loading WC.
     */
    abstract class WC_Payment_Gateway { // phpcs:ignore
        public string $id                 = '';
        public string $method_title       = '';
        public string $method_description = '';
        public string $title              = '';
        public string $description        = '';
        public bool   $has_fields         = false;
        public string $icon               = '';
        public string $enabled            = 'no';
        public array  $supports           = [];
        public array  $form_fields        = [];
        public array  $settings           = [];

        public function get_option( string $key, mixed $empty_value = null ): mixed {
            return $this->settings[ $key ] ?? ( $this->form_fields[ $key ]['default'] ?? $empty_value );
        }

        public function get_option_key(): string {
            return 'woocommerce_' . $this->id . '_settings';
        }

        public function get_field_key( string $key ): string {
            // Match WC's real WC_Settings_API::get_field_key shape:
            // plugin_id . id . '_' . key — for a WC_Payment_Gateway,
            // plugin_id is always 'woocommerce_'.
            return 'woocommerce_' . $this->id . '_' . $key;
        }

        public function get_description_html( array $data ): string {
            // Match WC: when desc_tip is set the description renders as a
            // hover tooltip (see get_tooltip_html), not inline.
            if ( ! empty( $data['desc_tip'] ) ) {
                return '';
            }
            return isset( $data['description'] ) ? '<p class="description">' . $data['description'] . '</p>' : '';
        }

        public function get_tooltip_html( array $data ): string {
            if ( empty( $data['desc_tip'] ) ) {
                return '';
            }
            $tip = true === $data['desc_tip']
                ? ( $data['description'] ?? '' )
                : (string) $data['desc_tip'];
            return '' !== $tip
                ? '<span class="woocommerce-help-tip" data-tip="' . $tip . '"></span>'
                : '';
        }

        abstract public function process_payment( mixed $order_id ): mixed;

        public function init_form_fields(): void {}

        public function init_settings(): void {}

        public function process_admin_options(): bool {
            return true;
        }

        public function is_available(): bool {
            // Mirror WC's real base WC_Payment_Gateway::is_available() — gates
            // the gateway on the merchant having flipped Enabled to "yes". The
            // Spart subclass overrides this to layer additional checks
            // (API-key configured, merchants/eligibility verdict).
            return 'yes' === $this->enabled;
        }
    }
}

if ( ! class_exists( 'WC_Order' ) ) {
    /**
     * Minimal WC_Order stub for unit tests.
     *
     * Exposes the subset of getters IntentRequestBuilder relies on.
     * `__test_init()` is a controlled back-door used by tests to populate the
     * stub without simulating the full WC lifecycle.
     */
    class WC_Order { // phpcs:ignore
        /** @var array<string, mixed> */
        private array $data = [];
        /** @var list<array<string, mixed>> */
        private array $items = [];

        /** @param array<string, mixed> $d */
        public function __test_init( array $d ): void {
            $this->data  = $d;
            $this->items = $d['items'] ?? [];
        }

        public function get_id(): int { return (int) ( $this->data['id'] ?? 0 ); }
        public function get_status(): string { return (string) ( $this->data['status'] ?? 'pending' ); }
        public function get_currency(): string { return (string) ( $this->data['currency'] ?? 'USD' ); }
        public function get_total(): string { return (string) ( $this->data['total'] ?? '0' ); }
        public function get_billing_email(): string { return (string) ( $this->data['email'] ?? '' ); }
        public function get_billing_first_name(): string { return (string) ( $this->data['first'] ?? '' ); }
        public function get_billing_last_name(): string { return (string) ( $this->data['last'] ?? '' ); }

        /** @return array<int, object> */
        public function get_items( string $type = 'line_item' ): array {
            $out = [];
            foreach ( $this->items as $i => $row ) {
                $out[ $i ] = new class( $row ) {
                    /** @param array<string, mixed> $row */
                    public function __construct( private array $row ) {}
                    public function get_name(): string { return (string) ( $this->row['name'] ?? '' ); }
                    public function get_quantity(): int { return (int) ( $this->row['qty'] ?? 1 ); }
                    public function get_product(): ?object {
                        $img = $this->row['image'] ?? null;
                        if ( $img === null ) { return null; }
                        return new class( (string) $img ) {
                            public function __construct( private string $img ) {}
                            public function get_image_id(): int { return 1; }
                            public function get_image_url(): string { return $this->img; }
                        };
                    }
                };
            }
            return $out;
        }

        public function delete( bool $force = false ): bool { return true; }

        /** @return list<string> */
        public function get_coupon_codes(): array { return []; }

        /** @var array<string, mixed> */
        private array $meta = [];

        public function get_meta( string $key, bool $single = true ): mixed {
            return $this->meta[ $key ] ?? '';
        }

        public function update_meta_data( string $key, mixed $value ): void {
            $this->meta[ $key ] = $value;
        }

        public function save(): int { return $this->get_id(); }
    }
}

if ( ! class_exists( 'WC_Product' ) ) {
    /**
     * Minimal WC_Product stand-in for unit tests. Mocked per-test via Mockery.
     */
    class WC_Product { // phpcs:ignore
        public function managing_stock(): bool { return false; }
    }
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
    /**
     * Minimal WC_Order_Item_Product stand-in for unit tests. Mocked per-test
     * via Mockery so OrderSyncTest can exercise the stock-restore branch
     * without booting WooCommerce.
     */
    class WC_Order_Item_Product { // phpcs:ignore
        public function get_meta( string $key, bool $single = false ): mixed { return ''; }
        public function get_product(): ?WC_Product { return null; }
        public function delete_meta_data( string $key ): void {}
        public function save(): int { return 0; }
    }
}

if ( ! class_exists( 'wpdb' ) ) {
    /**
     * Minimal `\wpdb` stand-in for unit tests.
     *
     * Mocked per-test via Mockery — this declaration only needs to exist
     * so Mockery can build a partial mock that satisfies the production
     * code's `\wpdb` type hint.
     */
    class wpdb { // phpcs:ignore
        public string $prefix     = 'wp_';
        public string $last_error = '';
        public int    $insert_id  = 0;

        public function prepare( string $query, mixed ...$args ): string {
            return $query;
        }

        public function get_row( string $query, string $output = 'OBJECT', int $row_offset = 0 ): mixed {
            return null;
        }

        /** @param array<string, mixed> $data @param array<int, string>|string|null $format */
        public function insert( string $table, array $data, array|string|null $format = null ): int|false {
            return false;
        }

        /**
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         * @param array<int, string>|string|null $format
         * @param array<int, string>|string|null $where_format
         */
        public function update( string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null ): int|false {
            return false;
        }

        public function query( string $query ): int|false {
            return false;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    /**
     * Minimal stand-in for WordPress's WP_REST_Request, used by webhook
     * receiver unit tests. Lookup of headers is case-insensitive (the real
     * class normalises to lowercase internally).
     */
    class WP_REST_Request { // phpcs:ignore
        /** @var array<string, string> Headers, keyed by lowercase name. */
        private array $headers;

        /** @param array<string, string> $headers Headers keyed by HTTP-style name. */
        public function __construct(
            private string $body = '',
            array $headers = []
        ) {
            $this->headers = [];
            foreach ( $headers as $name => $value ) {
                $this->headers[ strtolower( (string) $name ) ] = (string) $value;
            }
        }

        public function get_body(): string {
            return $this->body;
        }

        public function get_header( string $name ): ?string {
            return $this->headers[ strtolower( $name ) ] ?? null;
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    /**
     * Minimal stand-in for WordPress's WP_REST_Response, used by webhook
     * receiver unit tests.
     */
    class WP_REST_Response { // phpcs:ignore
        /** @param array<string, string> $headers */
        public function __construct(
            private mixed $data = null,
            private int $status = 200,
            private array $headers = []
        ) {}

        public function get_status(): int {
            return $this->status;
        }

        public function get_data(): mixed {
            return $this->data;
        }

        /** @return array<string, string> */
        public function get_headers(): array {
            return $this->headers;
        }
    }
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
	// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
	require_once __DIR__ . '/stubs-blocks.php';
}
