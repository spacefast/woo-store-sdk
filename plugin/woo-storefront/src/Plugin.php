<?php
/**
 * Plugin composition root.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

/**
 * Checks requirements and registers feature services.
 */
final class Plugin {
	/**
	 * Boot the plugin after WooCommerce has loaded.
	 */
	public static function boot(): void {
		if ( PHP_VERSION_ID < 80100 || ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'requirements_notice' ) );
			return;
		}

		$auth = new Auth();
		$services = array(
			new Branding(),
			new CheckoutUrl(),
			new CheckoutEntry(),
			$auth,
			new Accounts( $auth ),
			new Webhooks(),
		);

		foreach ( $services as $service ) {
			$service->register();
		}
	}

	/**
	 * Plugin activation.
	 */
	public static function activate(): void {
		if ( PHP_VERSION_ID < 80100 || ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( WOO_STOREFRONT_FILE ) );
			wp_die(
				esc_html__( 'Woo Storefront requires PHP 8.1 or newer and an active WooCommerce installation.', 'woo-storefront' ),
				esc_html__( 'Plugin requirements not met', 'woo-storefront' ),
				array( 'back_link' => true )
			);
		}

		CheckoutEntry::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * Flush checkout rewrites on deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Show a requirements warning.
	 */
	public static function requirements_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Woo Storefront is inactive because it requires PHP 8.1 or newer and WooCommerce.', 'woo-storefront' );
		echo '</p></div>';
	}
}
