<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * A compiled store bundle: `<dir>/store.json` plus `<dir>/files/<sha256>` (interfaces §1).
 *
 * Validation is structural and strict (unknown keys are refused) so a drift between the
 * TypeScript compiler and this reader fails the golden-file contract instead of being ignored.
 * The bundle may omit files the box already holds; Catalog decides whether a missing file is held.
 */
final class Bundle {
	public const EMAILS = array(
		'customer_processing_order' => 'emails/customer-processing-order.php',
		'customer_completed_order' => 'emails/customer-completed-order.php',
		'new_order' => 'emails/admin-new-order.php',
	);

	public array $store = array();
	public string $hash = '';
	/** @var array<string, string> Verified sha256 → path cache. */
	private array $verified = array();

	private function __construct( private string $directory ) {}

	public static function open( string $directory ): self|\WP_Error {
		$directory = rtrim( $directory, '/' );
		$file = $directory . '/store.json';
		if ( ! is_dir( $directory ) || ! is_readable( $file ) ) {
			return self::refuse( 'store-json-invalid', 'The bundle has no readable store.json.' );
		}
		$bytes = (string) file_get_contents( $file );
		$store = json_decode( $bytes, true );
		if ( ! is_array( $store ) || array_is_list( $store ) ) {
			return self::refuse( 'store-json-invalid', 'store.json is not a JSON object.' );
		}
		$bundle = new self( $directory );
		$bundle->hash = hash( 'sha256', $bytes );
		$valid = $bundle->validate( $store );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$bundle->store = $store;
		return $bundle;
	}

	public static function refuse( string $code, string $detail, ?string $pointer = null, ?string $key = null ): \WP_Error {
		return new \WP_Error( $code, $detail, array( 'pointer' => $pointer, 'key' => $key ) );
	}

	/** Path of a verified bundle file, or null when the bundle does not carry it. A corrupt file is an error. */
	public function file( array $ref, string $pointer ): string|null|\WP_Error {
		$sha = $ref['sha256'];
		if ( isset( $this->verified[ $sha ] ) && filesize( $this->verified[ $sha ] ) === $ref['size'] ) {
			return $this->verified[ $sha ];
		}
		$path = $this->directory . '/files/' . $sha;
		if ( ! is_file( $path ) ) {
			return null;
		}
		if ( filesize( $path ) !== $ref['size'] || ! hash_equals( $sha, (string) hash_file( 'sha256', $path ) ) ) {
			return self::refuse( 'file-integrity', 'Bundle file ' . $sha . ' does not match its declared size and sha256.', $pointer );
		}
		$this->verified[ $sha ] = $path;
		return $path;
	}

	private function validate( array $store ): true|\WP_Error {
		if ( 1 !== ( $store['schemaVersion'] ?? null ) ) {
			return self::refuse( 'schema-version', 'This plugin reads store.json schemaVersion 1.', '/schemaVersion' );
		}
		$checks = array(
			fn () => $this->keys( $store, array( 'schemaVersion', 'merchant', 'shipping', 'products', 'emails' ), '' ),
			fn () => $this->merchant( $store['merchant'] ),
			fn () => $this->shipping( $store['shipping'], $store['merchant']['currency'] ),
		);
		foreach ( $checks as $validate ) {
			$check = $validate();
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		$products = $store['products'];
		if ( ! is_array( $products ) || ! array_is_list( $products ) || count( $products ) > 100 ) {
			return self::refuse( 'store-json-invalid', 'products must be a list of at most 100 products.', '/products' );
		}
		$seen = array();
		foreach ( $products as $index => $product ) {
			$check = $this->product( $product, '/products/' . $index, $store['merchant']['currency'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			if ( isset( $seen[ $product['key'] ] ) ) {
				return self::refuse( 'duplicate-key', 'Product key "' . $product['key'] . '" is declared twice.', '/products/' . $index . '/key', $product['key'] );
			}
			$seen[ $product['key'] ] = true;
			if ( 'physical' === $product['kind'] && null === $store['shipping'] ) {
				return self::refuse( 'shipping-required', 'Physical products need store-level shipping.', '/shipping', $product['key'] );
			}
		}
		return $this->emails( $store['emails'] );
	}

	private function keys( mixed $value, array $allowed, string $pointer, array $optional = array() ): true|\WP_Error {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			return self::refuse( 'store-json-invalid', 'Expected an object.', '' === $pointer ? null : $pointer );
		}
		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return self::refuse( 'store-json-invalid', 'Unknown field "' . $key . '".', $pointer . '/' . $key );
			}
		}
		foreach ( array_diff( $allowed, $optional ) as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return self::refuse( 'store-json-invalid', 'Missing field "' . $key . '".', $pointer . '/' . $key );
			}
		}
		return true;
	}

	private function merchant( mixed $merchant ): true|\WP_Error {
		$keys = $this->keys( $merchant, array( 'name', 'brand', 'country', 'currency', 'downloads' ), '/merchant' );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}
		if ( ! is_string( $merchant['name'] ) || '' === trim( $merchant['name'] ) || strlen( $merchant['name'] ) > 255 ) {
			return self::refuse( 'store-json-invalid', 'merchant.name must be a non-empty string.', '/merchant/name' );
		}
		$brand = $this->keys( $merchant['brand'], array( 'logo', 'accent' ), '/merchant/brand' );
		if ( is_wp_error( $brand ) ) {
			return $brand;
		}
		$logo = $this->media_ref( $merchant['brand']['logo'], '/merchant/brand/logo' );
		if ( is_wp_error( $logo ) ) {
			return $logo;
		}
		if ( null !== $merchant['brand']['accent'] && ( ! is_string( $merchant['brand']['accent'] ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/D', $merchant['brand']['accent'] ) ) ) {
			return self::refuse( 'store-json-invalid', 'merchant.brand.accent must be a #rrggbb color or null.', '/merchant/brand/accent' );
		}
		if ( ! is_string( $merchant['country'] ) || ! isset( WC()->countries->get_countries()[ $merchant['country'] ] ) ) {
			return self::refuse( 'store-json-invalid', 'merchant.country must be an ISO 3166-1 alpha-2 country Woo knows.', '/merchant/country' );
		}
		if ( ! is_string( $merchant['currency'] ) || ! preg_match( '/^[a-z]{3}$/D', $merchant['currency'] ) ||
			! array_key_exists( strtoupper( $merchant['currency'] ), get_woocommerce_currencies() ) ) {
			return self::refuse( 'store-json-invalid', 'merchant.currency must be a lowercase ISO 4217 code Woo supports.', '/merchant/currency' );
		}
		$downloads = $this->keys( $merchant['downloads'], array( 'limit', 'expiryDays' ), '/merchant/downloads' );
		if ( is_wp_error( $downloads ) ) {
			return $downloads;
		}
		foreach ( array( 'limit', 'expiryDays' ) as $field ) {
			$value = $merchant['downloads'][ $field ];
			if ( null !== $value && ( ! is_int( $value ) || $value < 1 || $value > 100000 ) ) {
				return self::refuse( 'store-json-invalid', 'merchant.downloads.' . $field . ' must be a positive integer or null.', '/merchant/downloads/' . $field );
			}
		}
		return true;
	}

	private function shipping( mixed $shipping, string $currency ): true|\WP_Error {
		if ( null === $shipping ) {
			return true;
		}
		$keys = $this->keys( $shipping, array( 'countries', 'rate', 'policy' ), '/shipping' );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}
		$countries = $shipping['countries'];
		if ( ! is_array( $countries ) || ! array_is_list( $countries ) || array() === $countries ||
			count( $countries ) !== count( array_unique( $countries ) ) ) {
			return self::refuse( 'store-json-invalid', 'shipping.countries must be a non-empty list of distinct countries.', '/shipping/countries' );
		}
		foreach ( $countries as $index => $country ) {
			if ( ! is_string( $country ) || ! isset( WC()->countries->get_countries()[ $country ] ) ) {
				return self::refuse( 'store-json-invalid', 'Unknown shipping country.', '/shipping/countries/' . $index );
			}
		}
		$rate = $this->keys( $shipping['rate'], array( 'amountMinor' ), '/shipping/rate' );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		if ( ! is_int( $shipping['rate']['amountMinor'] ) || $shipping['rate']['amountMinor'] < 0 ) {
			return self::refuse( 'store-json-invalid', 'shipping.rate.amountMinor must be a non-negative integer.', '/shipping/rate/amountMinor' );
		}
		if ( $shipping['rate']['amountMinor'] > 0 && is_wp_error( Planner::price_decimal( $shipping['rate']['amountMinor'], $currency ) ) ) {
			return self::refuse( 'store-json-invalid', 'The shipping amount must be chargeable in the merchant currency.', '/shipping/rate/amountMinor' );
		}
		if ( null !== $shipping['policy'] && ( ! is_string( $shipping['policy'] ) || strlen( $shipping['policy'] ) > 1200 ) ) {
			return self::refuse( 'store-json-invalid', 'shipping.policy must be text or null.', '/shipping/policy' );
		}
		return true;
	}

	private function product( mixed $product, string $at, string $currency ): true|\WP_Error {
		$keys = $this->keys( $product, array( 'key', 'kind', 'name', 'summary', 'description', 'price', 'image', 'page', 'asset', 'active', 'stock' ), $at );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}
		$key = is_string( $product['key'] ) ? $product['key'] : null;
		if ( null === $key || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key ) ) {
			return self::refuse( 'store-json-invalid', 'Product keys are 1-64 lowercase letters, digits, "_" or "-".', $at . '/key' );
		}
		$fail = static fn ( string $field, string $detail, string $code = 'store-json-invalid' ): \WP_Error => self::refuse( $code, $detail, $at . '/' . $field, $key );
		if ( ! in_array( $product['kind'], array( 'digital', 'physical' ), true ) ) {
			return $fail( 'kind', 'kind is "digital" or "physical".' );
		}
		if ( ! is_string( $product['name'] ) || '' === trim( $product['name'] ) || strlen( $product['name'] ) > 255 ) {
			return $fail( 'name', 'name must be a non-empty string.' );
		}
		if ( null !== $product['summary'] && ( ! is_string( $product['summary'] ) || strlen( $product['summary'] ) > 2000 ) ) {
			return $fail( 'summary', 'summary must be text or null.' );
		}
		if ( null !== $product['description'] && ( ! is_string( $product['description'] ) || mb_strlen( $product['description'] ) > 2000 ) ) {
			return $fail( 'description', 'description must be plain text up to 2000 characters, or null.' );
		}
		$price = $this->keys( $product['price'], array( 'amountMinor', 'currency' ), $at . '/price' );
		if ( is_wp_error( $price ) ) {
			return $price;
		}
		if ( ! is_int( $product['price']['amountMinor'] ) || $product['price']['amountMinor'] <= 0 ) {
			return $fail( 'price/amountMinor', 'price.amountMinor must be a positive integer.' );
		}
		if ( $product['price']['currency'] !== $currency ) {
			return $fail( 'price/currency', 'Product currency must equal merchant.currency (' . $currency . ').', 'currency-mismatch' );
		}
		if ( is_wp_error( Planner::price_decimal( $product['price']['amountMinor'], $currency ) ) ) {
			return $fail( 'price/amountMinor', 'This currency only accepts whole units.' );
		}
		$image = $this->media_ref( $product['image'], $at . '/image' );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		if ( null !== $product['page'] && ( ! is_string( $product['page'] ) || ! preg_match( '#^/[^\s?\#]*$#D', $product['page'] ) || str_starts_with( $product['page'], '//' ) ) ) {
			return $fail( 'page', 'page must be a site-relative path such as /books/field-guide/.' );
		}
		if ( 'digital' === $product['kind'] && null === $product['asset'] ) {
			return $fail( 'asset', 'A digital product needs its download asset.', 'asset-required' );
		}
		if ( 'physical' === $product['kind'] && null !== $product['asset'] ) {
			return $fail( 'asset', 'Only digital products carry a download asset.' );
		}
		if ( null !== $product['asset'] ) {
			$asset = $this->file_ref( $product['asset'], $at . '/asset' );
			if ( is_wp_error( $asset ) ) {
				return $asset;
			}
		}
		if ( ! is_bool( $product['active'] ) ) {
			return $fail( 'active', 'active must be a boolean.' );
		}
		if ( null !== $product['stock'] ) {
			if ( 'digital' === $product['kind'] ) {
				return $fail( 'stock', 'Only physical products track stock.' );
			}
			$stock = $this->keys( $product['stock'], array( 'track', 'initial' ), $at . '/stock' );
			if ( is_wp_error( $stock ) ) {
				return $stock;
			}
			if ( ! is_bool( $product['stock']['track'] ) || ( null !== $product['stock']['initial'] && ( ! is_int( $product['stock']['initial'] ) || $product['stock']['initial'] < 0 ) ) ) {
				return $fail( 'stock', 'stock is { track: boolean, initial: non-negative integer | null }.' );
			}
		}
		return true;
	}

	private function media_ref( mixed $ref, string $pointer ): true|\WP_Error {
		if ( null === $ref ) {
			return true;
		}
		if ( is_array( $ref ) && array_key_exists( 'url', $ref ) ) {
			$keys = $this->keys( $ref, array( 'url' ), $pointer );
			if ( is_wp_error( $keys ) ) {
				return $keys;
			}
			$parts = is_string( $ref['url'] ) ? wp_parse_url( $ref['url'] ) : false;
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $ref['url'] ) > 2048 ) {
				return self::refuse( 'store-json-invalid', 'Image URLs must be public https URLs.', $pointer . '/url' );
			}
			return true;
		}
		return $this->file_ref( $ref, $pointer );
	}

	private function file_ref( mixed $ref, string $pointer ): true|\WP_Error {
		$keys = $this->keys( $ref, array( 'path', 'sha256', 'size' ), $pointer );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}
		$name = is_string( $ref['path'] ) ? basename( $ref['path'] ) : '';
		if ( '' === sanitize_file_name( $name ) || strlen( $name ) > 180 || preg_match( '/[\x00-\x1f\x7f]/', $ref['path'] ) ) {
			return self::refuse( 'store-json-invalid', 'File references need a path with a file name.', $pointer . '/path' );
		}
		if ( ! is_string( $ref['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $ref['sha256'] ) ) {
			return self::refuse( 'store-json-invalid', 'sha256 must be 64 lowercase hex characters.', $pointer . '/sha256' );
		}
		if ( ! is_int( $ref['size'] ) || $ref['size'] < 0 || $ref['size'] > 1024 * 1024 * 1024 ) {
			return self::refuse( 'store-json-invalid', 'size must be a byte count.', $pointer . '/size' );
		}
		return true;
	}

	private function emails( mixed $emails ): true|\WP_Error {
		if ( ! is_array( $emails ) || ( array() !== $emails && array_is_list( $emails ) ) ) {
			return self::refuse( 'store-json-invalid', 'emails must be an object keyed by Woo email ID.', '/emails' );
		}
		foreach ( $emails as $id => $email ) {
			$at = '/emails/' . $id;
			if ( ! isset( self::EMAILS[ $id ] ) ) {
				return self::refuse( 'email-unsupported', 'Supported email IDs: ' . implode( ', ', array_keys( self::EMAILS ) ) . '.', $at );
			}
			$keys = $this->keys( $email, array( 'html', 'sha256' ), $at );
			if ( is_wp_error( $keys ) ) {
				return $keys;
			}
			if ( ! is_string( $email['html'] ) || '' === $email['html'] || ! is_string( $email['sha256'] ) ||
				! hash_equals( hash( 'sha256', $email['html'] ), $email['sha256'] ) ) {
				return self::refuse( 'email-hash-mismatch', 'Email HTML does not match its sha256.', $at . '/sha256' );
			}
			$valid = $this->email_html( $email['html'], $at . '/html' );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		return true;
	}

	/** The bundle is an independent input: enforce the compiler's static-email contract here too. */
	private function email_html( string $html, string $pointer ): true|\WP_Error {
		if ( strlen( $html ) > 100 * 1024 || ! preg_match( '/^\s*<!doctype\s+html\b/i', $html ) ) {
			return self::refuse( 'email-invalid', 'An email must be a complete HTML document beginning with <!doctype html>, up to 100 KiB.', $pointer );
		}
		$slots = array( 'SF-STORE-NAME', 'SF-EMAIL-HEADING', 'SF-ORDER-DETAILS', 'SF-CUSTOMER-DETAILS', 'SF-ADDITIONAL-CONTENT', 'SF-STORE-LINK' );
		$details = false;
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$tag = $tags->get_tag();
			if ( in_array( $tag, array( 'SCRIPT', 'FORM' ), true ) || ( str_starts_with( $tag, 'SF-' ) && ! in_array( $tag, $slots, true ) ) ) {
				return self::refuse( 'email-invalid', 'Email templates use static HTML and the six supported <sf-*> email slots. Remove <' . strtolower( $tag ) . '>.', $pointer );
			}
			foreach ( $tags->get_attribute_names_with_prefix( '' ) ?? array() as $attribute ) {
				if ( str_starts_with( strtolower( $attribute ), 'on' ) || preg_match( '/^\s*javascript:/i', (string) $tags->get_attribute( $attribute ) ) ) {
					return self::refuse( 'email-invalid', 'Remove event handlers and javascript: URLs from email templates.', $pointer );
				}
			}
			$details = $details || 'SF-ORDER-DETAILS' === $tag;
		}
		return $details ? true : self::refuse( 'email-invalid', 'An email template must contain <sf-order-details>.', $pointer );
	}
}
