<?php
/**
 * Customer JWT authentication endpoints.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WooStorefront\Support\Base64Url;
use WooStorefront\Support\Jwt;
use WooStorefront\Support\Secrets;

/**
 * Issues, rotates, validates, and revokes customer JWTs.
 */
final class Auth {
	public const REST_NAMESPACE = 'woo-storefront/v1';
	private const ISSUER        = 'woo-storefront';
	private const ACCESS_TTL    = 15 * MINUTE_IN_SECONDS;
	private const REFRESH_TTL   = 30 * DAY_IN_SECONDS;
	private const DENYLIST      = 'woo_storefront_jti_denylist';

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register public auth endpoints.
	 */
	public function register_routes(): void {
		$routes = array(
			'/auth/login'    => 'login',
			'/auth/refresh'  => 'refresh',
			'/auth/logout'   => 'logout',
			'/auth/register' => 'register_customer',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * Authenticate credentials, merge a guest cart, and issue tokens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email    = sanitize_email( (string) $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );
		if ( '' === $email || '' === $password ) {
			return $this->error( 'woo_storefront_invalid_credentials', __( 'Email and password are required.', 'woo-storefront' ), 400 );
		}

		$user = wp_authenticate( $email, $password );
		if ( is_wp_error( $user ) ) {
			return $this->error( 'woo_storefront_invalid_credentials', __( 'Invalid email or password.', 'woo-storefront' ), 401 );
		}

		$cart_token = $this->request_cart_token( $request );
		if ( '' !== $cart_token ) {
			$this->merge_guest_cart( $cart_token, (int) $user->ID );
		}

		return new WP_REST_Response( $this->token_response( (int) $user->ID ), 200 );
	}

	/**
	 * Rotate a refresh token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = (string) $request->get_param( 'refresh_token' );
		$claims = $this->validate_token( $token, 'refresh' );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$customer_id = (int) $claims['sub'];
		if ( ! get_user_by( 'id', $customer_id ) ) {
			return $this->error( 'woo_storefront_customer_not_found', __( 'Customer not found.', 'woo-storefront' ), 401 );
		}

		$this->revoke_claims( $claims );
		return new WP_REST_Response( $this->token_response( $customer_id ), 200 );
	}

	/**
	 * Revoke the supplied access and/or refresh token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function logout( WP_REST_Request $request ): WP_REST_Response {
		$tokens = array_filter(
			array(
				$this->bearer_token( $request ),
				(string) $request->get_param( 'refresh_token' ),
			)
		);

		foreach ( $tokens as $token ) {
			foreach ( array( 'access', 'refresh' ) as $type ) {
				$claims = $this->validate_token( $token, $type );
				if ( ! is_wp_error( $claims ) ) {
					$this->revoke_claims( $claims );
					break;
				}
			}
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Create a Woo customer and issue tokens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_customer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$email      = sanitize_email( (string) $request->get_param( 'email' ) );
		$password   = (string) $request->get_param( 'password' );
		$first_name = sanitize_text_field( (string) $request->get_param( 'firstName' ) );
		$last_name  = sanitize_text_field( (string) $request->get_param( 'lastName' ) );

		if ( ! is_email( $email ) || strlen( $password ) < 8 ) {
			return $this->error( 'woo_storefront_invalid_registration', __( 'Use a valid email and a password of at least eight characters.', 'woo-storefront' ), 400 );
		}

		$customer_id = wc_create_new_customer( $email, '', $password );
		if ( is_wp_error( $customer_id ) ) {
			return $this->error( 'woo_storefront_registration_failed', $customer_id->get_error_message(), 400 );
		}

		wp_update_user(
			array(
				'ID'         => $customer_id,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);

		$cart_token = $this->request_cart_token( $request );
		if ( '' !== $cart_token ) {
			$this->merge_guest_cart( $cart_token, (int) $customer_id );
		}

		return new WP_REST_Response( $this->token_response( (int) $customer_id ), 201 );
	}

	/**
	 * Permission callback for customer routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ): true|WP_Error {
		$claims = $this->validate_token( $this->bearer_token( $request ), 'access' );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$customer_id = (int) $claims['sub'];
		if ( $customer_id <= 0 || ! get_user_by( 'id', $customer_id ) ) {
			return $this->error( 'woo_storefront_customer_not_found', __( 'Customer not found.', 'woo-storefront' ), 401 );
		}

		$request->set_param( '_woo_storefront_customer_id', $customer_id );
		return true;
	}

	/**
	 * Convert a Woo customer to the public contract shape.
	 *
	 * @param int $customer_id Customer ID.
	 * @return array<string, mixed>
	 */
	public static function customer_payload( int $customer_id ): array {
		$customer = new \WC_Customer( $customer_id );

		return array(
			'id'              => $customer_id,
			'email'           => $customer->get_email(),
			'firstName'       => $customer->get_first_name(),
			'lastName'        => $customer->get_last_name(),
			'billingAddress'  => self::address_payload( $customer, 'billing' ),
			'shippingAddress' => self::address_payload( $customer, 'shipping' ),
		);
	}

	/**
	 * Validate a customer token and expected type.
	 *
	 * @param string $token Token.
	 * @param string $type  Expected token type.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate_token( string $token, string $type ): array|WP_Error {
		if ( '' === $token ) {
			return $this->error( 'woo_storefront_auth_required', __( 'Authentication is required.', 'woo-storefront' ), 401 );
		}

		try {
			$claims = Jwt::decode( $token, Secrets::auth(), self::ISSUER );
		} catch ( InvalidArgumentException $exception ) {
			return $this->error( 'woo_storefront_auth_expired', __( 'The customer token is invalid or expired.', 'woo-storefront' ), 401 );
		}

		if (
			$type !== ( $claims['type'] ?? null ) ||
			! isset( $claims['sub'], $claims['jti'] ) ||
			! is_string( $claims['jti'] ) ||
			$this->is_revoked( $claims['jti'] )
		) {
			return $this->error( 'woo_storefront_auth_invalid', __( 'The customer token is invalid.', 'woo-storefront' ), 401 );
		}

		return $claims;
	}

	/**
	 * Build access/refresh credentials and customer data.
	 *
	 * @param int $customer_id Customer ID.
	 * @return array<string, mixed>
	 */
	private function token_response( int $customer_id ): array {
		return array(
			'access_token'  => $this->issue_token( $customer_id, 'access', self::ACCESS_TTL ),
			'refresh_token' => $this->issue_token( $customer_id, 'refresh', self::REFRESH_TTL ),
			'expires_in'    => self::ACCESS_TTL,
			'cart_token'    => $this->issue_cart_token( $customer_id ),
			'customer'      => self::customer_payload( $customer_id ),
		);
	}

	/**
	 * Issue a customer JWT.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $type        Token type.
	 * @param int    $ttl         Lifetime.
	 * @return string
	 */
	private function issue_token( int $customer_id, string $type, int $ttl ): string {
		$issued_at = time();
		return Jwt::encode(
			array(
				'iss'  => self::ISSUER,
				'sub'  => $customer_id,
				'iat'  => $issued_at,
				'nbf'  => $issued_at,
				'exp'  => $issued_at + $ttl,
				'jti'  => Base64Url::encode( random_bytes( 24 ) ),
				'type' => $type,
			),
			Secrets::auth()
		);
	}

	/**
	 * Issue a core-compatible Cart-Token for the merged customer session.
	 *
	 * @param int $customer_id Customer ID/session key.
	 * @return string
	 */
	private function issue_cart_token( int $customer_id ): string {
		$expiration = time() + (int) apply_filters( 'wc_session_expiration', 48 * HOUR_IN_SECONDS );
		return Jwt::encode(
			array(
				'user_id' => (string) $customer_id,
				'exp'     => $expiration,
				'iss'     => 'store-api',
			),
			'@' . wp_salt()
		);
	}

	/**
	 * Read Cart-Token from the request header or body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function request_cart_token( WP_REST_Request $request ): string {
		$header = $request->get_header( 'Cart-Token' );
		return '' !== $header ? $header : (string) $request->get_param( 'cart_token' );
	}

	/**
	 * Extract an Authorization bearer token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function bearer_token( WP_REST_Request $request ): string {
		$authorization = $request->get_header( 'Authorization' );
		return preg_match( '/^Bearer\s+(\S+)$/i', $authorization, $matches ) ? $matches[1] : '';
	}

	/**
	 * Revoke a token jti until its expiration.
	 *
	 * @param array<string, mixed> $claims Claims.
	 */
	private function revoke_claims( array $claims ): void {
		$jti     = (string) $claims['jti'];
		$expires = (int) $claims['exp'];
		$ttl     = max( 1, $expires - time() );
		set_transient( 'woo_storefront_revoked_' . hash( 'sha256', $jti ), 1, $ttl );

		$denylist = get_option( self::DENYLIST, array() );
		$denylist = is_array( $denylist ) ? $denylist : array();
		$denylist = array_filter( $denylist, static fn( mixed $expiry ): bool => (int) $expiry > time() );
		$denylist[ $jti ] = $expires;
		update_option( self::DENYLIST, $denylist, false );
	}

	/**
	 * Check transient and durable revocation stores.
	 *
	 * @param string $jti Token identifier.
	 * @return bool
	 */
	private function is_revoked( string $jti ): bool {
		if ( false !== get_transient( 'woo_storefront_revoked_' . hash( 'sha256', $jti ) ) ) {
			return true;
		}

		$denylist = get_option( self::DENYLIST, array() );
		return is_array( $denylist ) && isset( $denylist[ $jti ] ) && (int) $denylist[ $jti ] > time();
	}

	/**
	 * Merge stored guest cart contents into the customer session record.
	 *
	 * @param string $cart_token Cart-Token JWT.
	 * @param int    $customer_id Customer ID.
	 */
	private function merge_guest_cart( string $cart_token, int $customer_id ): void {
		try {
			$claims = Jwt::decode( $cart_token, '@' . wp_salt(), 'store-api' );
		} catch ( InvalidArgumentException $exception ) {
			return;
		}

		$guest_id = isset( $claims['user_id'] ) ? (string) $claims['user_id'] : '';
		if ( '' === $guest_id || (string) $customer_id === $guest_id ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		$guest_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT session_value, session_expiry FROM {$table} WHERE session_key = %s", $guest_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! is_array( $guest_row ) ) {
			return;
		}

		$customer_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT session_value, session_expiry FROM {$table} WHERE session_key = %s", (string) $customer_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$guest_data    = maybe_unserialize( $guest_row['session_value'] );
		$customer_data = is_array( $customer_row ) ? maybe_unserialize( $customer_row['session_value'] ) : array();
		$guest_data    = is_array( $guest_data ) ? $guest_data : array();
		$customer_data = is_array( $customer_data ) ? $customer_data : array();
		$guest_cart    = maybe_unserialize( $guest_data['cart'] ?? array() );
		$customer_cart = maybe_unserialize( $customer_data['cart'] ?? array() );
		$guest_cart    = is_array( $guest_cart ) ? $guest_cart : array();
		$customer_cart = is_array( $customer_cart ) ? $customer_cart : array();

		foreach ( $guest_cart as $key => $item ) {
			if ( isset( $customer_cart[ $key ] ) && is_array( $item ) && is_array( $customer_cart[ $key ] ) ) {
				$customer_cart[ $key ]['quantity'] = (int) ( $customer_cart[ $key ]['quantity'] ?? 0 ) + (int) ( $item['quantity'] ?? 0 );
			} else {
				$customer_cart[ $key ] = $item;
			}
		}

		$customer_data['cart'] = maybe_serialize( $customer_cart );
		foreach ( array( 'applied_coupons', 'coupon_discount_totals', 'coupon_discount_tax_totals' ) as $cart_key ) {
			if ( ! isset( $customer_data[ $cart_key ] ) && isset( $guest_data[ $cart_key ] ) ) {
				$customer_data[ $cart_key ] = $guest_data[ $cart_key ];
			}
		}

		$expiration = max(
			time() + (int) apply_filters( 'wc_session_expiration', 48 * HOUR_IN_SECONDS ),
			(int) ( $guest_row['session_expiry'] ?? 0 ),
			(int) ( is_array( $customer_row ) ? $customer_row['session_expiry'] : 0 )
		);

		$wpdb->replace(
			$table,
			array(
				'session_key'    => (string) $customer_id,
				'session_value'  => maybe_serialize( $customer_data ),
				'session_expiry' => $expiration,
			),
			array( '%s', '%s', '%d' )
		);

		$wpdb->delete( $table, array( 'session_key' => $guest_id ), array( '%s' ) );
	}

	/**
	 * Format a customer address.
	 *
	 * @param \WC_Customer $customer Customer.
	 * @param string       $type     billing|shipping.
	 * @return array<string, string>
	 */
	private static function address_payload( \WC_Customer $customer, string $type ): array {
		$prefix = 'get_' . $type . '_';
		$address = array(
			'firstName' => (string) $customer->{ $prefix . 'first_name' }(),
			'lastName'  => (string) $customer->{ $prefix . 'last_name' }(),
			'company'   => (string) $customer->{ $prefix . 'company' }(),
			'address1'  => (string) $customer->{ $prefix . 'address_1' }(),
			'address2'  => (string) $customer->{ $prefix . 'address_2' }(),
			'city'      => (string) $customer->{ $prefix . 'city' }(),
			'state'     => (string) $customer->{ $prefix . 'state' }(),
			'postcode'  => (string) $customer->{ $prefix . 'postcode' }(),
			'country'   => (string) $customer->{ $prefix . 'country' }(),
		);

		if ( 'billing' === $type ) {
			$address['email'] = $customer->get_billing_email();
			$address['phone'] = $customer->get_billing_phone();
		}

		return $address;
	}

	/**
	 * Create a REST error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
