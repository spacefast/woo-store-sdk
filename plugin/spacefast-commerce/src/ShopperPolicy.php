<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Woo owns the cart; managed source owns quantity and included-shipping destinations. */
final class ShopperPolicy {
	public function __construct( private Store $store ) {}

	public function register(): void {
		add_action( 'woocommerce_check_cart_items', function (): void {
			$error = $this->cart_error( WC()->cart );
			if ( null !== $error ) {
				wc_add_notice( $error, 'error' );
			}
		} );
		add_action( 'woocommerce_store_api_cart_errors', function ( \WP_Error $errors, \WC_Cart $cart ): void {
			$error = $this->cart_error( $cart );
			if ( null !== $error ) {
				$errors->add( 'managed_cart_invalid', $error );
			}
		}, 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', function ( array $data, \WP_Error $errors ): void {
			$country = ! empty( $data['ship_to_different_address'] ) ? ( $data['shipping_country'] ?? '' ) : ( $data['billing_country'] ?? '' );
			$error = $this->cart_error( WC()->cart, $country );
			if ( null !== $error ) {
				$errors->add( 'managed_cart_invalid', $error );
			}
		}, 10, 2 );
		add_filter( 'woocommerce_countries_shipping_countries', function ( array $countries ): array {
			$destinations = $this->destinations( WC()->cart?->get_cart() ?? array() );
			return null === $destinations ? $countries : array_intersect_key( $countries, array_flip( $destinations ) );
		} );
		add_filter( 'woocommerce_cart_shipping_packages', function ( array $packages ): array {
			if ( ! $this->store->binding() ) {
				return $packages;
			}
			foreach ( $packages as $key => $package ) {
				// Woo removes product objects before hashing cached rates. Include source generations.
				$packages[$key]['spacefast_catalog'] = array_map( static fn ( array $item ): int => (int) $item['data']->get_meta( '_spacefast_catalog_generation' ), $package['contents'] );
			}
			return $packages;
		} );
		add_filter( 'woocommerce_package_rates', function ( array $rates, array $package ): array {
			$destinations = $this->destinations( $package['contents'] );
			if ( null === $destinations ) {
				return $rates;
			}
			if ( ! in_array( $package['destination']['country'] ?? '', $destinations, true ) ) {
				return array();
			}
			return array( 'spacefast_included' => new \WC_Shipping_Rate( 'spacefast_included', 'Shipping included', 0, array(), 'spacefast_included' ) );
		}, PHP_INT_MAX, 2 );
	}

	private function managed( \WC_Product $product ): bool {
		return '' !== (string) $product->get_meta( '_spacefast_product_key' ) &&
			$product->get_meta( '_spacefast_space_id' ) === ( $this->store->binding()['space_id'] ?? null );
	}

	/** Null leaves ordinary unbound Woo alone; an empty list fails closed on missing source policy. */
	private function destinations( array $items ): ?array {
		if ( ! $this->store->binding() ) {
			return null;
		}
		$result = null;
		foreach ( $items as $item ) {
			$product = $item['data'];
			if ( ! $this->managed( $product ) ) {
				return array();
			}
			if ( $product->is_virtual() ) {
				continue;
			}
			$shipping = $product->get_meta( '_spacefast_shipping' );
			$countries = is_array( $shipping ) && true === ( $shipping['included'] ?? null ) && is_array( $shipping['allowedCountries'] ?? null )
				? $shipping['allowedCountries'] : array();
			foreach ( $countries as $country ) {
				if ( ! is_string( $country ) || ! isset( WC()->countries->get_countries()[$country] ) ) {
					return array();
				}
			}
			$result = null === $result ? $countries : array_values( array_intersect( $result, $countries ) );
		}
		return $result;
	}

	private function cart_error( ?\WC_Cart $cart, ?string $country = null ): ?string {
		if ( ! $cart || ! $this->store->binding() || $cart->is_empty() ) {
			return null;
		}
		$items = $cart->get_cart();
		if ( count( $items ) !== 1 ) {
			return 'Checkout one product at a time.';
		}
		$item = current( $items );
		if ( 1.0 !== (float) $item['quantity'] || ! $this->managed( $item['data'] ) ) {
			return 'Checkout requires one source-managed product with quantity one.';
		}
		$destinations = $this->destinations( $items );
		$country ??= WC()->customer?->get_shipping_country() ?? '';
		return null !== $destinations && ( array() === $destinations || ( '' !== $country && ! in_array( $country, $destinations, true ) ) )
			? 'This product cannot be shipped to this destination.' : null;
	}
}
