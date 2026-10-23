<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * The catalog planner, ported from the control plane's `commerce/catalog.ts` (D2): stable keys,
 * update in place, owned-field drift repair. It is pure: Catalog reads the native projections,
 * this decides, Catalog writes. The removal rule is D2's baseline rule instead of archiving.
 */
final class Planner {
	/** Stripe charge units, kept from catalog.ts's amount.ts so a price means the same thing on both sides. */
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
	private const WHOLE_ONLY = array( 'ISK', 'UGX' );

	/** Fields the declaration owns. A native difference in any of them is drift that apply repairs. */
	public const OWNED = array(
		'name', 'description', 'short_description', 'image', 'gallery_image_ids', 'price', 'sale_price',
		'date_on_sale_from', 'date_on_sale_to', 'type', 'status', 'sku', 'catalog_visibility',
		'sold_individually', 'manage_stock', 'kind', 'downloads', 'download_limit', 'download_expiry', 'page',
	);

	public static function price_decimal( int $amount_minor, string $currency ): string|\WP_Error {
		$currency = strtoupper( $currency );
		if ( $amount_minor <= 0 || ( in_array( $currency, self::WHOLE_ONLY, true ) && 0 !== $amount_minor % 100 ) ) {
			return new \WP_Error( 'price-invalid', 'The price cannot be charged in this currency.' );
		}
		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return (string) $amount_minor;
		}
		return intdiv( $amount_minor, 100 ) . '.' . str_pad( (string) ( $amount_minor % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/** Woo stores "12.500" and "12.5" for the same price; compare the number, not the spelling. */
	public static function decimal( string $value ): string {
		if ( ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value ) ) {
			return $value;
		}
		return str_contains( $value, '.' ) ? rtrim( rtrim( $value, '0' ), '.' ) : $value;
	}

	public static function description_html( ?string $text ): string {
		return null === $text || '' === trim( $text ) ? '' : trim( wpautop( esc_html( $text ) ) );
	}

	/** Media identity: the content hash for bundle files, the URL for remote images. */
	public static function media_identity( ?array $ref ): ?string {
		if ( null === $ref ) {
			return null;
		}
		return isset( $ref['url'] ) ? 'url:' . $ref['url'] : 'sha256:' . $ref['sha256'];
	}

	/** The desired native projection of one declared product (catalog.ts `commerceProductsFromSource`). */
	public static function desired( array $product, int $index, array $merchant ): array {
		return array(
			'key' => $product['key'],
			'name' => $product['name'],
			'description' => self::description_html( $product['description'] ),
			'short_description' => $product['summary'] ?? '',
			'image' => self::media_identity( $product['image'] ),
			'gallery_image_ids' => array(),
			'price' => (string) self::price_decimal( $product['price']['amountMinor'], $merchant['currency'] ),
			'sale_price' => '',
			'date_on_sale_from' => null,
			'date_on_sale_to' => null,
			'type' => 'simple',
			'status' => $product['active'] ? 'publish' : 'draft',
			'sku' => $product['key'],
			'catalog_visibility' => 'visible',
			// Digital stays sold individually; physical quantity is the shopper's (D3).
			'sold_individually' => 'digital' === $product['kind'],
			'manage_stock' => 'physical' === $product['kind'] && true === ( $product['stock']['track'] ?? false ),
			'kind' => $product['kind'],
			'downloads' => null === $product['asset'] ? array() : array( array( 'sha256' => $product['asset']['sha256'], 'filename' => sanitize_file_name( basename( $product['asset']['path'] ) ) ) ),
			'download_limit' => $merchant['downloads']['limit'] ?? -1,
			'download_expiry' => $merchant['downloads']['expiryDays'] ?? -1,
			'page' => $product['page'],
			'menu_order' => $index,
			'stock_initial' => $product['stock']['initial'] ?? null,
		);
	}

	/**
	 * Plan one apply. `$current` holds native projections of every managed product, including
	 * inactive ones. `$baseline` is the live version's keys (`--baseline`), an empty list for
	 * `--no-live-catalog`, or null to use every currently managed product (M2 hosts).
	 *
	 * @return array{changes: list<array{key: string, action: string}>, deactivate: list<string>, warnings: list<array>}|\WP_Error
	 */
	public static function plan( array $desired, array $current, string $origin, ?array $baseline, string $currency, string $store_currency, bool $currency_locked ): array|\WP_Error {
		$by_key = array();
		foreach ( $current as $native ) {
			if ( isset( $by_key[ $native['key'] ] ) ) {
				return Bundle::refuse( 'identity-conflict', 'Two managed products in the store share the key "' . $native['key'] . '". Remove the duplicate in WooCommerce.', null, $native['key'] );
			}
			$by_key[ $native['key'] ] = $native;
		}
		$target = array();
		foreach ( $desired as $index => $product ) {
			if ( isset( $target[ $product['key'] ] ) ) {
				return Bundle::refuse( 'duplicate-key', 'Product key "' . $product['key'] . '" is declared twice.', '/products/' . $index . '/key', $product['key'] );
			}
			$target[ $product['key'] ] = true;
		}
		if ( $currency_locked && strtoupper( $currency ) !== strtoupper( $store_currency ) ) {
			return Bundle::refuse( 'currency-mismatch', 'The store sells in ' . strtoupper( $store_currency ) . ' and already has products or orders; the declaration says ' . $currency . '.', '/merchant/currency' );
		}
		$baseline ??= array_map( 'strval', array_keys( $by_key ) );
		$deactivate = array();
		foreach ( $baseline as $key ) {
			if ( isset( $target[ $key ] ) ) {
				continue;
			}
			if ( 'deploy' === $origin ) {
				return Bundle::refuse( 'product-removed', 'Product "' . $key . '" is live but missing from the declaration. Set "active": false to stop selling it; removing a product is not supported.', '/products', $key );
			}
			$deactivate[ $key ] = true;
		}
		// Leftovers from superseded or failed activations, outside both target and baseline (D2 "Serialization").
		foreach ( array_keys( $by_key ) as $key ) {
			if ( ! isset( $target[ $key ] ) ) {
				$deactivate[ $key ] = true;
			}
		}
		$changes = array();
		$warnings = array();
		foreach ( $desired as $index => $product ) {
			$native = $by_key[ $product['key'] ] ?? null;
			if ( null === $native ) {
				$changes[] = array( 'key' => $product['key'], 'action' => 'created' );
				continue;
			}
			$changes[] = array( 'key' => $product['key'], 'action' => self::owned( $native ) === self::owned( $product ) && $native['menu_order'] === $product['menu_order'] ? 'unchanged' : 'updated' );
			if ( $product['manage_stock'] && null !== $product['stock_initial'] && $product['stock_initial'] !== $native['stock_initial'] ) {
				$warnings[] = array(
					'code' => 'stock-initial-ignored',
					'detail' => 'stock.initial for "' . $product['key'] . '" only applies when the product is created. Manage stock levels in WooCommerce.',
					'pointer' => '/products/' . $index . '/stock/initial',
				);
			}
		}
		$deactivated = array();
		ksort( $deactivate, SORT_STRING );
		foreach ( array_keys( $deactivate ) as $key ) {
			$key = (string) $key;
			if ( isset( $by_key[ $key ] ) && 'draft' !== $by_key[ $key ]['status'] ) {
				$changes[] = array( 'key' => $key, 'action' => 'deactivated' );
				$deactivated[] = $key;
			}
		}
		return array( 'changes' => $changes, 'deactivate' => $deactivated, 'warnings' => $warnings );
	}

	private static function owned( array $product ): array {
		$owned = array_intersect_key( $product, array_flip( self::OWNED ) );
		$owned['price'] = self::decimal( (string) $owned['price'] );
		ksort( $owned );
		return $owned;
	}
}
