<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * Private download bytes, stored outside the web root at `<root>/<sha256>/<filename>`.
 * Woo alone authorizes buyers. Each product has one download entry with a stable ID, so
 * replacing the asset updates that entry in place: previous buyers get the new file and keep
 * their access, expiry and remaining downloads (plan D5 `asset`).
 */
final class PrivateFiles {
	public function register(): void {
		add_filter( 'woocommerce_file_download_method', static function ( string $method, int $product_id ): string {
			return '' !== (string) get_post_meta( $product_id, '_spacefast_product_key', true ) ? 'force' : $method;
		}, 10, 2 );
	}

	/** On Spacefast the runtime owns protected storage; other hosts configure a directory outside the web root. */
	public function root(): string|\WP_Error {
		if ( function_exists( 'spacefast_commerce_runtime_private_root' ) ) {
			$root = spacefast_commerce_runtime_private_root();
			return is_string( $root ) ? $root : Bundle::refuse( 'private-storage-unavailable', 'Protected runtime storage is unavailable.' );
		}
		$configured = defined( 'SPACEFAST_COMMERCE_PRIVATE_ROOT' ) ? SPACEFAST_COMMERCE_PRIVATE_ROOT : '';
		$public = realpath( defined( 'SPACEFAST_COMMERCE_PUBLIC_ROOT' ) ? SPACEFAST_COMMERCE_PUBLIC_ROOT : ABSPATH );
		if ( ! is_string( $configured ) || '' === $configured || '/' !== $configured[0] || ! is_string( $public ) ) {
			return Bundle::refuse( 'private-storage-unavailable', 'Define SPACEFAST_COMMERCE_PRIVATE_ROOT as a directory outside the web root.' );
		}
		// Resolve the existing parent before creating anything; symlinks cannot move bytes into the web root.
		$parent = realpath( dirname( $configured ) );
		if ( ! is_string( $parent ) || is_link( $configured ) || $parent === $public || str_starts_with( $parent . '/', rtrim( $public, '/' ) . '/' ) ) {
			return Bundle::refuse( 'private-storage-unavailable', 'The private download directory must sit outside the web root.' );
		}
		if ( ! is_dir( $configured ) && ! mkdir( $configured, 0700 ) ) {
			return Bundle::refuse( 'private-storage-unavailable', 'The private download directory could not be created.' );
		}
		$root = realpath( $configured );
		return is_string( $root ) && is_writable( $root ) ? $root : Bundle::refuse( 'private-storage-unavailable', 'The private download directory is not writable.' );
	}

	private function path( string $root, array $ref ): string {
		return $root . '/' . $ref['sha256'] . '/' . sanitize_file_name( basename( $ref['path'] ) );
	}

	private function held( string $path, string $sha ): bool {
		return is_file( $path ) && ! is_link( $path ) && ! is_link( dirname( $path ) ) && hash_equals( $sha, (string) hash_file( 'sha256', $path ) );
	}

	/** Refusal phase: the bytes are already held, or the bundle carries them. */
	public function prepare( array $ref, Bundle $bundle, string $pointer ): true|\WP_Error {
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		if ( $this->held( $this->path( $root, $ref ), $ref['sha256'] ) ) {
			return true;
		}
		$file = $bundle->file( $ref, $pointer );
		if ( null === $file ) {
			return Bundle::refuse( 'file-missing', 'The bundle does not carry files/' . $ref['sha256'] . ' and the store does not hold it yet.', $pointer );
		}
		return is_wp_error( $file ) ? $file : true;
	}

	/** Write phase: commit the bytes atomically and return Woo's download entry for the product. */
	public function download( string $key, array $ref, Bundle $bundle ): \WC_Product_Download|\WP_Error {
		$root = $this->root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$path = $this->path( $root, $ref );
		if ( ! $this->held( $path, $ref['sha256'] ) ) {
			$source = $bundle->file( $ref, '' );
			$directory = dirname( $path );
			if ( ! is_string( $source ) || is_link( $directory ) || ( ! is_dir( $directory ) && ! mkdir( $directory, 0700 ) ) ) {
				return new \WP_Error( 'private-storage-unavailable', 'Download storage could not be prepared.' );
			}
			$temp = tempnam( $directory, '.ingest-' );
			if ( ! is_string( $temp ) || ! copy( $source, $temp ) || ! chmod( $temp, 0600 ) || ! rename( $temp, $path ) ) {
				return new \WP_Error( 'private-storage-unavailable', 'Download bytes could not be committed.' );
			}
		}
		wc_get_container()->get( \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class )->add_approved_directory( $root );
		$download = new \WC_Product_Download();
		$download->set_id( self::download_id( $key ) );
		$download->set_name( basename( $path ) );
		$download->set_file( $path );
		return $download;
	}

	public static function download_id( string $key ): string {
		return substr( hash( 'sha256', 'spacefast-download:' . $key ), 0, 32 );
	}

	/** Planner projection: content identities, never private paths. Null reports drift for repair. */
	public function declarations( \WC_Product $product, string $key ): ?array {
		$result = array();
		$root = $this->root();
		foreach ( $product->get_downloads() as $id => $file ) {
			$path = $file->get_file();
			$sha = basename( dirname( $path ) );
			if ( is_wp_error( $root ) || $id !== self::download_id( $key ) || dirname( $path, 2 ) !== $root ||
				! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! $this->held( $path, $sha ) ) {
				return null;
			}
			$result[] = array( 'sha256' => $sha, 'filename' => basename( $path ) );
		}
		return $result;
	}
}
