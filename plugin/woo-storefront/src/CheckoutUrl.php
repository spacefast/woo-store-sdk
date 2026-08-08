<?php
/**
 * Hosted checkout URL injection.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

use WooStorefront\Support\Jwt;
use WooStorefront\Support\Secrets;
use WooStorefront\Support\SignedKey;

/**
 * Adds a fresh hosted checkout URL to Store API cart responses.
 */
final class CheckoutUrl {
	private const KEY_TTL = 300;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'add_checkout_url' ), 10, 3 );
	}

	/**
	 * Add checkout_url to successful Store API cart payloads.
	 *
	 * @param mixed            $response REST response.
	 * @param \WP_REST_Server  $server   REST server.
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function add_checkout_url( mixed $response, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		unset( $server );

		if ( ! str_starts_with( $request->get_route(), '/wc/store/v1/cart' ) || is_wp_error( $response ) ) {
			return $response;
		}

		$rest_response = rest_ensure_response( $response );
		if ( $rest_response->is_error() || $rest_response->get_status() >= 400 ) {
			return $response;
		}

		$data = $rest_response->get_data();
		if ( ! is_array( $data ) || ! array_key_exists( 'items', $data ) ) {
			return $response;
		}

		$cart_token = $this->find_cart_token( $rest_response, $request );
		if ( null === $cart_token ) {
			return $response;
		}

		$data['checkout_url'] = $this->build_url( $cart_token, $request );
		$rest_response->set_data( $data );

		return $rest_response;
	}

	/**
	 * Find a core token or mint a compatible token for the active WC session.
	 *
	 * @param \WP_REST_Response $response REST response.
	 * @param \WP_REST_Request  $request  REST request.
	 * @return string|null
	 */
	private function find_cart_token( \WP_REST_Response $response, \WP_REST_Request $request ): ?string {
		foreach ( $response->get_headers() as $name => $value ) {
			if ( 'cart-token' === strtolower( (string) $name ) && is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$request_token = $request->get_header( 'Cart-Token' );
		if ( '' !== $request_token ) {
			return $request_token;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$expiration = time() + (int) apply_filters( 'wc_session_expiration', 48 * HOUR_IN_SECONDS );
		return Jwt::encode(
			array(
				'user_id' => (string) WC()->session->get_customer_id(),
				'exp'     => $expiration,
				'iss'     => 'store-api',
			),
			'@' . wp_salt()
		);
	}

	/**
	 * Build a signed hosted checkout URL.
	 *
	 * @param string           $cart_token Cart token.
	 * @param \WP_REST_Request $request    REST request.
	 * @return string
	 */
	private function build_url( string $cart_token, \WP_REST_Request $request ): string {
		$expires = time() + self::KEY_TTL;
		$secret  = Secrets::checkout();
		$query   = array(
			'expires' => $expires,
			'key'     => SignedKey::create( $cart_token, $expires, $secret ),
		);

		$return_url = $request->get_param( 'return_url' );
		$return_url = apply_filters(
			'woo_storefront_checkout_return_url',
			is_string( $return_url ) ? $return_url : '',
			$request
		);

		if ( is_string( $return_url ) && CheckoutEntry::is_valid_return_url( $return_url ) ) {
			$return_expires          = time() + HOUR_IN_SECONDS;
			$query['return_url']     = $return_url;
			$query['return_expires'] = $return_expires;
			$query['return_key']     = SignedKey::create( $return_url, $return_expires, $secret );
		}

		$base_url = home_url( '/checkout/c/' . rawurlencode( $cart_token ) );
		return add_query_arg( $query, $base_url );
	}
}
