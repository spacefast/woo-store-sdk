<?php
/**
 * WordPress-free unit test bootstrap.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86_400 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @param array<string, mixed> $data */
		public function __construct(
			public string $code,
			public string $message,
			public array $data = array()
		) {}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @param array<string, string> $headers */
		public function __construct( private array $headers = array() ) {}

		public function get_header( string $name ): ?string {
			return $this->headers[ strtolower( $name ) ] ?? null;
		}
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname( __DIR__ ) . '/src/Support/Base64Url.php';
require_once dirname( __DIR__ ) . '/src/Support/Jwt.php';
require_once dirname( __DIR__ ) . '/src/Support/SignedKey.php';
require_once dirname( __DIR__ ) . '/src/Support/Secrets.php';
require_once dirname( __DIR__ ) . '/src/Auth.php';
