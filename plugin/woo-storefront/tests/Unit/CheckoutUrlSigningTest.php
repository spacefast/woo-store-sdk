<?php
/**
 * Checkout URL signing unit tests.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WooStorefront\Support\SignedKey;

/**
 * @covers \WooStorefront\Support\SignedKey
 */
final class CheckoutUrlSigningTest extends TestCase {
	public function test_signs_and_verifies_cart_token_and_expiry(): void {
		$key = SignedKey::create( 'header.payload.signature', 2_000, 'checkout-secret' );

		self::assertTrue( SignedKey::verify( 'header.payload.signature', 2_000, $key, 'checkout-secret', 1_000 ) );
	}

	public function test_rejects_expired_key(): void {
		$key = SignedKey::create( 'cart-token', 999, 'checkout-secret' );

		self::assertFalse( SignedKey::verify( 'cart-token', 999, $key, 'checkout-secret', 1_000 ) );
	}

	public function test_signature_binds_token_and_expiry(): void {
		$key = SignedKey::create( 'cart-token', 2_000, 'checkout-secret' );

		self::assertFalse( SignedKey::verify( 'different-token', 2_000, $key, 'checkout-secret', 1_000 ) );
		self::assertFalse( SignedKey::verify( 'cart-token', 2_001, $key, 'checkout-secret', 1_000 ) );
	}
}
