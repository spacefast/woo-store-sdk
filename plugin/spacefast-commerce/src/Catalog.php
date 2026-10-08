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
			register_rest_route( 'spacefast-commerce/v1', '/products/(?P<key>[a-z0-9][a-z0-9_-]{0,63})', array(
				'methods' => 'PUT',
				'permission_callback' => array( $this->store, 'authorize' ),
				'callback' => array( $this, 'write' ),
			) );
		} );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		$products = wc_get_products( array(
			'limit' => -1,
			'status' => array( 'publish', 'draft', 'private' ),
			'meta_key' => '_spacefast_space_id',
			'meta_value' => $this->store->binding()['space_id'],
		) );
		return new \WP_REST_Response( array( 'data' => array_map( array( $this, 'projection' ), $products ) ) );
	}

	private function projection( \WC_Product $product ): array {
		return array(
			'id' => $product->get_id(),
			'key' => $product->get_meta( '_spacefast_product_key' ),
			'name' => $product->get_name(),
			'description' => $product->get_description(),
			'price' => $product->get_regular_price(),
			'enabled' => 'publish' === $product->get_status(),
			'kind' => $product->is_virtual() ? 'digital' : 'physical',
			'generation' => (int) $product->get_meta( '_spacefast_catalog_generation' ),
		);
	}

	/** The pipeline supplies a monotonic deployment generation, reused on retry. */
	public function write( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'generation', 'name', 'description', 'price', 'enabled', 'kind', 'downloads', 'download_limit', 'download_expiry' ) ) ||
			! is_int( $input['generation'] ?? null ) || $input['generation'] < 1 ||
			! is_string( $input['name'] ?? null ) || '' === trim( $input['name'] ) || strlen( $input['name'] ) > 255 ||
			! is_string( $input['description'] ?? null ) || strlen( $input['description'] ) > 65536 ||
			! is_string( $input['price'] ?? null ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $input['price'] ) ||
			! is_bool( $input['enabled'] ?? null ) || ! in_array( $input['kind'] ?? null, array( 'digital', 'physical' ), true ) ) {
			return new \WP_Error( 'catalog_invalid', 'Product fields or deployment generation are invalid.', array( 'status' => 422 ) );
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
		global $wpdb;
		$lock = 'sf_catalog_' . substr( hash( 'sha256', $this->store->binding()['store_id'] ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return new \WP_Error( 'catalog_busy', 'Another deployment is applying this catalog. Retry.', array( 'status' => 409 ) );
		}
		try {
			// Read under the installation lock; concurrent creates cannot duplicate a managed key.
			$generation = (int) get_option( 'spacefast_commerce_catalog_generation', 0 );
			if ( $input['generation'] < $generation ) {
				return new \WP_Error( 'catalog_stale', 'A newer deployment owns this catalog.', array( 'status' => 409 ) );
			}
			// Fence before mutation so a partial newer deployment cannot be undone by an older one.
			update_option( 'spacefast_commerce_catalog_generation', $input['generation'], false );
			$found = wc_get_products( array(
				'limit' => 2,
				'status' => array( 'publish', 'draft', 'private' ),
				'meta_query' => array(
					array( 'key' => '_spacefast_space_id', 'value' => $this->store->binding()['space_id'] ),
					array( 'key' => '_spacefast_product_key', 'value' => $request['key'] ),
				),
			) );
			if ( count( $found ) > 1 ) {
				return new \WP_Error( 'catalog_identity_conflict', 'Managed product identity is duplicated.', array( 'status' => 409 ) );
			}
			$product = $found[0] ?? new \WC_Product_Simple();
			$this->managed->apply( function () use ( $product, $input, $request, $downloads ): void {
				$product->set_name( $input['name'] );
				$product->set_description( wp_kses_post( $input['description'] ) );
				$product->set_short_description( '' );
				$product->set_regular_price( $input['price'] );
				$product->set_sale_price( '' );
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
				$product->update_meta_data( '_spacefast_catalog_generation', $input['generation'] );
				$product->save();
			} );
			return new \WP_REST_Response( array( 'data' => $this->projection( $product ) ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
