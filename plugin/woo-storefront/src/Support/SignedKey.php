<?php
/**
 * Short-lived HMAC key helper.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront\Support;

/**
 * Signs an opaque value and absolute expiration.
 */
final class SignedKey {
	/**
	 * Create a key over value + expiration.
	 *
	 * @param string $value   Opaque value.
	 * @param int    $expires Absolute Unix expiration.
	 * @param string $secret  Secret.
	 * @return string
	 */
	public static function create( string $value, int $expires, string $secret ): string {
		return Base64Url::encode( hash_hmac( 'sha256', self::message( $value, $expires ), $secret, true ) );
	}

	/**
	 * Verify a key and expiration.
	 *
	 * @param string   $value   Opaque value.
	 * @param int      $expires Absolute Unix expiration.
	 * @param string   $key     Presented key.
	 * @param string   $secret  Secret.
	 * @param int|null $now     Current Unix time for deterministic tests.
	 * @return bool
	 */
	public static function verify(
		string $value,
		int $expires,
		string $key,
		string $secret,
		?int $now = null
	): bool {
		if ( '' === $value || '' === $key || '' === $secret || $expires <= ( $now ?? time() ) ) {
			return false;
		}

		return hash_equals( self::create( $value, $expires, $secret ), $key );
	}

	/**
	 * Produce an unambiguous signed message.
	 *
	 * @param string $value   Value.
	 * @param int    $expires Expiration.
	 * @return string
	 */
	private static function message( string $value, int $expires ): string {
		return strlen( $value ) . ':' . $value . ':' . $expires;
	}
}
