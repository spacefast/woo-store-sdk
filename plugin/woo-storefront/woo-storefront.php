<?php
/**
 * Plugin Name: Woo Storefront
 * Description: Hosted checkout, customer accounts, and cache signals for headless WooCommerce storefronts.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.3
 * Text Domain: woo-storefront
 *
 * @package WooStorefront
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOO_STOREFRONT_VERSION', '0.1.0' );
define( 'WOO_STOREFRONT_FILE', __FILE__ );
define( 'WOO_STOREFRONT_PATH', plugin_dir_path( __FILE__ ) );

$woo_storefront_files = array(
	'src/Support/Base64Url.php',
	'src/Support/Jwt.php',
	'src/Support/SignedKey.php',
	'src/Support/Secrets.php',
	'src/CheckoutUrl.php',
	'src/CheckoutEntry.php',
	'src/Auth.php',
	'src/Accounts.php',
	'src/Webhooks.php',
	'src/Branding.php',
	'src/Plugin.php',
);

foreach ( $woo_storefront_files as $woo_storefront_file ) {
	require_once WOO_STOREFRONT_PATH . $woo_storefront_file;
}

register_activation_hook( __FILE__, array( WooStorefront\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WooStorefront\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( WooStorefront\Plugin::class, 'boot' ), 20 );
