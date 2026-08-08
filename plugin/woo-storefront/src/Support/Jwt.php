<?php
/**
 * Minimal HS256 JSON Web Token implementation.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront\Support;

use InvalidArgumentException;
use JsonException;

/**
 * Issues and validates HS256 JWTs without WordPress dependencies.
 */
final class Jwt {
	/**
	 * Encode claims as an HS256 JWT.
	 *
	 * @param array<string, mixed> $claims Claims.
	 * @param string               $secret Signing secret.
	 * @return string
	 */
	public static function encode( array $claims, string $secret ): string {
		if ( '' === $secret ) {
			throw new InvalidArgumentException( 'A JWT secret is required.' );
		}

		try {
			$header  = Base64Url::encode( (string) wp_json_encode_compat( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
			$payload = Base64Url::encode( (string) wp_json_encode_compat( $claims ) );
		} catch ( JsonException $exception ) {
			throw new InvalidArgumentException( 'JWT claims are not JSON encodable.', 0, $exception );
		}

		$signature = hash_hmac( 'sha256', $header . '.' . $payload, $secret, true );

		return $header . '.' . $payload . '.' . Base64Url::encode( $signature );
	}

	/**
	 * Decode and validate an HS256 JWT.
	 *
	 * @param string      $token           JWT.
	 * @param string      $secret          Signing secret.
	 * @param string|null $expected_issuer Required issuer, when supplied.
	 * @param int|null    $now             Current Unix time for deterministic tests.
	 * @return array<string, mixed>
	 */
	public static function decode(
		string $token,
		string $secret,
		?string $expected_issuer = null,
		?int $now = null
	): array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || '' === $secret ) {
			throw new InvalidArgumentException( 'Malformed JWT.' );
		}

		try {
			$header = json_decode( Base64Url::decode( $parts[0] ), true, 512, JSON_THROW_ON_ERROR );
			$claims = json_decode( Base64Url::decode( $parts[1] ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			throw new InvalidArgumentException( 'Malformed JWT JSON.', 0, $exception );
		}

		if ( ! is_array( $header ) || 'HS256' !== ( $header['alg'] ?? null ) || ! is_array( $claims ) ) {
			throw new InvalidArgumentException( 'Unsupported JWT.' );
		}

		$expected_signature = hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], $secret, true );
		$actual_signature   = Base64Url::decode( $parts[2] );

		if ( ! hash_equals( $expected_signature, $actual_signature ) ) {
			throw new InvalidArgumentException( 'Invalid JWT signature.' );
		}

		$current_time = $now ?? time();
		if ( isset( $claims['nbf'] ) && (int) $claims['nbf'] > $current_time ) {
			throw new InvalidArgumentException( 'JWT is not active.' );
		}

		if ( ! isset( $claims['exp'] ) || (int) $claims['exp'] <= $current_time ) {
			throw new InvalidArgumentException( 'JWT has expired.' );
		}

		if ( null !== $expected_issuer && $expected_issuer !== ( $claims['iss'] ?? null ) ) {
			throw new InvalidArgumentException( 'Invalid JWT issuer.' );
		}

		return $claims;
	}
}

/**
 * Encode JSON with the strict native encoder while remaining WordPress-free.
 *
 * This namespaced function intentionally avoids wp_json_encode so crypto tests
 * can run without loading WordPress.
 *
 * @param mixed $value Value to encode.
 * @return string
 * @throws JsonException When encoding fails.
 */
function wp_json_encode_compat( mixed $value ): string {
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
}
