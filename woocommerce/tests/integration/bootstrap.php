<?php
/**
 * Integration test bootstrap.
 *
 * Runs INSIDE the @wordpress/env tests-cli container. WordPress is at
 * /var/www/html/. The Spart plugin is activated by wp-env's
 * lifecycleScripts.afterStart so it loads naturally during the wp-load.php
 * boot below — that order matters: hooks like `before_woocommerce_init`
 * and `woocommerce_payment_gateways` only fire once during WC init, so the
 * plugin must be in active_plugins BEFORE wp-load runs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    fwrite( STDERR, "Could not find WP at {$wp_load}\n" );
    exit( 1 );
}
require $wp_load;

if ( ! class_exists( 'WooCommerce' ) ) {
    fwrite( STDERR, "WooCommerce did not load. Check .wp-env.json plugins[] and lifecycleScripts.afterStart.\n" );
    exit( 1 );
}

if ( ! class_exists( \Spart\WooCommerce\Plugin::class ) || ! \Spart\WooCommerce\Plugin::is_booted() ) {
    fwrite( STDERR, "Spart plugin did not boot. Check that lifecycleScripts.afterStart activated 'spart-woocommerce' in tests-cli.\n" );
    exit( 1 );
}

// HPOS toggle for the CircleCI php-tests-wc matrix.
// WP_SPART_HPOS_MODE is set per matrix slot ('on' or 'off'). Defaults to 'on'
// to match wp-env's out-of-the-box state.
$hpos_mode = getenv( 'WP_SPART_HPOS_MODE' );
if ( ! is_string( $hpos_mode ) || '' === $hpos_mode ) {
    $hpos_mode = 'on';
}

if ( function_exists( 'update_option' ) ) {
    update_option(
        'woocommerce_custom_orders_table_enabled',
        'on' === $hpos_mode ? 'yes' : 'no'
    );

    if ( function_exists( 'wc_get_container' )
        && class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class ) ) {
        $hpos_controller = wc_get_container()
            ->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
        if ( method_exists( $hpos_controller, 'show_feature' ) ) {
            $hpos_controller->show_feature();
        }
    }
}
