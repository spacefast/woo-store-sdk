<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Source-field protection. Transactional Woo writes remain native. */
final class ManagedProducts {
	private bool $applying = false;
	private array $source_meta = array(
		'_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to',
		'_virtual', '_downloadable', '_downloadable_files', '_download_limit', '_download_expiry',
		'_sku', '_visibility', '_sold_individually', '_manage_stock', '_spacefast_product_key', '_spacefast_space_id',
	);

	public function register(): void {
		add_action( 'woocommerce_before_product_object_save', array( $this, 'guard_save' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'guard_post' ), 10, 2 );
		foreach ( array( 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata' ) as $hook ) {
			add_filter( $hook, array( $this, 'guard_meta' ), 10, 4 );
		}
		add_filter( 'pre_delete_post', array( $this, 'guard_delete' ), 10, 2 );
		add_filter( 'pre_trash_post', array( $this, 'guard_delete' ), 10, 2 );
		add_filter( 'map_meta_cap', array( $this, 'guard_capability' ), 10, 4 );
	}

	/** Only the authenticated catalog controller receives this object. No request flag grants bypass. */
	public function apply( callable $work ): mixed {
		$this->applying = true;
		try {
			return $work();
		} finally {
			$this->applying = false;
		}
	}

	private function managed( int $id ): bool {
		return '' !== (string) get_post_meta( $id, '_spacefast_product_key', true );
	}

	public function guard_save( \WC_Product $product ): void {
		if ( $this->applying || ! $this->managed( $product->get_id() ) ) {
			return;
		}
		$owned = array( 'name', 'description', 'short_description', 'status', 'regular_price', 'sale_price', 'price',
			'date_on_sale_from', 'date_on_sale_to', 'virtual', 'downloadable', 'downloads', 'download_limit',
			'download_expiry', 'sku', 'catalog_visibility', 'sold_individually', 'manage_stock' );
		if ( 'simple' !== $product->get_type() || array_intersect( array_keys( $product->get_changes() ), $owned ) ) {
			throw new \WC_Data_Exception( 'source_managed_product', 'Edit this product in the Space source.', 403 );
		}
	}

	public function guard_post( array $data, array $post ): array {
		$id = (int) ( $post['ID'] ?? 0 );
		if ( $this->applying || ! $this->managed( $id ) ) {
			return $data;
		}
		$current = get_post( $id, ARRAY_A );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type' ) as $field ) {
			if ( array_key_exists( $field, $data ) && wp_unslash( $data[$field] ) !== $current[$field] ) {
				throw new \WC_Data_Exception( 'source_managed_product', 'Edit this product in the Space source.', 403 );
			}
		}
		return $data;
	}

	public function guard_meta( mixed $check, int $id, string $key, mixed $value ): mixed {
		if ( ! $this->applying && $this->managed( $id ) && in_array( $key, $this->source_meta, true ) ) {
			throw new \WC_Data_Exception( 'source_managed_product', 'Edit this product in the Space source.', 403 );
		}
		return $check;
	}

	public function guard_delete( mixed $check, \WP_Post $post ): mixed {
		return $this->managed( $post->ID ) ? false : $check;
	}

	public function guard_capability( array $caps, string $cap, int $user_id, array $args ): array {
		if ( in_array( $cap, array( 'edit_post', 'delete_post' ), true ) && $this->managed( (int) ( $args[0] ?? 0 ) ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}
}
