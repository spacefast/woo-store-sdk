<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Executes deploy-owned catalog writes. This plugin never computes a desired-state diff. */
final class Catalog {
	public function __construct( private Store $store, private ManagedProducts $managed, private PrivateFiles $files ) {}

	public function register(): void {
		add_action( 'rest_api_init', function (): void {
			register_rest_route( 'spacefast-commerce/v1', '/products', array(
				'methods' => 'GET',
				'permission_callback' => array( $this->store, 'authorize' ),
				'callback' => array( $this, 'read' ),
			) );
			register_rest_route( 'spacefast-commerce/v1', '/catalog/fence', array(
				'methods' => 'PUT',
				'permission_callback' => array( $this->store, 'authorize' ),
				'callback' => array( $this, 'fence' ),
			) );
			register_rest_route( 'spacefast-commerce/v1', '/products/(?P<key>[a-z0-9][a-z0-9_-]{0,63})', array(
				'methods' => 'PUT',
				'permission_callback' => array( $this->store, 'authorize' ),
				'callback' => array( $this, 'write' ),
			) );
		} );
	}

	/** Even an empty or unchanged desired catalog must retire older in-flight writes. */
	public function fence( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_keys( $input ) !== array( 'generation' ) || ! is_int( $input['generation'] ) || $input['generation'] < 1 ) {
			return new \WP_Error( 'catalog_invalid', 'Supply a positive catalog generation.', array( 'status' => 422 ) );
		}
		return $this->with_generation( $input['generation'], static fn (): \WP_REST_Response => new \WP_REST_Response( array( 'data' => $input ) ) );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$keys = $request->get_param( 'keys' ) ?? array();
		if ( ! is_array( $keys ) || ! array_is_list( $keys ) || count( $keys ) > 100 ) {
			return new \WP_Error( 'catalog_invalid', 'Supply at most 100 desired product keys.', array( 'status' => 422 ) );
		}
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key ) ) {
				return new \WP_Error( 'catalog_invalid', 'A desired product key is invalid.', array( 'status' => 422 ) );
			}
		}
		if ( count( $keys ) !== count( array_unique( $keys ) ) ) {
			return new \WP_Error( 'catalog_invalid', 'Desired product keys must be distinct.', array( 'status' => 422 ) );
		}
		// Retired history is not a desired-state scan. Include active rows and explicitly desired keys.
		$products = $this->products( array(
			'limit' => 101,
			'status' => array( 'publish', 'private' ),
			'meta_query' => array(
				array( 'key' => '_spacefast_space_id', 'value' => $this->store->binding()['space_id'] ),
				array( 'key' => '_spacefast_product_key', 'compare' => 'EXISTS' ),
			),
		) );
		if ( count( $products ) > 100 ) {
			return new \WP_Error( 'catalog_limit_exceeded', 'The active managed catalog exceeds 100 products.', array( 'status' => 409 ) );
		}
		if ( $keys ) {
			$desired = $this->products( array(
				'limit' => 101,
				'status' => array( 'publish', 'draft', 'private' ),
				'meta_query' => array(
					array( 'key' => '_spacefast_space_id', 'value' => $this->store->binding()['space_id'] ),
					array( 'key' => '_spacefast_product_key', 'value' => $keys, 'compare' => 'IN' ),
				),
			) );
			if ( count( $desired ) > 100 ) {
				return new \WP_Error( 'catalog_identity_conflict', 'Desired managed product keys are duplicated.', array( 'status' => 409 ) );
			}
			$products = array_merge( $products, $desired );
		}
		$unique = array();
		foreach ( $products as $product ) {
			$unique[$product->get_id()] = $product;
		}
		return new \WP_REST_Response( array( 'data' => array( 'currency' => get_woocommerce_currency(), 'products' => array_values( array_map( array( $this, 'projection' ), $unique ) ) ) ) );
	}

	private function projection( \WC_Product $product ): array {
		return array(
			'id' => $product->get_id(),
			'key' => $product->get_meta( '_spacefast_product_key' ),
			'name' => $product->get_name(),
			'description' => $product->get_description(),
			'cover_image' => $this->cover_source( (int) $product->get_image_id() ),
			'gallery_image_ids' => $product->get_gallery_image_ids(),
			'price' => $product->get_regular_price(),
			'sale_price' => $product->get_sale_price(),
			'date_on_sale_from' => $product->get_date_on_sale_from()?->getTimestamp(),
			'date_on_sale_to' => $product->get_date_on_sale_to()?->getTimestamp(),
			'currency' => get_woocommerce_currency(),
			'type' => $product->get_type(),
			'status' => $product->get_status(),
			'short_description' => $product->get_short_description(),
			'sku' => $product->get_sku(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'sold_individually' => $product->is_sold_individually(),
			'manage_stock' => $product->get_manage_stock(),
			'downloads' => $this->files->declarations( $product ),
			'download_limit' => $product->get_download_limit(),
			'download_expiry' => $product->get_download_expiry(),
			'shipping' => $product->is_virtual() ? null : $product->get_meta( '_spacefast_shipping' ),
			'enabled' => 'publish' === $product->get_status(),
			'kind' => $product->is_virtual() ? 'digital' : 'physical',
			'generation' => (int) $product->get_meta( '_spacefast_catalog_generation' ),
		);
	}

	/** The pipeline supplies a monotonic deployment generation, reused on retry. */
	public function write( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = $request->get_json_params();
		if ( is_array( $input ) && 'archive' === ( $input['action'] ?? null ) ) {
			if ( array_diff( array_keys( $input ), array( 'generation', 'action' ) ) || ! is_int( $input['generation'] ?? null ) || $input['generation'] < 1 ) {
				return new \WP_Error( 'catalog_invalid', 'Supply an archive action and positive generation.', array( 'status' => 422 ) );
			}
			return $this->with_generation( $input['generation'], function () use ( $request, $input ): \WP_REST_Response|\WP_Error {
				$found = $this->find( $request['key'] );
				if ( is_wp_error( $found ) ) {
					return $found;
				}
				if ( ! $found ) {
					return new \WP_REST_Response( array( 'data' => null ) );
				}
				$product = $found[0];
				$this->managed->apply( static function () use ( $product, $input ): void {
					$product->set_status( 'draft' );
					$product->update_meta_data( '_spacefast_catalog_generation', $input['generation'] );
					$product->save();
				} );
				return new \WP_REST_Response( array( 'data' => $this->projection( $product ) ) );
			} );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'generation', 'name', 'description', 'cover_image', 'price', 'enabled', 'kind', 'downloads', 'download_limit', 'download_expiry', 'shipping' ) ) ||
			! is_int( $input['generation'] ?? null ) || $input['generation'] < 1 ||
			! is_string( $input['name'] ?? null ) || '' === trim( $input['name'] ) || strlen( $input['name'] ) > 255 ||
			! is_string( $input['description'] ?? null ) || strlen( $input['description'] ) > 65536 ||
			! is_string( $input['price'] ?? null ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $input['price'] ) ||
			! is_bool( $input['enabled'] ?? null ) || ! in_array( $input['kind'] ?? null, array( 'digital', 'physical' ), true ) ) {
			return new \WP_Error( 'catalog_invalid', 'Product fields or deployment generation are invalid.', array( 'status' => 422 ) );
		}
		if ( sanitize_text_field( $input['name'] ) !== $input['name'] || wp_kses_post( $input['description'] ) !== $input['description'] ) {
			return new \WP_Error( 'catalog_invalid', 'Use a plain product name and safe description HTML.', array( 'status' => 422 ) );
		}
		if ( ! array_key_exists( 'cover_image', $input ) || ( null !== $input['cover_image'] && (
			! is_string( $input['cover_image'] ) || strlen( $input['cover_image'] ) > 2048 ||
			'https' !== wp_parse_url( $input['cover_image'], PHP_URL_SCHEME ) ||
			wp_parse_url( $input['cover_image'], PHP_URL_USER ) || wp_parse_url( $input['cover_image'], PHP_URL_PASS )
		) ) ) {
			return new \WP_Error( 'catalog_invalid', 'Supply a public HTTPS cover image URL or null.', array( 'status' => 422 ) );
		}
		$shipping = $input['shipping'] ?? null;
		if ( ( 'digital' === $input['kind'] && null !== $shipping ) || ( 'physical' === $input['kind'] && (
			! is_array( $shipping ) || array_diff( array_keys( $shipping ), array( 'included', 'allowedCountries', 'policy' ) ) ||
			true !== ( $shipping['included'] ?? null ) || ! is_array( $shipping['allowedCountries'] ?? null ) ||
			! array_is_list( $shipping['allowedCountries'] ) || count( $shipping['allowedCountries'] ) < 1 || count( $shipping['allowedCountries'] ) > 250 ||
			! is_string( $shipping['policy'] ?? null ) || '' === trim( $shipping['policy'] ) || strlen( $shipping['policy'] ) > 1200
		) ) ) {
			return new \WP_Error( 'catalog_invalid', 'Physical products require an included-shipping policy and destinations.', array( 'status' => 422 ) );
		}
		if ( null !== $shipping ) {
			$countries = WC()->countries->get_countries();
			$seen = array();
			foreach ( $shipping['allowedCountries'] as $country ) {
				if ( ! is_string( $country ) || ! isset( $countries[$country] ) || isset( $seen[$country] ) ) {
					return new \WP_Error( 'catalog_invalid', 'Shipping destinations must be distinct supported countries.', array( 'status' => 422 ) );
				}
				$seen[$country] = true;
			}
		}
		$downloads = $this->files->resolve( $input['downloads'] ?? array() );
		if ( is_wp_error( $downloads ) ) {
			return $downloads;
		}
		if ( $input['enabled'] && 'digital' === $input['kind'] && array() === $downloads ) {
			return new \WP_Error( 'catalog_invalid', 'An enabled digital product requires a privately ingested download.', array( 'status' => 422 ) );
		}
		foreach ( array( 'download_limit', 'download_expiry' ) as $field ) {
			if ( isset( $input[$field] ) && ( ! is_int( $input[$field] ) || $input[$field] < -1 || $input[$field] > 100000 ) ) {
				return new \WP_Error( 'catalog_invalid', 'Download limit or expiry is invalid.', array( 'status' => 422 ) );
			}
		}
		return $this->with_generation( $input['generation'], function () use ( $request, $input, $downloads, $shipping ): \WP_REST_Response|\WP_Error {
			$found = $this->find( $request['key'] );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$product = $found ? new \WC_Product_Simple( $found[0]->get_id() ) : new \WC_Product_Simple();
			$image_id = $this->cover_attachment( $product, $input['cover_image'] );
			if ( is_wp_error( $image_id ) ) {
				return $image_id;
			}
			$this->managed->apply( function () use ( $product, $input, $request, $downloads, $shipping, $image_id ): void {
				$product->set_name( $input['name'] );
				$product->set_description( wp_kses_post( $input['description'] ) );
				$product->set_short_description( '' );
				$product->set_image_id( $image_id );
				$product->set_gallery_image_ids( array() );
				$product->update_meta_data( '_spacefast_cover_attachment', $image_id );
				$product->set_sku( '' );
				$product->set_catalog_visibility( 'visible' );
				$product->set_regular_price( $input['price'] );
				$product->set_sale_price( '' );
				$product->set_date_on_sale_from( null );
				$product->set_date_on_sale_to( null );
				$product->set_price( $input['price'] );
				$product->set_status( $input['enabled'] ? 'publish' : 'draft' );
				$product->set_virtual( 'digital' === $input['kind'] );
				$history = $product->get_meta( '_spacefast_download_history' );
				$history = is_array( $history ) ? $history : array();
				foreach ( $downloads as $id => $file ) {
					$history[$id] = array( 'name' => $file->get_name(), 'file' => $file->get_file() );
				}
				$product->update_meta_data( '_spacefast_download_history', $history );
				$product->set_downloads( $downloads );
				// Historical grants remain readable even after changing product kind.
				$product->set_downloadable( (bool) $downloads || (bool) $history );
				$product->set_download_limit( $input['download_limit'] ?? -1 );
				$product->set_download_expiry( $input['download_expiry'] ?? -1 );
				$product->set_sold_individually( true );
				$product->set_manage_stock( false );
				$product->update_meta_data( '_spacefast_space_id', $this->store->binding()['space_id'] );
				$product->update_meta_data( '_spacefast_product_key', $request['key'] );
				$product->update_meta_data( '_spacefast_shipping', $shipping );
				$product->update_meta_data( '_spacefast_catalog_generation', $input['generation'] );
				$product->save();
			} );
			return new \WP_REST_Response( array( 'data' => $this->projection( $product ) ) );
		} );
	}

	/** Native media owns the bytes and thumbnails; URL declarations never become browser bypass flags. */
	private function cover_source( int $image_id ): ?string {
		$file = $image_id ? get_attached_file( $image_id ) : false;
		$source = $image_id ? get_post_meta( $image_id, '_spacefast_cover_source', true ) : '';
		return is_string( $source ) && '' !== $source && $file && is_file( $file ) ? $source : null;
	}

	private function cover_attachment( \WC_Product $product, ?string $source ): int|\WP_Error {
		if ( null === $source ) {
			return 0;
		}
		$previous = (int) $product->get_meta( '_spacefast_cover_attachment' );
		if ( $previous && $source === $this->cover_source( $previous ) ) {
			return $previous;
		}
		$upload = wc_rest_upload_image_from_url( $source );
		if ( is_wp_error( $upload ) ) {
			return new \WP_Error( 'catalog_cover_unavailable', 'The declared cover image could not be installed: ' . $upload->get_error_message(), array( 'status' => 503 ) );
		}
		$image_id = wc_rest_set_uploaded_image_as_attachment( $upload, $product->get_id() );
		if ( ! $image_id ) {
			return new \WP_Error( 'catalog_cover_unavailable', 'The declared cover image attachment could not be created.', array( 'status' => 503 ) );
		}
		update_post_meta( $image_id, '_spacefast_cover_source', $source );
		return $image_id;
	}

	/** Woo ignores arbitrary meta_query arguments; its native query extension owns this clause. */
	private function products( array $args ): array {
		$filter = static function ( array $query, array $vars ) use ( $args ): array {
			if ( true === ( $vars['spacefast_catalog_query'] ?? false ) ) {
				$query['meta_query'] = array( 'relation' => 'AND', $query['meta_query'] ?? array(), $args['meta_query'] );
			}
			return $query;
		};
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $filter, 10, 2 );
		try {
			return wc_get_products( array_merge( $args, array( 'spacefast_catalog_query' => true ) ) );
		} finally {
			remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $filter, 10 );
		}
	}

	private function find( string $key ): array|\WP_Error {
		$found = $this->products( array(
			'limit' => 2,
			'status' => array( 'publish', 'draft', 'private' ),
			'meta_query' => array(
				array( 'key' => '_spacefast_space_id', 'value' => $this->store->binding()['space_id'] ),
				array( 'key' => '_spacefast_product_key', 'value' => $key ),
			),
		) );
		return count( $found ) > 1 ? new \WP_Error( 'catalog_identity_conflict', 'Managed product identity is duplicated.', array( 'status' => 409 ) ) : $found;
	}

	private function with_generation( int $generation, callable $write ): \WP_REST_Response|\WP_Error {
		global $wpdb;
		$lock = 'sf_catalog_' . substr( hash( 'sha256', $this->store->binding()['store_id'] ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return new \WP_Error( 'catalog_busy', 'Another deployment is applying this catalog. Retry.', array( 'status' => 409 ) );
		}
		try {
			if ( get_woocommerce_currency() !== $this->store->binding()['currency'] ) {
				return new \WP_Error( 'catalog_currency_conflict', 'Restore the configured merchant currency before applying a catalog.', array( 'status' => 409 ) );
			}
			if ( $generation < (int) get_option( 'spacefast_commerce_catalog_generation', 0 ) ) {
				return new \WP_Error( 'catalog_stale', 'A newer deployment owns this catalog.', array( 'status' => 409 ) );
			}
			// Fence before mutation so a partial newer apply cannot be undone by an older one.
			update_option( 'spacefast_commerce_catalog_generation', $generation, false );
			return $write();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
