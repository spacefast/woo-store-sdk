<?php
/**
 * Catalog cache revalidation webhooks.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

/**
 * Emits signed product cache signals to the configured application URL.
 */
final class Webhooks {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_update_product', array( $this, 'product_updated' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'stock_updated' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'stock_updated' ) );
		add_action( 'woocommerce_product_object_updated_props', array( $this, 'props_updated' ), 10, 2 );
	}

	/**
	 * Emit a product update.
	 *
	 * @param int $product_id Product ID.
	 */
	public function product_updated( int $product_id ): void {
		$this->send( 'product.updated', array( $product_id ) );
	}

	/**
	 * Emit a stock update.
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function stock_updated( \WC_Product $product ): void {
		$this->send( 'stock.updated', $this->product_ids( $product ) );
	}

	/**
	 * Emit a price update when price properties changed.
	 *
	 * @param \WC_Product $product      Product object.
	 * @param string[]    $updated_props Updated property names.
	 */
	public function props_updated( \WC_Product $product, array $updated_props ): void {
		if ( array_intersect( array( 'price', 'regular_price', 'sale_price' ), $updated_props ) ) {
			$this->send( 'price.updated', $this->product_ids( $product ) );
		}
	}

	/**
	 * Include a variation and its parent in invalidation.
	 *
	 * @param \WC_Product $product Product object.
	 * @return int[]
	 */
	private function product_ids( \WC_Product $product ): array {
		$ids = array( $product->get_id() );
		if ( $product->is_type( 'variation' ) && $product->get_parent_id() > 0 ) {
			$ids[] = $product->get_parent_id();
		}

		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Send an HMAC-authenticated JSON payload.
	 *
	 * @param string $event       Event name.
	 * @param int[]  $product_ids Product IDs.
	 */
	private function send( string $event, array $product_ids ): void {
		$settings = Branding::get_settings();
		$url      = (string) ( $settings['revalidate_url'] ?? '' );
		$secret   = (string) ( $settings['shared_secret'] ?? '' );
		if ( '' === $url || '' === $secret ) {
			return;
		}

		$payload = wp_json_encode(
			array(
				'event'      => $event,
				'productIds' => array_values( array_unique( array_map( 'absint', $product_ids ) ) ),
			)
		);

		if ( ! is_string( $payload ) ) {
			return;
		}

		wp_remote_post(
			$url,
			array(
				'blocking' => false,
				'timeout'  => 5,
				'headers'  => array(
					'Content-Type'                    => 'application/json',
					'X-Woo-Storefront-Signature'     => 'sha256=' . hash_hmac( 'sha256', $payload, $secret ),
					'X-Woo-Storefront-Event'         => $event,
				),
				'body'     => $payload,
			)
		);
	}
}
