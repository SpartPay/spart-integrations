<?php
/**
 * Plugin Name:       Spart for WooCommerce
 * Plugin URI:        https://spartpay.com/
 * Description:       Accept payments split into multiple parts via Spart at WooCommerce checkout.
 * Version:           0.5.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Spart
 * Author URI:        https://spartpay.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spart-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Spart\WooCommerce
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

\Spart\WooCommerce\Plugin::boot( __FILE__ );
