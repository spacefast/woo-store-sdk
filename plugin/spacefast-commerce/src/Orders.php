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
	public static function count(): int {
		$statuses = array_merge( array( 'trash' ), array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) );
		$result = wc_get_orders( array(
			'type' => 'shop_order',
			'status' => $statuses,
			'limit' => 1,
			'paginate' => true,
			'return' => 'ids',
		) );
		return (int) $result->total;
	}
}
