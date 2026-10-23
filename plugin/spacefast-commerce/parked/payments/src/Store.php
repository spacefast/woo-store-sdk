<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Installation identity and the revocable control-plane credential, never a Stripe secret. */
final class Store {
	public function register(): void {
		// Managed checkout keeps Woo's receipt and download experience.
		add_filter( 'woo_storefront_checkout_redirect_after_order', fn ( bool $redirect ): bool => $this->binding() ? false : $redirect );
		add_filter( 'woo_storefront_checkout_return_url', fn ( mixed $url ): mixed => $this->binding()['origin'] ?? $url );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'spacefast-commerce bind', array( $this, 'bind_command' ) );
		}
	}

	public function binding(): array {
		if ( function_exists( 'spacefast_commerce_runtime_binding' ) ) {
			return spacefast_commerce_runtime_binding();
		}
		$binding = get_option( 'spacefast_commerce_binding', array() );
		return is_array( $binding ) ? $binding : array();
	}

	/** The trusted installer supplies a JSON file, keeping credentials out of process arguments. */
	public function bind_command( array $args ): void {
		if ( count( $args ) !== 1 || ! is_readable( $args[0] ) ) {
			\WP_CLI::error( 'Supply a readable store binding JSON file.' );
		}
		$input = json_decode( file_get_contents( $args[0] ), true );
		$result = $this->bind( $input );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Store bound. Payment activation is separate.' );
	}

	/** Root-only provisioning; this operation has no public REST entry point. */
	public function bind( mixed $input ): true|\WP_Error {
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'binding_invalid', 'A store binding is required.' );
		}
		foreach ( array( 'space_id', 'store_id', 'environment', 'origin', 'currency', 'country', 'credential' ) as $field ) {
			if ( ! isset( $input[$field] ) || ! is_string( $input[$field] ) || '' === $input[$field] ) {
				return new \WP_Error( 'binding_invalid', 'Store binding fields are missing.' );
			}
		}
		$origin = wp_parse_url( $input['origin'] );
		if (
			! preg_match( '/^spc_[A-Za-z0-9]+$/', $input['space_id'] ) ||
			! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $input['store_id'] ) ||
			! in_array( $input['environment'], array( 'test', 'live' ), true ) ||
			! preg_match( '/^[A-Za-z0-9_-]{32,256}$/D', $input['credential'] ) ||
			! is_array( $origin ) || ! isset( $origin['host'], $origin['scheme'] ) ||
			'https' !== $origin['scheme'] || ( isset( $origin['user'] ) || isset( $origin['pass'] ) ) ||
			isset( $origin['query'] ) || isset( $origin['fragment'] ) ||
			( isset( $origin['path'] ) && '' !== $origin['path'] && '/' !== $origin['path'] ) ||
			! array_key_exists( $input['currency'], get_woocommerce_currencies() ) ||
			! array_key_exists( $input['country'], WC()->countries->get_countries() )
		) {
			return new \WP_Error( 'binding_invalid', 'Store identity, origin, merchant location or credential is invalid.' );
		}
		$existing = $this->binding();
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			if ( isset( $existing[$field] ) && $existing[$field] !== $input[$field] ) {
				return new \WP_Error( 'store_already_bound', 'This installation belongs to another store or environment.' );
			}
		}
		// Currency cannot change underneath native orders or an existing catalog.
		if ( isset( $existing['currency'] ) && $existing['currency'] !== $input['currency'] ) {
			return new \WP_Error( 'store_currency_conflict', 'An existing store cannot change currency.' );
		}
		$binding = array_intersect_key( $input, array_flip( array( 'space_id', 'store_id', 'environment', 'origin', 'currency', 'country' ) ) );
		$binding['origin'] = rtrim( $binding['origin'], '/' );
		$binding['credential_hash'] = hash( 'sha256', $input['credential'] );
		if ( function_exists( 'spacefast_commerce_runtime_bind' ) ) {
			if ( ! spacefast_commerce_runtime_bind( $binding ) ) {
				return new \WP_Error( 'store_binding_failed', 'Protected store identity could not be saved.' );
			}
		} else {
			update_option( 'spacefast_commerce_binding', $binding, false );
		}
		update_option( 'woocommerce_currency', $binding['currency'] );
		update_option( 'woocommerce_default_country', $binding['country'] );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		update_option( 'woocommerce_downloads_grant_access_after_payment', 'yes' );
		update_option( 'woocommerce_file_download_method', 'force' );
		update_option( 'woocommerce_downloads_redirect_fallback_allowed', 'no' );
		return true;
	}

	/** Exact installation/environment binding; an order key never authenticates this boundary. */
	public function authorize( \WP_REST_Request $request ): true|\WP_Error {
		$binding = $this->binding();
		$authorization = $request->get_header( 'Authorization' ) ?? '';
		if ( ! preg_match( '/^Bearer ([A-Za-z0-9_-]{32,256})$/D', $authorization, $match ) ||
			! isset( $binding['credential_hash'] ) ||
			! hash_equals( $binding['credential_hash'], hash( 'sha256', $match[1] ) ) ) {
			return new \WP_Error( 'store_unauthorized', 'Store authentication is required.', array( 'status' => 401 ) );
		}
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			if ( $request->get_header( 'X-Spacefast-' . str_replace( '_', '-', $field ) ) !== $binding[$field] ) {
				return new \WP_Error( 'store_scope_mismatch', 'Store scope does not match this installation.', array( 'status' => 403 ) );
			}
		}
		return true;
	}
}
