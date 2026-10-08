<?php
/**
 * Plugin Name: Spacefast Commerce
 * Description: Source-managed WooCommerce stores and the Spacefast payment boundary.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce, woo-storefront
 * Text Domain: spacefast-commerce
 *
 * @package SpacefastCommerce
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/ManagedProducts.php';
require_once __DIR__ . '/src/PrivateFiles.php';
require_once __DIR__ . '/src/Catalog.php';
require_once __DIR__ . '/src/Provisioning.php';

add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', static function (): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	$store = new \SpacefastCommerce\Store();
	$managed = new \SpacefastCommerce\ManagedProducts();
	$managed->register();
	$files = new \SpacefastCommerce\PrivateFiles( $store );
	$files->register();
	( new \SpacefastCommerce\Provisioning( $store, $files ) )->register();
	( new \SpacefastCommerce\Catalog( $store, $managed, $files ) )->register();
	$store->register();
}, 30 );
