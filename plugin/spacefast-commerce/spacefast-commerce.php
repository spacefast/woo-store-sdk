<?php
/**
 * Plugin Name: Spacefast Commerce
 * Description: Source-managed WooCommerce stores and the Spacefast payment boundary.
 * Version: 0.1.12
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
require_once __DIR__ . '/src/ShopperPolicy.php';
require_once __DIR__ . '/src/PrivateFiles.php';
require_once __DIR__ . '/src/Catalog.php';
require_once __DIR__ . '/src/Provisioning.php';
require_once __DIR__ . '/src/PaymentOrders.php';
require_once __DIR__ . '/src/Orders.php';
require_once __DIR__ . '/src/Refunds.php';
require_once __DIR__ . '/src/Reconciliation.php';

add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'woocommerce_blocks_loaded', static function (): void {
	require_once __DIR__ . '/src/BlockPayment.php';
	add_action( 'woocommerce_blocks_payment_method_type_registration', static function ( $registry ): void {
		$registry->register( new \SpacefastCommerce\BlockPayment() );
	} );
} );

add_action( 'plugins_loaded', static function (): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	require_once __DIR__ . '/src/Gateway.php';
	add_filter( 'woocommerce_payment_gateways', static function ( array $gateways ): array {
		$gateways[] = \SpacefastCommerce\Gateway::class;
		return $gateways;
	} );
	$store = new \SpacefastCommerce\Store();
	$managed = new \SpacefastCommerce\ManagedProducts();
	$managed->register();
	( new \SpacefastCommerce\ShopperPolicy( $store ) )->register();
	$files = new \SpacefastCommerce\PrivateFiles( $store );
	$files->register();
	( new \SpacefastCommerce\Provisioning( $store, $files ) )->register();
	( new \SpacefastCommerce\PaymentOrders( $store ) )->register();
	( new \SpacefastCommerce\Orders( $store ) )->register();
	( new \SpacefastCommerce\Refunds( $store ) )->register();
	( new \SpacefastCommerce\Reconciliation( $store ) )->register();
	( new \SpacefastCommerce\Catalog( $store, $managed, $files ) )->register();
	$store->register();
}, 30 );
