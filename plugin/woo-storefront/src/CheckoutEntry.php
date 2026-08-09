<?php
/**
 * Hosted checkout entry and return handling.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

use InvalidArgumentException;
use WooStorefront\Support\Jwt;
use WooStorefront\Support\Secrets;
use WooStorefront\Support\SignedKey;

/**
 * Validates cart handoffs and adopts the Cart-Token's Woo session.
 */
final class CheckoutEntry {
	private const QUERY_VAR          = 'woo_storefront_cart_token';
	private const RETURN_SESSION_KEY = 'woo_storefront_return';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle_entry' ), 1 );
		add_action( 'template_redirect', array( $this, 'capture_return_url' ), 5 );
		add_action( 'template_redirect', array( $this, 'redirect_after_order' ), 20 );
		add_filter( 'template_include', array( $this, 'checkout_template' ), 99 );
	}

	/**
	 * Add the checkout entry rewrite.
	 */
	public static function add_rewrite_rule(): void {
		add_rewrite_rule( '^checkout/c/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Add the token query variable.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Validate a handoff, set the target Woo session cookie, and redirect.
	 */
	public function handle_entry(): void {
		$cart_token = get_query_var( self::QUERY_VAR );
		if ( ! is_string( $cart_token ) || '' === $cart_token ) {
			return;
		}

		$cart_token = rawurldecode( $cart_token );
		$expires    = isset( $_GET['expires'] ) ? absint( wp_unslash( $_GET['expires'] ) ) : 0;
		$key        = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( ! SignedKey::verify( $cart_token, $expires, $key, Secrets::checkout() ) ) {
			$this->reject( __( 'This checkout link is invalid or has expired.', 'woo-storefront' ) );
		}

		try {
			$claims = Jwt::decode( $cart_token, '@' . wp_salt(), 'store-api' );
		} catch ( InvalidArgumentException $exception ) {
			$this->reject( __( 'The cart session is invalid or has expired.', 'woo-storefront' ) );
		}

		$user_id = $claims['user_id'] ?? null;
		if ( ! is_string( $user_id ) && ! is_int( $user_id ) ) {
			$this->reject( __( 'The cart session has no customer identifier.', 'woo-storefront' ) );
		}

		$this->set_session_cookie( (string) $user_id, (int) $claims['exp'] );

		$checkout_url = wc_get_checkout_url();
		$return_args  = $this->validated_return_args();
		if ( null !== $return_args ) {
			$checkout_url = add_query_arg( $return_args, $checkout_url );
		}

		wp_safe_redirect( $checkout_url, 302, 'Woo Storefront' );
		exit;
	}

	/**
	 * Persist a signed return target once the adopted session is loaded.
	 */
	public function capture_return_url(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$return_args = $this->validated_return_args();
		if ( null === $return_args ) {
			return;
		}

		WC()->session->set(
			self::RETURN_SESSION_KEY,
			array(
				'url'     => $return_args['return_url'],
				'expires' => (int) $return_args['return_expires'],
				'key'     => $return_args['return_key'],
			)
		);
	}

	/**
	 * Redirect a completed checkout to its still-valid signed return target.
	 */
	public function redirect_after_order(): void {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$return = WC()->session->get( self::RETURN_SESSION_KEY );
		WC()->session->__unset( self::RETURN_SESSION_KEY );

		if ( ! is_array( $return ) || ! isset( $return['url'], $return['expires'], $return['key'] ) ) {
			return;
		}

		$url     = (string) $return['url'];
		$expires = (int) $return['expires'];
		$key     = (string) $return['key'];
		if ( ! self::is_valid_return_url( $url ) || ! SignedKey::verify( $url, $expires, $key, Secrets::checkout() ) ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			$url   = add_query_arg(
				array(
					'order_id'  => $order_id,
					'order_key' => $order ? $order->get_order_key() : '',
				),
				$url
			);
		}

		wp_redirect( esc_url_raw( $url ), 302, 'Woo Storefront' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL is HMAC-authenticated.
		exit;
	}

	/**
	 * Use the isolated Checkout Block shell on the main checkout route.
	 *
	 * @param string $template Resolved template.
	 * @return string
	 */
	public function checkout_template( string $template ): string {
		if (
			function_exists( 'is_checkout' ) &&
			is_checkout() &&
			! is_wc_endpoint_url( 'order-received' ) &&
			! is_wc_endpoint_url( 'order-pay' )
		) {
			return WOO_STOREFRONT_PATH . 'templates/checkout-shell.php';
		}

		return $template;
	}

	/**
	 * Validate an external return URL shape before signature verification.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_valid_return_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Read and validate signed return query parameters.
	 *
	 * @return array{return_url: string, return_expires: int, return_key: string}|null
	 */
	private function validated_return_args(): ?array {
		$return_url     = isset( $_GET['return_url'] ) ? esc_url_raw( wp_unslash( $_GET['return_url'] ) ) : '';
		$return_expires = isset( $_GET['return_expires'] ) ? absint( wp_unslash( $_GET['return_expires'] ) ) : 0;
		$return_key     = isset( $_GET['return_key'] ) ? sanitize_text_field( wp_unslash( $_GET['return_key'] ) ) : '';

		if (
			! self::is_valid_return_url( $return_url ) ||
			! SignedKey::verify( $return_url, $return_expires, $return_key, Secrets::checkout() )
		) {
			return null;
		}

		return array(
			'return_url'     => $return_url,
			'return_expires' => $return_expires,
			'return_key'     => $return_key,
		);
	}

	/**
	 * Create the same session cookie shape as WC_Session_Handler.
	 *
	 * @param string $customer_id Session/customer identifier from Cart-Token.
	 * @param int    $expiration  Token expiration.
	 */
	private function set_session_cookie( string $customer_id, int $expiration ): void {
		$cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;
		$expiring    = max( time(), $expiration - HOUR_IN_SECONDS );

		// Mirror WC_Session_Handler exactly: hash over "customer_id|expiration",
		// via wp_fast_hash on WP 6.8+ (`$generic$…`) with the pre-6.8 HMAC
		// fallback, and single-pipe field delimiters (current cookie format).
		$to_hash     = $customer_id . '|' . $expiration;
		$cookie_hash = function_exists( 'wp_fast_hash' )
			? wp_fast_hash( $to_hash )
			: hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
		$cookie      = implode( '|', array( $customer_id, (string) $expiration, (string) $expiring, $cookie_hash ) );

		wc_setcookie( $cookie_name, $cookie, $expiration, is_ssl(), true );
		$_COOKIE[ $cookie_name ] = $cookie;
	}

	/**
	 * Terminate an invalid checkout entry.
	 *
	 * @param string $message Error message.
	 * @return never
	 */
	private function reject( string $message ): never {
		wp_die( esc_html( $message ), esc_html__( 'Checkout unavailable', 'woo-storefront' ), array( 'response' => 403 ) );
	}
}
