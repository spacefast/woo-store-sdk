<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Private bytes and historical file references. Woo alone owns buyer permissions. */
final class PrivateFiles {
	public function __construct( private Store $store ) {}

	public function register(): void {
		add_action( 'rest_api_init', function (): void {
			register_rest_route( 'spacefast-commerce/v1', '/files/(?P<sha256>[a-f0-9]{64})', array(
				'methods' => 'PUT',
				'permission_callback' => array( $this->store, 'authorize' ),
				'callback' => array( $this, 'ingest' ),
			) );
		} );
		add_filter( 'woocommerce_product_file', array( $this, 'historical_file' ), 10, 3 );
		add_filter( 'woocommerce_product_file_download_path', array( $this, 'historical_path' ), 10, 3 );
		add_filter( 'woocommerce_product_get_downloads', array( $this, 'native_download_files' ), 10, 2 );
		add_filter( 'woocommerce_file_download_method', function ( string $method, int $product_id ): string {
			return get_post_meta( $product_id, '_spacefast_product_key', true ) ? 'force' : $method;
		}, 10, 2 );
		add_filter( 'pre_option_woocommerce_downloads_redirect_fallback_allowed', fn ( mixed $value ): mixed => $this->store->binding() ? 'no' : $value );
	}

	/** Spacefast owns its protected engine root; other hosts require storage outside the web root. */
	public function root(): string|\WP_Error {
		if ( function_exists( 'spacefast_commerce_runtime_private_root' ) ) {
			$root = spacefast_commerce_runtime_private_root( $this->store->binding() );
			return is_string( $root ) ? $root : new \WP_Error( 'private_storage_invalid', 'Protected runtime storage is unavailable for this store.', array( 'status' => 503 ) );
		}
		$configured = defined( 'SPACEFAST_COMMERCE_PRIVATE_ROOT' ) ? SPACEFAST_COMMERCE_PRIVATE_ROOT : '';
		$public_root = defined( 'SPACEFAST_COMMERCE_PUBLIC_ROOT' ) ? SPACEFAST_COMMERCE_PUBLIC_ROOT : '';
		$public = is_string( $public_root ) && '' !== $public_root ? realpath( $public_root ) : false;
		if ( ! is_string( $configured ) || '' === $configured || '/' !== $configured[0] || ! is_string( $public ) ) {
			return new \WP_Error( 'private_storage_unconfigured', 'Configure a private commerce directory outside the public web root.', array( 'status' => 503 ) );
		}
		// Resolve the existing parent before creating anything; symlinks cannot move bytes into htdocs.
		$parent = realpath( dirname( $configured ) );
		if ( ! is_string( $parent ) || ( ! is_dir( $configured ) && ! is_writable( $parent ) ) || $parent === $public || str_starts_with( $parent, $public . '/' ) || is_link( $configured ) ) {
			return new \WP_Error( 'private_storage_invalid', 'The private storage directory is not protected or writable.', array( 'status' => 503 ) );
		}
		if ( ! is_dir( $configured ) && ! mkdir( $configured, 0700 ) ) {
			return new \WP_Error( 'private_storage_unavailable', 'Private storage could not be created.', array( 'status' => 503 ) );
		}
		$root = realpath( $configured );
		if ( ! is_string( $root ) || $root === $public || str_starts_with( $root, $public . '/' ) || ! is_writable( $root ) ) {
			return new \WP_Error( 'private_storage_invalid', 'Private storage is not protected or writable.', array( 'status' => 503 ) );
		}
		return $root;
	}

	private function filename( mixed $filename ): string|\WP_Error {
		if ( ! is_string( $filename ) || '' === $filename || strlen( $filename ) > 180 ||
			$filename !== sanitize_file_name( $filename ) || basename( $filename ) !== $filename ||
			in_array( $filename, array( '.', '..' ), true ) ) {
			return new \WP_Error( 'private_file_invalid', 'Supply a safe download filename.', array( 'status' => 422 ) );
		}
		return $filename;
	}

	public function ingest( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$filename = $this->filename( rawurldecode( $request->get_header( 'X-Spacefast-Filename' ) ?? '' ) );
		if ( is_wp_error( $filename ) ) {
			return $filename;
		}
		$bytes = $request->get_body();
		if ( strlen( $bytes ) > 256 * 1024 * 1024 || ! hash_equals( $request['sha256'], hash( 'sha256', $bytes ) ) ) {
			return new \WP_Error( 'private_file_integrity', 'Download bytes exceed the limit or do not match their SHA-256.', array( 'status' => 422 ) );
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$directory = $root . '/' . $request['sha256'];
		if ( is_link( $directory ) || ( ! is_dir( $directory ) && ! mkdir( $directory, 0700 ) ) ) {
			return new \WP_Error( 'private_storage_unavailable', 'Download storage could not be prepared.', array( 'status' => 503 ) );
		}
		$path = $directory . '/' . $filename;
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			return new \WP_Error( 'private_storage_invalid', 'A download path is not a regular file.', array( 'status' => 503 ) );
		}
		$stored_hash = is_file( $path ) ? hash_file( 'sha256', $path ) : false;
		// Source bytes have already passed the hash check; repair corruption without changing grant paths.
		if ( ! is_string( $stored_hash ) || ! hash_equals( $request['sha256'], $stored_hash ) ) {
			$temp = tempnam( $directory, '.ingest-' );
			if ( ! is_string( $temp ) ) {
				return new \WP_Error( 'private_storage_unavailable', 'Download storage is unavailable.', array( 'status' => 503 ) );
			}
			try {
				if ( strlen( $bytes ) !== file_put_contents( $temp, $bytes ) || ! chmod( $temp, 0600 ) || ! rename( $temp, $path ) ) {
					return new \WP_Error( 'private_storage_unavailable', 'Download storage could not be committed.', array( 'status' => 503 ) );
				}
			} finally {
				if ( is_file( $temp ) ) {
					unlink( $temp );
				}
			}
		}
		$committed_hash = hash_file( 'sha256', $path );
		if ( ! is_string( $committed_hash ) || ! hash_equals( $request['sha256'], $committed_hash ) ) {
			return new \WP_Error( 'private_file_integrity', 'Stored download bytes failed integrity verification.', array( 'status' => 503 ) );
		}
		return new \WP_REST_Response( array( 'data' => array( 'sha256' => $request['sha256'], 'filename' => $filename, 'size' => strlen( $bytes ) ) ) );
	}

	/** Validate a source declaration against privately ingested bytes before a catalog write. */
	public function resolve( mixed $declarations ): array|\WP_Error {
		if ( ! is_array( $declarations ) || ! array_is_list( $declarations ) || count( $declarations ) > 20 ) {
			return new \WP_Error( 'private_file_invalid', 'Downloads must be a list of file declarations.', array( 'status' => 422 ) );
		}
		if ( array() === $declarations ) {
			return array();
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$files = array();
		foreach ( $declarations as $declaration ) {
			if ( ! is_array( $declaration ) || array_diff( array_keys( $declaration ), array( 'sha256', 'filename' ) ) ||
				! is_string( $declaration['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $declaration['sha256'] ) ) {
				return new \WP_Error( 'private_file_invalid', 'A download declaration is invalid.', array( 'status' => 422 ) );
			}
			$filename = $this->filename( $declaration['filename'] ?? null );
			if ( is_wp_error( $filename ) ) {
				return $filename;
			}
			$directory = $root . '/' . $declaration['sha256'];
			$path = $directory . '/' . $filename;
			if ( is_link( $directory ) || is_link( $path ) || ! is_file( $path ) || ! hash_equals( $declaration['sha256'], hash_file( 'sha256', $path ) ) ) {
				return new \WP_Error( 'private_file_missing', 'Upload the exact declared download before applying the product.', array( 'status' => 409 ) );
			}
			// Woo permission IDs are at most 36 characters. Bind both content and filename.
			$id = substr( hash( 'sha256', $declaration['sha256'] . ':' . $filename ), 0, 32 );
			if ( isset( $files[$id] ) ) {
				return new \WP_Error( 'private_file_invalid', 'Download declarations must be unique.', array( 'status' => 422 ) );
			}
			$file = new \WC_Product_Download();
			$file->set_id( $id );
			$file->set_name( $filename );
			$file->set_file( $path );
			$files[$id] = $file;
		}
		wc_get_container()->get( \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class )->add_approved_directory( $root );
		return $files;
	}

	public function historical_file( mixed $file, \WC_Product $product, string $id ): mixed {
		if ( $file || '' === $id || ! $product->get_meta( '_spacefast_product_key' ) ) {
			return $file;
		}
		$history = $product->get_meta( '_spacefast_download_history' );
		if ( ! is_array( $history ) || ! isset( $history[$id] ) ) {
			return $file;
		}
		$download = new \WC_Product_Download();
		$download->set_id( $id );
		$download->set_name( $history[$id]['name'] );
		$download->set_file( $history[$id]['file'] );
		return $download;
	}

	/** Source sync receives content identities, never private backing paths. Null reports drift. */
	public function declarations( \WC_Product $product ): ?array {
		$downloads = $product->get_downloads();
		if ( array() === $downloads ) {
			return array();
		}
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return null;
		}
		$result = array();
		foreach ( $downloads as $id => $file ) {
			$path = $file->get_file();
			$filename = basename( $path );
			$sha = basename( dirname( $path ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ||
				is_wp_error( $this->filename( $filename ) ) || $path !== $root . '/' . $sha . '/' . $filename ||
				is_link( dirname( $path ) ) || is_link( $path ) || ! is_file( $path ) ||
				$id !== substr( hash( 'sha256', $sha . ':' . $filename ), 0, 32 ) || $file->get_name() !== $filename ) {
				return null;
			}
			$digest = hash_file( 'sha256', $path );
			if ( ! is_string( $digest ) || ! hash_equals( $sha, $digest ) ) {
				return null;
			}
			$result[] = array( 'sha256' => $sha, 'filename' => $filename );
		}
		return $result;
	}

	public function historical_path( string $path, \WC_Product $product, string $id ): string {
		$file = $this->historical_file( false, $product, $id );
		return $file ? $file->get_file() : $path;
	}

	/** Expand availability only for Woo's native download request. Permissions remain checked by Woo. */
	public function native_download_files( array $files, \WC_Product $product ): array {
		if ( ! isset( $_GET['download_file'], $_GET['order'], $_GET['key'] ) ||
			! is_scalar( $_GET['download_file'] ) || (int) $_GET['download_file'] !== $product->get_id() ||
			! is_string( $_GET['key'] ) ) {
			return $files;
		}
		$id = wp_unslash( $_GET['key'] );
		$file = $this->historical_file( false, $product, $id );
		if ( $file ) {
			$files[$id] = $file;
		}
		return $files;
	}
}
