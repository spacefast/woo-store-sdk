<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * `wp spacefast-commerce apply` (D2): one idempotent, serialized apply of a store bundle.
 * Every check that can refuse runs before the first write, so a refusal changes nothing.
 */
final class Catalog {
	public function __construct(
		private Store $store,
		private ManagedProducts $managed,
		private PrivateFiles $files,
		private Media $media,
		private Shipping $shipping,
		private Storefront $storefront,
		private Emails $emails,
	) {}

	/**
	 * @param array{origin: string, baseline: ?array, canonical_origin: ?string} $options
	 */
	public function apply( string $directory, array $options ): array|\WP_Error {
		return $this->store->with_lock( function () use ( $directory, $options ): array|\WP_Error {
			try {
				return $this->apply_locked( $directory, $options );
			} finally {
				$this->media->cleanup();
			}
		} );
	}

	/** Removing the store from a version disables sales, while native orders and buyer grants remain. */
	public function disable(): array|\WP_Error {
		return $this->store->with_lock( function (): array|\WP_Error {
			$current = $this->current();
			$counter = $this->store->next_counter();
			$changes = $this->managed->apply( function () use ( $current ): array|\WP_Error {
				$changes = array();
				foreach ( $current as $native ) {
					$product = wc_get_product( $native['id'] );
					if ( ! $product ) {
						return Bundle::refuse( 'apply-failed', 'A managed product disappeared while disabling the store. Retry.', null, $native['key'] );
					}
					$action = 'unchanged';
					if ( 'draft' !== $product->get_status( 'edit' ) ) {
						try {
							$product->set_status( 'draft' );
							$product->save();
						} catch ( \Exception $error ) {
							return Bundle::refuse( 'apply-failed', 'The managed product was not disabled: ' . $error->getMessage() . ' Retry.', null, $native['key'] );
						}
						$action = 'deactivated';
					}
					$changes[] = array( 'key' => $native['key'], 'action' => $action );
				}
				return $changes;
			} );
			if ( is_wp_error( $changes ) ) {
				return $changes;
			}
			delete_option( 'spacefast_commerce_store_hash' );
			return array( 'ok' => true, 'counter' => $counter, 'storeHash' => null, 'changes' => $changes, 'warnings' => array() );
		} );
	}

	private function apply_locked( string $directory, array $options ): array|\WP_Error {
		if ( null !== $options['canonical_origin'] ) {
			$origin = Store::normalize_origin( $options['canonical_origin'] );
			if ( is_wp_error( $origin ) ) {
				return $origin;
			}
		}
		$bundle = Bundle::open( $directory );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		$store = $bundle->store;
		$merchant = $store['merchant'];
		$current = $this->current();
		$order_count = Orders::count();
		if ( is_wp_error( $order_count ) ) {
			return $order_count;
		}
		$desired = array();
		foreach ( $store['products'] as $index => $product ) {
			$desired[] = Planner::desired( $product, $index, $merchant );
		}
		$plan = Planner::plan(
			$desired,
			array_values( $current ),
			$options['origin'],
			$options['baseline'],
			$merchant['currency'],
			get_woocommerce_currency(),
			array() !== $current || $order_count > 0,
		);
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$refusal = $this->refusals( $bundle, $current );
		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}

		// First write. Anything failing from here on is an infrastructure fault; apply is idempotent, so retry.
		$counter = $this->store->next_counter();
		if ( null !== $options['canonical_origin'] ) {
			$this->store->set_canonical_origin( $options['canonical_origin'] );
		}
		$written = $this->managed->apply( function () use ( $bundle, $plan, $desired, $current ): true|\WP_Error {
			$result = $this->apply_store( $bundle );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$actions = array_column( $plan['changes'], 'action', 'key' );
			foreach ( $desired as $index => $product ) {
				if ( 'unchanged' === $actions[ $product['key'] ] ) {
					continue;
				}
				$result = $this->write( $product, $bundle->store['products'][ $index ], $bundle, $current[ $product['key'] ] ?? null );
				if ( is_wp_error( $result ) ) {
					return Bundle::refuse( 'apply-failed', 'Product "' . $product['key'] . '" was not written: ' . $result->get_error_message() . ' Retry the apply.', '/products/' . $index, $product['key'] );
				}
			}
			foreach ( $plan['deactivate'] as $key ) {
				$product = wc_get_product( $current[ $key ]['id'] );
				$product->set_status( 'draft' );
				$product->save();
			}
			return true;
		} );
		if ( is_wp_error( $written ) ) {
			$data = $written->get_error_data();
			return Bundle::refuse( 'apply-failed', $written->get_error_message() . ' Retry the apply.', is_array( $data ) ? ( $data['pointer'] ?? null ) : null, is_array( $data ) ? ( $data['key'] ?? null ) : null );
		}
		update_option( 'spacefast_commerce_store_hash', $bundle->hash, false );
		return array(
			'ok' => true,
			'counter' => $counter,
			'storeHash' => $bundle->hash,
			'changes' => $plan['changes'],
			'warnings' => $plan['warnings'],
		);
	}

	/** Refusals that need the store: SKU clashes, pages, and every referenced file. */
	private function refusals( Bundle $bundle, array $current ): true|\WP_Error {
		$store = $bundle->store;
		foreach ( $store['products'] as $index => $product ) {
			$owner = wc_get_product_id_by_sku( $product['key'] );
			if ( $owner && ManagedProducts::key( $owner ) !== $product['key'] ) {
				return Bundle::refuse( 'sku-clash', 'Another WooCommerce product (#' . $owner . ') already uses the SKU "' . $product['key'] . '". Change that product\'s SKU or pick another key.', '/products/' . $index . '/key', $product['key'] );
			}
		}
		$pages = $this->storefront->check_pages();
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}
		$checks = array( fn () => $this->media->prepare( $store['merchant']['brand']['logo'], $bundle, '/merchant/brand/logo' ) );
		foreach ( $store['products'] as $index => $product ) {
			$checks[] = fn () => $this->media->prepare( $product['image'], $bundle, '/products/' . $index . '/image' );
			if ( null !== $product['asset'] ) {
				$checks[] = fn () => $this->files->prepare( $product['asset'], $bundle, '/products/' . $index . '/asset' );
			}
		}
		foreach ( $checks as $check ) {
			$result = $check();
			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				$key = null;
				if ( is_array( $data ) && preg_match( '#^/products/(\d+)/#', (string) ( $data['pointer'] ?? '' ), $match ) ) {
					$key = $store['products'][ (int) $match[1] ]['key'];
				}
				return Bundle::refuse( $result->get_error_code(), $result->get_error_message(), $data['pointer'] ?? null, $key );
			}
		}
		return true;
	}

	/** Store-level managed fields (D1, D16) and the D3 shipping zone. */
	private function apply_store( Bundle $bundle ): true|\WP_Error {
		$store = $bundle->store;
		$merchant = $store['merchant'];
		update_option( 'blogname', $merchant['name'] );
		update_option( 'woocommerce_currency', strtoupper( $merchant['currency'] ) );
		// Seeded, not managed: keep the merchant's state when the country part is unchanged (D1).
		$country = explode( ':', (string) get_option( 'woocommerce_default_country', '' ) )[0];
		if ( $country !== $merchant['country'] ) {
			update_option( 'woocommerce_default_country', $merchant['country'] );
		}
		$logo = $this->media->attachment( $merchant['brand']['logo'], $bundle );
		if ( is_wp_error( $logo ) ) {
			return $logo;
		}
		$settings = get_option( 'woo_storefront_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$settings['branding'] = array_merge( is_array( $settings['branding'] ?? null ) ? $settings['branding'] : array(), array( 'logo' => $logo ? (string) wp_get_attachment_url( $logo ) : '' ) );
		$settings['branding']['colors'] = array_merge( is_array( $settings['branding']['colors'] ?? null ) ? $settings['branding']['colors'] : array(), array( 'primary' => $merchant['brand']['accent'] ?? '#7f54b3' ) );
		update_option( 'woo_storefront_settings', $settings );
		update_option( 'spacefast_commerce_brand', array( 'name' => $merchant['name'], 'settings' => $settings['branding'] ), false );
		$shipping = $this->shipping->apply( $store['shipping'], $merchant['currency'] );
		if ( is_wp_error( $shipping ) ) {
			return $shipping;
		}
		$this->emails->store( $store['emails'] );
		return $this->storefront->ensure_pages();
	}

	private function write( array $desired, array $declared, Bundle $bundle, ?array $native ): true|\WP_Error {
		$product = $native ? new \WC_Product_Simple( $native['id'] ) : new \WC_Product_Simple();
		$image = $this->media->attachment( $declared['image'], $bundle, $native['id'] ?? 0 );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$downloads = array();
		if ( null !== $declared['asset'] ) {
			$download = $this->files->download( $desired['key'], $declared['asset'], $bundle );
			if ( is_wp_error( $download ) ) {
				return $download;
			}
			$downloads[ $download->get_id() ] = $download;
		}
		try {
			$product->set_props( array(
				'name' => $desired['name'],
				'description' => $desired['description'],
				'short_description' => $desired['short_description'],
				'regular_price' => $desired['price'],
				'sale_price' => '',
				'date_on_sale_from' => null,
				'date_on_sale_to' => null,
				'status' => $desired['status'],
				'sku' => $desired['sku'],
				'catalog_visibility' => 'visible',
				'image_id' => $image,
				'gallery_image_ids' => array(),
				'virtual' => 'digital' === $desired['kind'],
				'downloadable' => 'digital' === $desired['kind'],
				'downloads' => $downloads,
				'download_limit' => $desired['download_limit'],
				'download_expiry' => $desired['download_expiry'],
				'sold_individually' => $desired['sold_individually'],
				'manage_stock' => $desired['manage_stock'],
				'menu_order' => $desired['menu_order'],
			) );
			$product->set_price( $desired['price'] );
			// stock.initial seeds a new product once; Woo owns existing stock even when tracking is toggled.
			if ( null === $native && $desired['manage_stock'] && null !== $desired['stock_initial'] ) {
				$product->set_stock_quantity( $desired['stock_initial'] );
				$product->update_meta_data( '_spacefast_stock_initial', $desired['stock_initial'] );
			}
			if ( ! $desired['manage_stock'] ) {
				$product->set_stock_status( 'instock' );
			}
			$product->update_meta_data( '_spacefast_product_key', $desired['key'] );
			if ( null === $desired['page'] ) {
				$product->delete_meta_data( '_spacefast_page' );
			} else {
				$product->update_meta_data( '_spacefast_page', $desired['page'] );
			}
			$product->save();
		} catch ( \WC_Data_Exception $error ) {
			return new \WP_Error( $error->getErrorCode(), $error->getMessage() );
		}
		if ( $image && ! wp_get_post_parent_id( $image ) ) {
			wp_update_post( array( 'ID' => $image, 'post_parent' => $product->get_id() ) );
		}
		return true;
	}

	/** Native projections of every managed product, any status, keyed by source key. */
	public function current(): array {
		$ids = get_posts( array(
			'post_type' => 'product',
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'fields' => 'ids',
			'numberposts' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
			'suppress_filters' => true,
			'meta_key' => '_spacefast_product_key',
			'meta_compare' => 'EXISTS',
		) );
		$current = array();
		$seen = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$projection = $this->projection( $product );
			// Keep duplicates visible to the planner: it refuses them as an identity conflict.
			$slot = isset( $seen[ $projection['key'] ] ) ? $projection['key'] . '#' . $id : $projection['key'];
			$seen[ $projection['key'] ] = true;
			$current[ $slot ] = $projection;
		}
		return $current;
	}

	public function projection( \WC_Product $product ): array {
		$key = ManagedProducts::key( $product->get_id() );
		$page = $product->get_meta( '_spacefast_page' );
		$initial = $product->get_meta( '_spacefast_stock_initial' );
		return array(
			'id' => $product->get_id(),
			'key' => $key,
			'name' => $product->get_name( 'edit' ),
			'description' => trim( $product->get_description( 'edit' ) ),
			'short_description' => $product->get_short_description( 'edit' ),
			'image' => $this->media->identity_of( (int) $product->get_image_id( 'edit' ) ),
			'gallery_image_ids' => array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ),
			'price' => (string) $product->get_regular_price( 'edit' ),
			'sale_price' => (string) $product->get_sale_price( 'edit' ),
			'date_on_sale_from' => $product->get_date_on_sale_from( 'edit' )?->getTimestamp(),
			'date_on_sale_to' => $product->get_date_on_sale_to( 'edit' )?->getTimestamp(),
			'type' => $product->get_type(),
			'status' => $product->get_status( 'edit' ),
			'sku' => $product->get_sku( 'edit' ),
			'catalog_visibility' => $product->get_catalog_visibility( 'edit' ),
			'sold_individually' => $product->get_sold_individually( 'edit' ),
			'manage_stock' => $product->get_manage_stock( 'edit' ),
			'kind' => $product->get_virtual( 'edit' ) ? 'digital' : 'physical',
			'downloads' => $this->files->declarations( $product, $key ),
			'download_limit' => $product->get_download_limit( 'edit' ),
			'download_expiry' => $product->get_download_expiry( 'edit' ),
			'page' => is_string( $page ) && '' !== $page ? $page : null,
			'menu_order' => $product->get_menu_order( 'edit' ),
			'stock_initial' => '' === $initial ? null : (int) $initial,
		);
	}
}
