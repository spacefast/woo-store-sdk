<?php
/**
 * JWT unit tests.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WooStorefront\Support\Jwt;

/**
 * @covers \WooStorefront\Support\Jwt
 */
final class JwtTest extends TestCase {
	public function test_issues_and_validates_hs256_token(): void {
		$claims = array(
			'iss'     => 'store-api',
			'user_id' => 'guest-session',
			'exp'     => 2_000,
		);

		$token = Jwt::encode( $claims, 'test-secret' );

		self::assertSame( $claims, Jwt::decode( $token, 'test-secret', 'store-api', 1_000 ) );
	}

	public function test_rejects_tampered_signature(): void {
		$token = Jwt::encode( array( 'iss' => 'store-api', 'exp' => 2_000 ), 'test-secret' );
		$token = substr( $token, 0, -1 ) . ( str_ends_with( $token, 'a' ) ? 'b' : 'a' );

		$this->expectException( InvalidArgumentException::class );
		Jwt::decode( $token, 'test-secret', 'store-api', 1_000 );
	}

	public function test_rejects_expired_token(): void {
		$token = Jwt::encode( array( 'iss' => 'woo-storefront', 'exp' => 999 ), 'test-secret' );

		$this->expectException( InvalidArgumentException::class );
		Jwt::decode( $token, 'test-secret', 'woo-storefront', 1_000 );
	}

	public function test_rejects_wrong_issuer(): void {
		$token = Jwt::encode( array( 'iss' => 'other', 'exp' => 2_000 ), 'test-secret' );

		$this->expectException( InvalidArgumentException::class );
		Jwt::decode( $token, 'test-secret', 'woo-storefront', 1_000 );
	}
}
