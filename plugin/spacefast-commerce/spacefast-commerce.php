<?php
/**
 * Plugin Name: Spacefast Commerce
 * Description: Source-managed WooCommerce stores: declared catalog, store pages, test/live mode and source-designed emails.
 * Version: 0.2.11
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce, woo-storefront
 * Text Domain: spacefast-commerce
 *
 * @package SpacefastCommerce
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( array( 'Bundle', 'Store', 'Planner', 'Media', 'PrivateFiles', 'ManagedProducts', 'Shipping', 'Storefront', 'Emails', 'Mode', 'MerchantAccess', 'Orders', 'Catalog', 'Provisioning', 'Plugin' ) as $spacefast_commerce_class ) {
	require_once __DIR__ . '/src/' . $spacefast_commerce_class . '.php';
}
require_once __DIR__ . '/cli.php';

add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', array( \SpacefastCommerce\Plugin::class, 'boot' ), 30 );
