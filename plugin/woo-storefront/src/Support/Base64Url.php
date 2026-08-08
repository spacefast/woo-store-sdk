<?php
/**
 * Base64 URL encoding helpers.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront\Support;

use InvalidArgumentException;

/**
 * RFC 4648 base64url helpers without padding.
 */
final class Base64Url {
	/**
	 * Encode binary data.
	 *
	 * @param string $value Binary data.
	 * @return string
	 */
	public static function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode base64url data.
	 *
	 * @param string $value Encoded data.
	 * @return string
	 */
	public static function decode( string $value ): string {
		if ( 1 === strlen( $value ) % 4 ) {
			throw new InvalidArgumentException( 'Invalid base64url value.' );
		}

		$padding = ( 4 - ( strlen( $value ) % 4 ) ) % 4;
		$decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );

		if ( false === $decoded ) {
			throw new InvalidArgumentException( 'Invalid base64url value.' );
		}

		return $decoded;
	}
}
