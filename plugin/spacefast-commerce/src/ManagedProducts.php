<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * Managed-field protection (D1). Declared fields belong to the store source: REST edits are
 * refused with an explicit error, wp-admin edits are not saved and say why, and any drift that
 * still reaches the database (SQL, other plugins) is repaired by the next apply. Operational
 * Woo writes (stock, sales counts, categories, reviews) stay native and merchant-owned.
 */
final class ManagedProducts {
	private bool $applying = false;

	/** Woo product props the declaration owns. */
	private const PROPS = array(
		'name', 'description', 'short_description', 'status', 'regular_price', 'sale_price',
		'date_on_sale_from', 'date_on_sale_to', 'virtual', 'downloadable', 'downloads', 'download_limit',
		'download_expiry', 'image_id', 'gallery_image_ids', 'sku', 'catalog_visibility', 'sold_individually',
		'manage_stock', 'menu_order', 'slug',
	);

	/** The same fields as post meta, for writes that bypass the product object. */
	private const META = array(
		'_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to',
		'_virtual', '_downloadable', '_downloadable_files', '_download_limit', '_download_expiry',
		'_thumbnail_id', '_product_image_gallery', '_sku', '_sold_individually', '_manage_stock',
		'_spacefast_product_key', '_spacefast_page', '_spacefast_stock_initial',
	);

	public function register(): void {
		foreach ( array( 'blogname', 'woocommerce_currency' ) as $option ) {
			add_filter( 'pre_update_option_' . $option, array( $this, 'guard_option' ), 10, 2 );
		}
		add_filter( 'rest_request_before_callbacks', array( $this, 'guard_store_rest' ), 10, 3 );
		add_filter( 'pre_update_option_woocommerce_default_country', array( $this, 'guard_country' ), 10, 2 );
		add_filter( 'pre_update_option_woo_storefront_settings', array( $this, 'guard_brand' ), 10, 2 );
		add_action( 'woocommerce_before_product_object_save', array( $this, 'guard_save' ) );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'guard_rest' ), 10, 3 );
		add_filter( 'wp_insert_post_data', array( $this, 'guard_post' ), 10, 2 );
		foreach ( array( 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata' ) as $hook ) {
			add_filter( $hook, array( $this, 'guard_meta' ), 10, 3 );
		}
		add_filter( 'pre_delete_post', array( $this, 'guard_delete' ), 10, 2 );
		add_filter( 'pre_trash_post', array( $this, 'guard_delete' ), 10, 2 );
		add_filter( 'map_meta_cap', array( $this, 'guard_capability' ), 10, 4 );
		add_action( 'set_object_terms', array( $this, 'guard_type' ), 10, 4 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	private function store_managed(): bool {
		return ! $this->applying && (bool) get_option( 'spacefast_commerce_store_hash' );
	}

	private static function setting_message(): string {
		return __( 'These store settings are managed by your store source. Edit the source and publish.', 'spacefast-commerce' );
	}

	/** A native settings save must explain a refusal instead of reporting a successful edit. */
	private function setting_refused(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'woocommerce_page_wc-settings' === $screen->id ) {
			\WC_Admin_Settings::add_error( self::setting_message() );
		} elseif ( $screen && 'options-general' === $screen->id ) {
			add_settings_error( 'general', 'source_managed_setting', self::setting_message() );
		}
	}

	public function guard_option( mixed $value, mixed $old ): mixed {
		if ( ! $this->store_managed() || $value === $old ) {
			return $value;
		}
		$this->setting_refused();
		return $old;
	}

	public function guard_country( mixed $value, mixed $old ): mixed {
		if ( $this->store_managed() && explode( ':', (string) $value )[0] !== explode( ':', (string) $old )[0] ) {
			$this->setting_refused();
			return $old;
		}
		return $value;
	}

	/** Woo's setting controller builds its receipt from the requested value, even if an option filter refuses it. */
	public function guard_store_rest( mixed $response, array $handler, \WP_REST_Request $request ): mixed {
		if ( null !== $response || ! $this->store_managed() || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $response;
		}
		$changed = false;
		if ( preg_match( '#^/wc/v[1-4]/settings/(?:[^/]+/)?([^/]+)/?$#', $request->get_route(), $match ) && current_user_can( 'manage_woocommerce' ) ) {
			// Woo's batch controller calls update_item directly, without another REST dispatch.
			$updates = 'batch' === $match[1] ? $request->get_param( 'update' ) : array( array( 'id' => $request->get_param( 'id' ), 'value' => $request->get_param( 'value' ) ) );
			foreach ( is_array( $updates ) ? $updates : array() as $update ) {
				$option = is_array( $update ) ? ( $update['id'] ?? null ) : null;
				$value = is_array( $update ) ? ( $update['value'] ?? null ) : null;
				if ( is_string( $value ) && in_array( $option, array( 'blogname', 'woocommerce_currency', 'woocommerce_default_country' ), true ) ) {
					$old = (string) get_option( $option );
					$changed = 'woocommerce_default_country' === $option ? explode( ':', $value )[0] !== explode( ':', $old )[0] : $value !== $old;
					if ( $changed ) {
						break;
					}
				}
			}
		} elseif ( '/wp/v2/settings' === $request->get_route() && current_user_can( 'manage_options' ) ) {
			$changed = $request->has_param( 'title' ) && $request->get_param( 'title' ) !== get_option( 'blogname' );
			$settings = $request->get_param( 'woo_storefront_settings' );
			$brand = is_array( $settings ) && is_array( $settings['branding'] ?? null ) ? $settings['branding'] : array();
			$old = get_option( 'woo_storefront_settings', array() );
			$changed = $changed || ( array_key_exists( 'logo', $brand ) && $brand['logo'] !== ( $old['branding']['logo'] ?? '' ) );
			$colors = is_array( $brand['colors'] ?? null ) ? $brand['colors'] : array();
			$changed = $changed || ( array_key_exists( 'primary', $colors ) && $colors['primary'] !== ( $old['branding']['colors']['primary'] ?? '#7f54b3' ) );
		}
		return $changed ? new \WP_Error( 'source_managed_setting', self::setting_message(), array( 'status' => 403 ) ) : $response;
	}

	/** Only the declared logo and accent are managed; other shell settings remain editable. */
	public function guard_brand( mixed $value, mixed $old ): mixed {
		if ( ! $this->store_managed() ) {
			return $value;
		}
		$value = is_array( $value ) ? $value : array();
		$old = is_array( $old ) ? $old : array();
		$value['branding'] = is_array( $value['branding'] ?? null ) ? $value['branding'] : array();
		$value['branding']['colors'] = is_array( $value['branding']['colors'] ?? null ) ? $value['branding']['colors'] : array();
		$value['branding']['logo'] = $old['branding']['logo'] ?? '';
		$value['branding']['colors']['primary'] = $old['branding']['colors']['primary'] ?? '#7f54b3';
		return $value;
	}

	/** Only Catalog's apply receives this; no request flag can grant the bypass. */
	public function apply( callable $work ): mixed {
		$previous = $this->applying;
		$this->applying = true;
		try {
			return $work();
		} finally {
			$this->applying = $previous;
		}
	}

	public function applying(): bool {
		return $this->applying;
	}

	public static function key( int $id ): string {
		return $id > 0 ? (string) get_post_meta( $id, '_spacefast_product_key', true ) : '';
	}

	private function protected_id( int $id ): bool {
		return ! $this->applying && '' !== self::key( $id );
	}

	/** wp-admin, quick edit, bulk edit and any other object save: put source values back. */
	public function guard_save( \WC_Product $product ): void {
		if ( ! $this->protected_id( $product->get_id() ) ) {
			return;
		}
		$changed = array_intersect( array_keys( $product->get_changes() ), self::PROPS );
		if ( array() === $changed ) {
			return;
		}
		$stored = wc_get_product( $product->get_id() );
		foreach ( $changed as $prop ) {
			$product->{'set_' . $prop}( $stored->{'get_' . $prop}( 'edit' ) );
		}
		if ( is_admin() && get_current_user_id() ) {
			set_transient( 'spacefast_commerce_managed_notice_' . get_current_user_id(), $product->get_id(), 60 );
		}
	}

	/** REST callers (agents, apps) get an explicit refusal instead of a silent revert. */
	public function guard_rest( mixed $product, \WP_REST_Request $request, bool $creating ): mixed {
		if ( $creating || ! $product instanceof \WC_Product || ! $this->protected_id( $product->get_id() ) ) {
			return $product;
		}
		$changed = array_intersect( array_keys( $product->get_changes() ), self::PROPS );
		if ( array() !== $changed || ( isset( $request['type'] ) && 'simple' !== $request['type'] ) ) {
			return new \WP_Error( 'source_managed_product', 'These fields are managed by your store source: ' . implode( ', ', $changed ?: array( 'type' ) ) . '. Change the source and publish.', array( 'status' => 403 ) );
		}
		return $product;
	}

	public function guard_post( array $data, array $post ): array {
		$id = (int) ( $post['ID'] ?? 0 );
		if ( ! $this->protected_id( $id ) ) {
			return $data;
		}
		$current = get_post( $id, ARRAY_A );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'menu_order', 'post_type' ) as $field ) {
			if ( array_key_exists( $field, $data ) && is_array( $current ) ) {
				$data[ $field ] = wp_slash( $current[ $field ] );
			}
		}
		return $data;
	}

	public function guard_meta( mixed $check, int $id, string $key ): mixed {
		return in_array( $key, self::META, true ) && $this->protected_id( $id ) ? false : $check;
	}

	/** wp-admin changes the product type through taxonomy writes before it saves the object. */
	public function guard_type( int $id, mixed $terms, array $term_ids, string $taxonomy ): void {
		if ( 'product_type' === $taxonomy && $this->protected_id( $id ) && array( 'simple' ) !== wp_get_object_terms( $id, 'product_type', array( 'fields' => 'slugs' ) ) ) {
			wp_set_object_terms( $id, 'simple', 'product_type' );
		}
	}

	/** Deleting a managed product would break its stable identity and order history. */
	public function guard_delete( mixed $check, \WP_Post $post ): mixed {
		return '' !== self::key( $post->ID ) && ! $this->applying ? false : $check;
	}

	public function guard_capability( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_post' === $cap && '' !== self::key( (int) ( $args[0] ?? 0 ) ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	public function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( $this->store_managed() && $screen && ( 'options-general' === $screen->id || ( 'woocommerce_page_wc-settings' === $screen->id && 'general' === $tab ) ) ) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Managed by your store source.', 'spacefast-commerce' ) . '</strong> ';
			echo esc_html__( 'Store name, currency, logo, accent and country come from the store source. Edit the source and publish to change them. State, address, taxes and other merchant settings stay editable here.', 'spacefast-commerce' );
			echo '</p></div>';
			return;
		}
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice.
		$reverted = get_transient( 'spacefast_commerce_managed_notice_' . get_current_user_id() );
		if ( ! $screen || 'product' !== $screen->post_type || ( '' === self::key( $id ) && ! $reverted ) ) {
			return;
		}
		delete_transient( 'spacefast_commerce_managed_notice_' . get_current_user_id() );
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Managed by your store source.', 'spacefast-commerce' ) . '</strong> ';
		echo esc_html__( 'Name, descriptions, price, image, download, SKU, visibility and stock tracking come from the store source. Changes to them here are not saved; edit the source and publish. Stock levels, categories and everything else stay yours to edit.', 'spacefast-commerce' );
		echo '</p></div>';
	}
}
