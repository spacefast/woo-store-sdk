<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * Product images and the brand logo as real media attachments, keyed by content hash (bundle
 * files) or URL (remote images), so re-applies never duplicate an attachment. Woo needs a real
 * attachment for checkout and email thumbnails.
 */
final class Media {
	/** @var array<string, string> Identity → downloaded temp file, fetched before the first write. */
	private array $prefetched = array();

	public function find( string $identity ): int {
		[ $kind, $value ] = explode( ':', $identity, 2 );
		$ids = get_posts( array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'fields' => 'ids',
			'numberposts' => 1,
			'orderby' => 'ID',
			'order' => 'ASC',
			'suppress_filters' => true,
			'meta_key' => 'sha256' === $kind ? '_spacefast_media_sha256' : '_spacefast_media_url',
			'meta_value' => $value,
		) );
		return $ids && is_file( (string) get_attached_file( (int) $ids[0] ) ) ? (int) $ids[0] : 0;
	}

	/** Refusal phase: every image must be either already held, carried by the bundle, or downloadable now. */
	public function prepare( ?array $ref, Bundle $bundle, string $pointer ): true|\WP_Error {
		$identity = Planner::media_identity( $ref );
		if ( null === $identity || $this->find( $identity ) ) {
			return true;
		}
		if ( isset( $ref['url'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temp = download_url( $ref['url'], 60 );
			if ( is_wp_error( $temp ) ) {
				return Bundle::refuse( 'image-unavailable', 'Could not download ' . $ref['url'] . ': ' . $temp->get_error_message(), $pointer . '/url' );
			}
			$this->prefetched[ $identity ] = $temp;
			return $this->validate_image( $temp, basename( (string) wp_parse_url( $ref['url'], PHP_URL_PATH ) ), $pointer );
		}
		$file = $bundle->file( $ref, $pointer );
		if ( null === $file ) {
			return Bundle::refuse( 'file-missing', 'The bundle does not carry files/' . $ref['sha256'] . ' and the store does not hold it yet.', $pointer );
		}
		return is_wp_error( $file ) ? $file : $this->validate_image( $file, basename( $ref['path'] ), $pointer );
	}

	/** Reject invalid image bytes before the catalog counter or any store setting changes. */
	private function validate_image( string $file, string $name, string $pointer ): true|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$mime = wp_get_image_mime( $file );
		if ( false !== $mime && str_starts_with( $mime, 'image/' ) ) {
			$checked = wp_check_filetype_and_ext( $file, $name );
			if ( ! empty( $checked['ext'] ) && ! empty( $checked['type'] ) ) {
				return true;
			}
		}
		if ( 'svg' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$document = new \DOMDocument();
			$previous = libxml_use_internal_errors( true );
			try {
				$parsed = $document->load( $file, LIBXML_NONET );
				if ( $parsed && 'svg' === $document->documentElement?->localName && null === $document->doctype ) {
					return true;
				}
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors( $previous );
			}
		}
		return Bundle::refuse( 'image-invalid', 'The image must contain valid image bytes in a supported file format.', $pointer );
	}

	/** Write phase: return the attachment for a prepared reference, creating it once. */
	public function attachment( ?array $ref, Bundle $bundle, int $parent = 0 ): int|\WP_Error {
		$identity = Planner::media_identity( $ref );
		if ( null === $identity ) {
			return 0;
		}
		$existing = $this->find( $identity );
		if ( $existing ) {
			return $existing;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( isset( $ref['url'] ) ) {
			$source = $this->prefetched[ $identity ] ?? '';
			$name = basename( (string) wp_parse_url( $ref['url'], PHP_URL_PATH ) );
		} else {
			$source = (string) $bundle->file( $ref, '' );
			$name = basename( $ref['path'] );
		}
		// media_handle_sideload moves its input; keep the bundle intact for a retry.
		$temp = wp_tempnam( $name );
		if ( '' === $source || ! copy( $source, $temp ) ) {
			return new \WP_Error( 'media-failed', 'Could not stage ' . $name . ' for the media library.' );
		}
		$allow_svg = static fn ( array $mimes ): array => $mimes + array( 'svg' => 'image/svg+xml' );
		add_filter( 'upload_mimes', $allow_svg );
		try {
			$id = media_handle_sideload( array( 'name' => sanitize_file_name( '' === $name ? 'image' : $name ), 'tmp_name' => $temp ), $parent );
		} finally {
			remove_filter( 'upload_mimes', $allow_svg );
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
		}
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'media-failed', 'Could not add ' . $name . ' to the media library: ' . $id->get_error_message() );
		}
		[ $kind, $value ] = explode( ':', $identity, 2 );
		update_post_meta( $id, 'sha256' === $kind ? '_spacefast_media_sha256' : '_spacefast_media_url', $value );
		return (int) $id;
	}

	public function identity_of( int $attachment_id ): ?string {
		if ( ! $attachment_id || ! is_file( (string) get_attached_file( $attachment_id ) ) ) {
			return null;
		}
		$sha = get_post_meta( $attachment_id, '_spacefast_media_sha256', true );
		if ( is_string( $sha ) && '' !== $sha ) {
			return 'sha256:' . $sha;
		}
		$url = get_post_meta( $attachment_id, '_spacefast_media_url', true );
		return is_string( $url ) && '' !== $url ? 'url:' . $url : 'attachment:' . $attachment_id;
	}

	public function cleanup(): void {
		foreach ( $this->prefetched as $temp ) {
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
		}
		$this->prefetched = array();
	}
}
