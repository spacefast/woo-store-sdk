<?php
/**
 * Persistent plugin secrets.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront\Support;

/**
 * Retrieves or creates non-autoloaded secrets.
 */
final class Secrets {
	public const CHECKOUT_OPTION = 'woo_storefront_checkout_secret';
	public const AUTH_OPTION     = 'woo_storefront_auth_secret';

	/**
	 * Checkout URL signing secret.
	 *
	 * @return string
	 */
	public static function checkout(): string {
		return self::get_or_create( self::CHECKOUT_OPTION );
	}

	/**
	 * Customer JWT signing secret.
	 *
	 * @return string
	 */
	public static function auth(): string {
		return self::get_or_create( self::AUTH_OPTION );
	}

	/**
	 * Retrieve or atomically create an option.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	private static function get_or_create( string $option ): string {
		$secret = get_option( $option, '' );
		if ( is_string( $secret ) && '' !== $secret ) {
			return $secret;
		}

		$generated = Base64Url::encode( random_bytes( 48 ) );
		if ( add_option( $option, $generated, '', false ) ) {
			return $generated;
		}

		$stored = get_option( $option, '' );
		return is_string( $stored ) && '' !== $stored ? $stored : $generated;
	}
}
