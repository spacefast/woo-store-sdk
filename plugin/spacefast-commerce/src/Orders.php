<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * Read-only order facts. Woo owns orders; the merchant manages them in wp-admin (M1).
 * The control plane reads the count to refuse purging a store with orders (D12).
 */
final class Orders {
	/** Every order Woo holds in any status, test orders included; checkout drafts are not orders yet. */
	public static function count(): int|\WP_Error {
		global $wpdb;
		$statuses = array_merge( array( 'trash' ), array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) );
		// A cached zero or a failed query cannot authorize permanent deletion.
		$wpdb->last_error = '';
		try {
			$result = wc_get_orders( array(
				'type' => 'shop_order',
				'status' => $statuses,
				'limit' => 1,
				'paginate' => true,
				'return' => 'ids',
				'cache_results' => false,
			) );
		} catch ( \Throwable ) {
			return self::unavailable();
		}
		if ( '' !== $wpdb->last_error || ! is_object( $result ) || ! isset( $result->total ) || ! is_int( $result->total ) || $result->total < 0 ) {
			return self::unavailable();
		}
		return $result->total;
	}

	/** A native retention fact, available only with the fail-closed count contract. */
	public static function has_orders(): bool|\WP_Error {
		$count = self::count();
		return is_wp_error( $count ) ? $count : $count > 0;
	}

	private static function unavailable(): \WP_Error {
		return Bundle::refuse( 'orders-unavailable', 'WooCommerce order history could not be read. Retry when the store database is available.' );
	}
}
