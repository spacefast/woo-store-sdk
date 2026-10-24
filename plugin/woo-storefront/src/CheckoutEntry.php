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
 * Validates cart handoffs and lets Woo clone the cart into a private browser session.
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
	 * Validate a handoff and redirect through Woo's native guest-session cloning.
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
		if ( ! is_string( $user_id ) || ! str_starts_with( $user_id, 't_' ) ) {
			$this->reject( __( 'The cart session is not a guest cart.', 'woo-storefront' ) );
		}

		// WC_Session_Handler clones guest carts and excludes customer details. Sharing
		// the token's session cookie would expose checkout PII to anyone holding it.
		$checkout_url = add_query_arg( 'session', $cart_token, wc_get_checkout_url() );
		$return_args  = $this->validated_return_args();
		if ( null !== $return_args ) {
			$checkout_url = add_query_arg( $return_args, $checkout_url );
		}

		wp_safe_redirect( $checkout_url, 302, 'Woo Storefront' );
		exit;
	}

	/**
	 * Persist a signed return target once the cloned session is loaded.
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
		if ( ! apply_filters( 'woo_storefront_checkout_redirect_after_order', true ) ) {
			return;
		}

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
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order || ! is_string( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return;
		}
		$url = add_query_arg(
			array(
				'order_id'  => $order_id,
				'order_key' => $order_key,
			),
			$url
		);

		wp_safe_redirect( esc_url_raw( $url ), 302, 'Woo Storefront' );
		exit;
	}

	/**
	 * Use the isolated Checkout Block shell on the main checkout route.
	 *
	 * @param string $template Resolved template.
	 * @return string
	 */
	public function checkout_template( string $template ): string {
		$checkout =
			function_exists( 'is_checkout' ) &&
			is_checkout() &&
			! is_wc_endpoint_url( 'order-received' ) &&
			! is_wc_endpoint_url( 'order-pay' );
		if ( $checkout || ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
			return WOO_STOREFRONT_PATH . 'templates/checkout-shell.php';
		}

		return $template;
	}

	/**
	 * Accept return targets only on the configured storefront origin, even when signed.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_valid_return_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) ||
			! isset( $parts['scheme'], $parts['host'], $home['scheme'], $home['host'] ) ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			preg_match( '/[\\x00-\\x20\\x7f\\\\\\\\]/', $url ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$home_scheme = strtolower( $home['scheme'] );
		return in_array( $scheme, array( 'http', 'https' ), true )
			&& $scheme === $home_scheme
			&& strtolower( $parts['host'] ) === strtolower( $home['host'] )
			&& ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) ) === ( $home['port'] ?? ( 'https' === $home_scheme ? 443 : 80 ) );
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
	 * Terminate an invalid checkout entry.
	 *
	 * @param string $message Error message.
	 * @return never
	 */
	private function reject( string $message ): never {
		wp_die( esc_html( $message ), esc_html__( 'Checkout unavailable', 'woo-storefront' ), array( 'response' => 403 ) );
	}
}
