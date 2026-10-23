<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** `setup` and `status`: idempotent store setup after the D7 set is active, and read-only facts. */
final class Provisioning {
	public function __construct( private Plugin $plugin ) {}

	public function setup(): array|\WP_Error {
		if ( ! get_option( 'woocommerce_db_version' ) ) {
			\WC_Install::install();
		}
		$pages = $this->plugin->storefront->ensure_pages();
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}
		// Downloads go through Woo's authorized, forced transfer only.
		update_option( 'woocommerce_downloads_grant_access_after_payment', 'yes' );
		update_option( 'woocommerce_file_download_method', 'force' );
		update_option( 'woocommerce_downloads_redirect_fallback_allowed', 'no' );
		// Seeded once (D1): taxes start off; turning them on in wp-admin survives every deploy.
		if ( ! get_option( 'spacefast_commerce_tax_seeded' ) ) {
			update_option( 'woocommerce_calc_taxes', 'no' );
			update_option( 'spacefast_commerce_tax_seeded', gmdate( 'c' ), false );
		}
		if ( false === get_option( 'spacefast_commerce_mode' ) ) {
			$mode = $this->plugin->mode->set( 'test' );
			if ( is_wp_error( $mode ) ) {
				return $mode;
			}
		}
		return array( 'ok' => true, 'mode' => $this->plugin->store->mode() );
	}

	public function status(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$versions = array( 'wordpress' => get_bloginfo( 'version' ) );
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$versions[ dirname( $file ) ] = $data['Version'];
			}
		}
		ksort( $versions );
		return array(
			'ok' => true,
			'mode' => $this->plugin->store->mode(),
			'stripeEnabled' => $this->plugin->mode->stripe_enabled(),
			'orderCount' => Orders::count(),
			'storeHash' => $this->plugin->store->store_hash(),
			'counter' => $this->plugin->store->counter(),
			'canonicalOrigin' => $this->plugin->store->canonical_origin(),
			'versions' => $versions,
		);
	}
}
