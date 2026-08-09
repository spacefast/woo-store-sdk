<?php
/**
 * Isolated Store API cart sessions for headless visitors.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use WP_REST_Response;

/**
 * Issues a distinct guest cart token before the first cart mutation.
 */
final class Sessions {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the session route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Auth::REST_NAMESPACE,
			'/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Create a cart token that is unique to one storefront visitor.
	 */
	public function create(): WP_REST_Response {
		$token    = CartTokenUtils::get_cart_token( wc_rand_hash( 't_', 30 ) );
		$response = new WP_REST_Response( array( 'cartToken' => $token ), 201 );
		$response->header( 'Cart-Token', $token );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
