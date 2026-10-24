<?php
/**
 * Settle the storefront cart consumed by a native checkout clone.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

/** Payment, including a webhook, may clear only the unchanged cart handed to checkout. */
final class CheckoutCart {
	/** Woo item keys encode the product, variation and item data, unlike mutable totals. */
	private function cart_hash( array $cart ): string {
		$items = array();
		foreach ( $cart as $key => $item ) {
			$items[ $key ] = $item['quantity'];
		}
		ksort( $items );
		return hash( 'sha256', serialize( $items ) );
	}

	public function register(): void {
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'initialize_source' ), 10, 0 );
		foreach ( array( 'woocommerce_add_to_cart', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_cart_emptied', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon' ) as $hook ) {
			add_action( $hook, array( $this, 'mark_source_change' ), 10, 0 );
		}
		add_action( 'template_redirect', array( $this, 'capture_source' ), 4 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'remember_source' ) );
		add_action( 'woocommerce_checkout_order_processed', function ( int $id ): void {
			$order = wc_get_order( $id );
			if ( $order ) {
				$this->remember_source( $order );
			}
		} );
		add_action( 'woocommerce_payment_complete', array( $this, 'settle_source' ) );
		add_action( 'woocommerce_order_status_changed', function ( int $id, string $from, string $to ): void {
			if ( in_array( $to, array( 'processing', 'completed', 'on-hold' ), true ) ) {
				$this->settle_source( $id );
			}
		}, 10, 3 );
	}

	/** Seed older carts once; reads must not advance their mutation identity. */
	public function initialize_source(): void {
		if ( WC()->session && ! WC()->session->get( 'woo_storefront_cart_revision' ) ) {
			$this->mark_source_change();
		}
	}

	/** A cart changed away and back is still a newer cart, even if its hash matches. */
	public function mark_source_change(): void {
		if ( WC()->session && ! WC()->session->get( 'previous_customer_id' ) ) {
			WC()->session->set( 'woo_storefront_cart_revision', wp_generate_uuid4() );
		}
	}

	/** Woo's clone already carries the cart snapshot, without the source's customer details. */
	public function capture_source(): void {
		if ( ! is_checkout() || is_wc_endpoint_url() || ! WC()->session || WC()->session->get( 'woo_storefront_source_cart' ) ) {
			return;
		}
		$id = WC()->session->get( 'previous_customer_id' );
		$cart = WC()->session->get( 'cart' );
		$revision = WC()->session->get( 'woo_storefront_cart_revision' );
		if ( is_string( $id ) && str_starts_with( $id, 't_' ) && is_array( $cart ) && array() !== $cart && is_string( $revision ) ) {
			WC()->session->set( 'woo_storefront_source_cart', array( 'id' => $id, 'hash' => $this->cart_hash( $cart ), 'revision' => $revision ) );
		}
	}

	/** Persist before payment so delayed gateway callbacks do not need the shopper's session. */
	public function remember_source( \WC_Order $order ): void {
		$source = WC()->session ? WC()->session->get( 'woo_storefront_source_cart' ) : null;
		if ( is_array( $source ) && ! $order->get_meta( '_woo_storefront_source_cart' ) ) {
			$order->update_meta_data( '_woo_storefront_source_cart', $source );
			$order->save_meta_data();
		}
	}

	/** Atomically remove an unchanged guest session, preserving any newer storefront cart. */
	public function settle_source( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_woo_storefront_source_cart_settled' ) ) {
			return;
		}
		$source = $order->get_meta( '_woo_storefront_source_cart' );
		if ( ! is_array( $source ) || ! is_string( $source['id'] ?? null ) || ! str_starts_with( $source['id'], 't_' ) || ! is_string( $source['hash'] ?? null ) || ! is_string( $source['revision'] ?? null ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		$wpdb->last_error = '';
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT session_value FROM %i WHERE session_key = %s', $table, $source['id'] ) );
		if ( '' !== $wpdb->last_error ) {
			return;
		}
		$data = is_string( $value ) ? maybe_unserialize( $value ) : null;
		$cart = is_array( $data ) ? maybe_unserialize( $data['cart'] ?? null ) : null;
		$revision = is_array( $data ) ? maybe_unserialize( $data['woo_storefront_cart_revision'] ?? null ) : null;
		if ( is_string( $revision ) && hash_equals( $source['revision'], $revision ) && is_array( $cart ) && hash_equals( $source['hash'], $this->cart_hash( $cart ) ) ) {
			// Woo has no conditional session-delete API. Compare the complete stored
			// bytes so even a concurrent address/cart edit wins over this cleanup.
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_key = %s AND BINARY session_value = BINARY %s', $table, $source['id'], $value ) );
			if ( false === $deleted ) {
				return;
			}
			if ( $deleted > 0 ) {
				wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $source['id'], WC_SESSION_CACHE_GROUP );
			}
		}
		// A changed source is settled too: a duplicate/late payment must never
		// clear a later cart that happens to contain the original items again.
		$order->update_meta_data( '_woo_storefront_source_cart_settled', true );
		$order->save_meta_data();
	}
}
