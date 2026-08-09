<?php
/**
 * Authentication boundary tests.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WooStorefront\Auth;

/**
 * @covers \WooStorefront\Auth
 */
final class AuthTest extends TestCase {
	public function test_missing_authorization_header_returns_the_typed_auth_error(): void {
		$error = ( new Auth() )->authorize( new \WP_REST_Request() );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'woo_storefront_auth_required', $error->code );
		self::assertSame( 401, $error->data['status'] );
	}
}
