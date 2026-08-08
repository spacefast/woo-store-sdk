<?php
/**
 * Woo Storefront uninstall cleanup.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woo_storefront_settings' );
delete_option( 'woo_storefront_checkout_secret' );
delete_option( 'woo_storefront_auth_secret' );
delete_option( 'woo_storefront_jti_denylist' );

global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->esc_like( '_transient_woo_storefront_revoked_' ) . '%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->esc_like( '_transient_timeout_woo_storefront_revoked_' ) . '%'
	)
);
