<?php
/** M1 contract against real WordPress, Woo products, shipping, orders, grants and email. */

use SpacefastCommerce\Bundle;
use SpacefastCommerce\Mode;
use SpacefastCommerce\Plugin;
use SpacefastCommerce\PrivateFiles;

function commerce_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function commerce_result( mixed $result ): array {
	commerce_check( is_array( $result ) && true === ( $result['ok'] ?? false ), 'Apply failed: ' . ( is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : wp_json_encode( $result ) ) );
	return $result;
}
function commerce_bundle( array $store, array $files = array() ): string {
	$directory = sys_get_temp_dir() . '/commerce-bundle-' . wp_generate_uuid4();
	mkdir( $directory . '/files', 0700, true );
	file_put_contents( $directory . '/store.json', wp_json_encode( $store, JSON_UNESCAPED_SLASHES ) );
	foreach ( $files as $sha => $bytes ) {
		file_put_contents( $directory . '/files/' . ( is_string( $sha ) ? $sha : hash( 'sha256', $bytes ) ), $bytes );
	}
	return $directory;
}
function commerce_ref( string $path, string $bytes ): array {
	return array( 'path' => $path, 'sha256' => hash( 'sha256', $bytes ), 'size' => strlen( $bytes ) );
}
function commerce_permissions( int $order_id ): array {
	return array_map( static fn ( WC_Customer_Download $download ): array => $download->get_data(), WC_Data_Store::load( 'customer-download' )->get_downloads( array( 'order_id' => $order_id ) ) );
}

$plugin = Plugin::instance();
commerce_check( $plugin instanceof Plugin, 'Companion did not boot.' );
$phase = $args[0] ?? 'apply';
if ( 'after-live' === $phase ) {
	$context = json_decode( file_get_contents( '/tmp/commerce-m1-context.json' ), true );
	commerce_check( 'live' === $plugin->store->mode() && '' === Mode::banner_html(), 'Live checkout still has a test banner.' );
	commerce_check( ! isset( WC()->payment_gateways()->payment_gateways()['dummy'] ), 'A fresh live request still registers the dummy gateway.' );
	$registry = Automattic\WooCommerce\Blocks\Package::container()->get( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class );
	commerce_check( ! isset( $registry->get_all_active_registered()['dummy'] ), 'A fresh live Checkout Block still exposes dummy payment.' );
	foreach ( $context['testOrders'] as $id ) {
		$order = wc_get_order( $id );
		commerce_check( 'cancelled' === $order->get_status() && array() === commerce_permissions( $id ), 'Go-live left test order delivery available.' );
		$webhook = new WC_Webhook();
		$webhook->set_topic( 'order.updated' );
		commerce_check( false === apply_filters( 'woocommerce_webhook_should_deliver', true, $webhook, $id ), 'A deferred cleanup webhook escaped in a new process.' );
	}
	commerce_check( $context['stockBeforeOrders'] === wc_get_product( $context['physicalId'] )->get_stock_quantity(), 'Go-live did not restore reduced stock exactly once.' );
	commerce_check( $context['orderCount'] === SpacefastCommerce\Orders::count(), 'Go-live erased order history.' );
	WP_CLI::line( 'PASS: fresh-process live gateway, silent cleanup, grants, stock and retained history' );
	return;
}

// Both cross-language goldens go through the real PHP bundle reader.
foreach ( glob( '/tmp/commerce-store-json/*.json' ) as $golden ) {
	$directory = sys_get_temp_dir() . '/commerce-golden-' . wp_generate_uuid4();
	mkdir( $directory );
	copy( $golden, $directory . '/store.json' );
	$bundle = Bundle::open( $directory );
	commerce_check( $bundle instanceof Bundle && hash_file( 'sha256', $golden ) === $bundle->hash, 'Golden contract rejected: ' . basename( $golden ) );
}

$key = 'm1-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$image = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=' );
$asset = "%PDF-1.4\nFirst edition\n";
$replacement = "%PDF-1.4\nSecond edition for existing buyers\n";
$html = '<!doctype html><html><head><title>Store email</title></head><body><h1><sf-email-heading></sf-email-heading></h1><p><sf-store-name></sf-store-name></p><img src="/brand.png" alt="Brand"><sf-order-details></sf-order-details><sf-customer-details></sf-customer-details><sf-additional-content></sf-additional-content><sf-store-link></sf-store-link></body></html>';
$email = array( 'html' => $html, 'sha256' => hash( 'sha256', $html ) );
$digital = array(
	'key' => $key . '-book', 'kind' => 'digital', 'name' => 'Field guide', 'summary' => 'A useful book', 'description' => 'Source description',
	'price' => array( 'amountMinor' => 1250, 'currency' => 'usd' ), 'image' => commerce_ref( 'images/cover.png', $image ),
	'page' => '/books/field-guide/', 'asset' => commerce_ref( 'private/guide.pdf', $asset ), 'active' => true, 'stock' => null,
);
$physical = array_merge( $digital, array( 'key' => $key . '-print', 'kind' => 'physical', 'name' => 'Printed guide', 'asset' => null, 'stock' => array( 'track' => true, 'initial' => 50 ) ) );
$store = array(
	'schemaVersion' => 1,
	'merchant' => array( 'name' => 'M1 contract shop', 'brand' => array( 'logo' => commerce_ref( 'images/cover.png', $image ), 'accent' => '#345678' ), 'country' => 'US', 'currency' => 'usd', 'downloads' => array( 'limit' => 5, 'expiryDays' => 30 ) ),
	'shipping' => array( 'countries' => array( 'US' ), 'rate' => array( 'amountMinor' => 1500 ), 'policy' => 'Ships to the US.' ),
	'products' => array( $digital, $physical ),
	'emails' => array( 'customer_processing_order' => $email, 'customer_completed_order' => $email, 'new_order' => $email ),
);
$options = array( 'origin' => 'deploy', 'baseline' => array(), 'canonical_origin' => 'https://store.example.test' );
$directory = commerce_bundle( $store, array( $image, $asset ) );
$first = commerce_result( $plugin->catalog->apply( $directory, $options ) );
$book_id = wc_get_product_id_by_sku( $digital['key'] );
$print_id = wc_get_product_id_by_sku( $physical['key'] );
$cover_id = wc_get_product( $book_id )->get_image_id();
commerce_check( $book_id > 0 && $print_id > 0 && $cover_id > 0, 'Apply did not create native products/media.' );
$options['baseline'] = array( $digital['key'], $physical['key'] );
$again = commerce_result( $plugin->catalog->apply( $directory, $options ) );
commerce_check( $first['counter'] + 1 === $again['counter'] && array( 'unchanged', 'unchanged' ) === array_column( $again['changes'], 'action' ), 'Retry did not preserve identities and detect no product changes.' );
commerce_check( $cover_id === wc_get_product( $print_id )->get_image_id() && $book_id === wc_get_product_id_by_sku( $digital['key'] ), 'Retry duplicated native media or product identity.' );

// Refusals must not change the apply counter, canonical origin, options or catalog.
$snapshot = static fn (): array => array( $plugin->store->counter(), $plugin->store->store_hash(), $plugin->store->canonical_origin(), get_option( 'blogname' ), $plugin->catalog->current() );
$refuse = static function ( array $bad, string $code, array $files = array(), ?array $apply_options = null ) use ( $plugin, $options, $snapshot ): void {
	$before = $snapshot();
	$result = $plugin->catalog->apply( commerce_bundle( $bad, $files ), $apply_options ?? $options );
	commerce_check( is_wp_error( $result ) && $code === $result->get_error_code(), 'Expected refusal ' . $code . ', got ' . ( is_wp_error( $result ) ? $result->get_error_code() : wp_json_encode( $result ) ) );
	commerce_check( $before === $snapshot(), 'Refusal ' . $code . ' changed store state.' );
};
$bad = $store;
$bad['products'] = array( $digital );
$refuse( $bad, 'product-removed' );
$bad = $store;
$bad['merchant']['currency'] = 'eur';
foreach ( $bad['products'] as &$row ) { $row['price']['currency'] = 'eur'; }
unset( $row );
$refuse( $bad, 'currency-mismatch' );
$bad = $store;
$bad['products'][0]['asset'] = commerce_ref( 'private/missing.pdf', 'missing bytes' );
$refuse( $bad, 'file-missing' );
$refuse( $bad, 'file-integrity', array( $bad['products'][0]['asset']['sha256'] => 'corrupted bytes' ) );
$bad = $store;
$bad['products'][0]['image'] = commerce_ref( 'images/invalid.png', 'not an image' );
$refuse( $bad, 'image-invalid', array( 'not an image' ) );
$refuse( $store, 'canonical-origin-invalid', array(), array_merge( $options, array( 'canonical_origin' => 'http://invalid.example/path' ) ) );
$bad = $store;
$bad['merchant']['name'] = 'Must not be written';
$clash = new WC_Product_Simple();
$clash->set_name( 'Native SKU neighbor' );
$clash->set_sku( $key . '-neighbor' );
$clash->save();
$bad['products'][0]['key'] = $clash->get_sku();
$refuse( $bad, 'sku-clash', array(), array_merge( $options, array( 'baseline' => array() ) ) );
$clash->delete( true );
foreach ( array( '<template><script>alert(1)</script></template>', '<form></form>', '<a onclick="bad()">Bad</a>', '<a href="javascript:bad()">Bad</a>', '<sf-cart></sf-cart>' ) as $unsafe ) {
	$bad = $store;
	$bad_html = str_replace( '</body>', $unsafe . '</body>', $html );
	$bad['emails']['new_order'] = array( 'html' => $bad_html, 'sha256' => hash( 'sha256', $bad_html ) );
	$refuse( $bad, 'email-invalid' );
}

// Actual native REST and wp-admin style writes: source fields are protected, stock/categories stay editable.
$administrator = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $administrator->ID );
$request = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $book_id );
$request->set_param( 'regular_price', '99.00' );
commerce_check( 403 === rest_do_request( $request )->get_status(), 'Native REST edited a source field.' );
$request = new WP_REST_Request( 'PUT', '/wc/v3/settings/general/woocommerce_calc_taxes' );
// WordPress body parameters can override the URL's setting selector.
$request->set_body_params( array( 'id' => 'woocommerce_currency', 'value' => 'EUR' ) );
$managed_setting = rest_do_request( $request );
commerce_check( 403 === $managed_setting->get_status() && 'source_managed_setting' === ( $managed_setting->get_data()['code'] ?? null ) && 'USD' === get_woocommerce_currency(), 'Native REST reported success for a rejected source-managed store setting; status=' . $managed_setting->get_status() . ', reported=' . ( $managed_setting->get_data()['value'] ?? '' ) );
$tax_before = get_option( 'woocommerce_calc_taxes' );
$request = new WP_REST_Request( 'POST', '/wc/v3/settings/general/batch' );
$request->set_param( 'update', array( array( 'id' => 'woocommerce_currency', 'value' => 'EUR' ), array( 'id' => 'woocommerce_calc_taxes', 'value' => 'yes' === $tax_before ? 'no' : 'yes' ) ) );
$managed_batch = rest_do_request( $request );
commerce_check( 403 === $managed_batch->get_status() && 'source_managed_setting' === ( $managed_batch->get_data()['code'] ?? null ) && $tax_before === get_option( 'woocommerce_calc_taxes' ), 'Native settings batch bypassed source refusal or partially saved a refused request; status=' . $managed_batch->get_status() );
$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
$rest_brand_before = get_option( 'woo_storefront_settings' );
$request->set_param( 'woo_storefront_settings', array( 'branding' => array( 'logo' => 'https://other.example/logo.png' ) ) );
$managed_brand = rest_do_request( $request );
commerce_check( 403 === $managed_brand->get_status() && 'source_managed_setting' === ( $managed_brand->get_data()['code'] ?? null ) && $rest_brand_before === get_option( 'woo_storefront_settings' ), 'WordPress REST did not explain source-managed branding refusal; status=' . $managed_brand->get_status() . ', code=' . ( $managed_brand->get_data()['code'] ?? '' ) );
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'woocommerce_page_wc-settings' );
$_GET['page'] = 'wc-settings';
$_GET['tab'] = 'general';
ob_start();
$plugin->managed->notice();
$managed_notice = ob_get_clean();
commerce_check( str_contains( $managed_notice, 'Managed by your store source.' ) && str_contains( $managed_notice, 'currency' ), 'Native store settings do not explain source ownership.' );
$request = new WP_REST_Request( 'DELETE', '/wc/v3/shipping/zones/' . $plugin->shipping->zone_id() );
commerce_check( 403 === rest_do_request( $request )->get_status(), 'Native REST deleted the source shipping zone.' );
$brand = get_option( 'woo_storefront_settings' );
update_option( 'blogname', 'Browser title' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'DE:BE' );
update_option( 'woo_storefront_settings', array( 'branding' => array( 'logo' => 'https://other.example/logo.png', 'colors' => array( 'primary' => '#ff0000' ) ) ) );
commerce_check( 'M1 contract shop' === get_option( 'blogname' ) && 'USD' === get_woocommerce_currency() && 'US' === explode( ':', get_option( 'woocommerce_default_country' ) )[0] && $brand['branding']['logo'] === get_option( 'woo_storefront_settings' )['branding']['logo'] && '#345678' === get_option( 'woo_storefront_settings' )['branding']['colors']['primary'], 'A native settings save edited declared store fields.' );
ob_start();
WC_Admin_Settings::show_messages();
$managed_errors = ob_get_clean();
commerce_check( str_contains( $managed_errors, 'managed by your store source' ), 'Native settings save silently ignored source-managed changes.' );
set_current_screen( null );
unset( $_GET['page'], $_GET['tab'] );
$product = wc_get_product( $print_id );
$product->set_name( 'Browser edit' );
$product->set_regular_price( '99.00' );
$product->set_stock_quantity( 47 );
$category = wp_insert_term( $key, 'product_cat' );
$product->set_category_ids( array( $category['term_id'] ) );
$product->save();
commerce_check( 'Printed guide' === wc_get_product( $print_id )->get_name() && '12.50' === wc_get_product( $print_id )->get_regular_price() && 47 === wc_get_product( $print_id )->get_stock_quantity(), 'Managed save blocked stock or changed source fields.' );
commerce_check( false === wp_delete_post( $book_id, true ), 'Managed product history could be deleted.' );
wp_set_object_terms( $print_id, 'variable', 'product_type' );
commerce_check( array( 'simple' ) === wp_get_object_terms( $print_id, 'product_type', array( 'fields' => 'slugs' ) ), 'Native taxonomy edit changed the declared product type.' );
$request = new WP_REST_Request( 'PUT', '/wc/v3/settings/general/woocommerce_default_country' );
$request->set_param( 'value', 'US:CA' );
commerce_check( 200 === rest_do_request( $request )->get_status() && 'US:CA' === get_option( 'woocommerce_default_country' ), 'Managed country blocked the merchant-owned state.' );
wp_set_current_user( 0 );
update_option( 'woocommerce_calc_taxes', 'yes' );
$store['products'][1]['stock']['initial'] = 80;
$store['products'][0]['name'] = 'Revised field guide';
$updated = commerce_result( $plugin->catalog->apply( commerce_bundle( $store ), $options ) );
commerce_check( 'Revised field guide' === wc_get_product( $book_id )->get_name(), 'Nested page setup disabled the catalog write bypass.' );
commerce_check( 'stock-initial-ignored' === $updated['warnings'][0]['code'] && 47 === wc_get_product( $print_id )->get_stock_quantity(), 'Reapply reset operational stock.' );
commerce_check( array( (int) $category['term_id'] ) === wc_get_product( $print_id )->get_category_ids() && 'US:CA' === get_option( 'woocommerce_default_country' ) && 'yes' === get_option( 'woocommerce_calc_taxes' ), 'Apply overwrote merchant-owned state.' );
$store['products'][1]['stock']['track'] = false;
commerce_result( $plugin->catalog->apply( commerce_bundle( $store ), $options ) );
$store['products'][1]['stock']['track'] = true;
commerce_result( $plugin->catalog->apply( commerce_bundle( $store ), $options ) );
// Native Woo clears quantity when tracking is disabled. Re-enabling must not seed it from source again.
commerce_check( null === wc_get_product( $print_id )->get_stock_quantity(), 'Toggling stock tracking re-seeded an existing product.' );
wc_update_product_stock( $print_id, 47, 'set' );

// SQL drift is observed and repaired by the same native projection the planner uses.
global $wpdb;
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => '99.00' ), array( 'post_id' => $book_id, 'meta_key' => '_regular_price' ) );
clean_post_cache( $book_id );
commerce_result( $plugin->catalog->apply( commerce_bundle( $store ), $options ) );
commerce_check( '12.50' === wc_get_product( $book_id )->get_regular_price(), 'Apply did not repair observed native drift.' );

wc_load_cart();
WC()->customer->set_shipping_country( 'US' );
WC()->customer->set_shipping_state( 'CA' );
WC()->customer->set_shipping_postcode( '94016' );
WC()->cart->empty_cart();
commerce_check( false !== WC()->cart->add_to_cart( $print_id, 3 ), 'Physical quantity greater than one was refused.' );
WC()->cart->calculate_totals();
commerce_check( '15' === (string) (float) WC()->cart->get_shipping_total(), 'Declared flat shipping was not charged once per order: ' . WC()->cart->get_shipping_total() );
$zone = new WC_Shipping_Zone( $plugin->shipping->zone_id() );
commerce_check( array( 'US' ) === array_map( static fn ( object $location ): string => $location->code, $zone->get_zone_locations() ), 'Managed shipping zone countries differ from source.' );
WC()->customer->set_shipping_country( 'DE' );
WC()->customer->set_shipping_state( '' );
WC()->cart->calculate_totals();
commerce_check( array() === WC()->shipping()->get_packages()[0]['rates'], 'An undeclared destination received a managed shipping rate.' );
WC()->cart->empty_cart();
commerce_check( wc_get_page_id( 'cart' ) < 1 && 'checkout' === get_post_field( 'post_name', wc_get_page_id( 'checkout' ) ) && 'my-account' === get_post_field( 'post_name', wc_get_page_id( 'myaccount' ) ), 'Fixed pages and cart ownership failed.' );

// A stale or direct database assignment is not authority to delete arbitrary merchant pages.
$ordinary_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Merchant information', 'post_content' => 'Keep this merchant content.' ), true );
commerce_check( is_int( $ordinary_page ), 'Could not create merchant page.' );
$checkout_page = wc_get_page_id( 'checkout' );
foreach ( array( $ordinary_page, $checkout_page ) as $cart_assignment ) {
	$plugin->managed->apply( static fn () => update_option( 'woocommerce_cart_page_id', $cart_assignment ) );
	commerce_result( $plugin->provisioning->setup() );
	commerce_check( get_post( $cart_assignment ) instanceof WP_Post && 0 === (int) get_option( 'woocommerce_cart_page_id' ), 'Setup deleted a page merely because the cart option referenced it.' );
}
update_option( 'woocommerce_cart_page_id', $ordinary_page );
commerce_check( 0 === (int) get_option( 'woocommerce_cart_page_id' ), 'Merchant settings reassigned the cart page.' );
wp_delete_post( $ordinary_page, true );
// Simulate a plugin/SQL write that bypasses the managed-page guard. Woo's
// canonical checkout factory is protected in 11.2; repair must still work.
$checkout_id = wc_get_page_id( 'checkout' );
$wpdb->update( $wpdb->posts, array( 'post_content' => 'Broken checkout content' ), array( 'ID' => $checkout_id ) );
clean_post_cache( $checkout_id );
commerce_result( $plugin->catalog->apply( commerce_bundle( $store ), $options ) );
commerce_check( has_block( 'woocommerce/checkout-contact-information-block', get_post_field( 'post_content', $checkout_id ) ), 'Checkout repair did not restore the native form blocks.' );
commerce_check( 'no' === get_option( 'woocommerce_enable_myaccount_registration' ) && 'yes' === get_option( 'woocommerce_enable_guest_checkout' ), 'Native checkout created a customer signup path.' );
$signup_email = 'blocked-' . wp_generate_uuid4() . '@example.test';
$signup = new WP_REST_Request( 'POST', '/woo-storefront/v1/auth/register' );
$signup->set_param( 'email', $signup_email );
$signup->set_param( 'password', 'disposable-registration-probe' );
$signup_response = rest_do_request( $signup );
$created_customer = get_user_by( 'email', $signup_email );
if ( $created_customer ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $created_customer->ID );
}
commerce_check( 403 === $signup_response->get_status() && 'woo_storefront_registration_disabled' === ( $signup_response->get_data()['code'] ?? null ) && false === $created_customer, 'Disabled registration created a customer or returned the wrong refusal: ' . $signup_response->get_status() );
$enable_registration = static fn() => 'yes';
add_filter( 'pre_option_woocommerce_enable_myaccount_registration', $enable_registration, PHP_INT_MAX );
try {
	$signup_response = rest_do_request( $signup );
	$created_customer = get_user_by( 'email', $signup_email );
	commerce_check( 201 === $signup_response->get_status() && $created_customer instanceof WP_User, 'Explicitly enabled registration did not create its customer.' );
} finally {
	remove_filter( 'pre_option_woocommerce_enable_myaccount_registration', $enable_registration, PHP_INT_MAX );
	if ( $created_customer instanceof WP_User ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created_customer->ID );
	}
	wp_set_current_user( 0 );
}
$account_document = new DOMDocument();
commerce_check( $account_document->loadHTML( wc_get_template_html( 'myaccount/form-login.php' ), LIBXML_NOERROR | LIBXML_NOWARNING ), 'Native account view did not render.' );
commerce_check( 1 === ( new DOMXPath( $account_document ) )->query( '//input[@type="password"]' )->length, 'A host that permits passwords lost its native login.' );
add_filter( 'spacefast_commerce_refuse_password_login', '__return_true' );
try {
	commerce_check( $account_document->loadHTML( wc_get_template_html( 'myaccount/form-login.php' ), LIBXML_NOERROR | LIBXML_NOWARNING ), 'Guest account view did not render.' );
	$account_nodes = new DOMXPath( $account_document );
	commerce_check( 0 === $account_nodes->query( '//input[@type="password"]' )->length && 'No account needed' === $account_nodes->query( '//h1' )->item( 0 )?->textContent && $plugin->store->url( '/' ) === $account_nodes->query( '//a' )->item( 0 )?->getAttribute( 'href' ), 'Password-disabled shoppers saw a dead login form or lost their store link.' );
} finally {
	remove_filter( 'spacefast_commerce_refuse_password_login', '__return_true' );
}
commerce_check( 'https://store.example.test/books/field-guide/' === get_permalink( $book_id ), 'Native product link did not point to the storefront page.' );

$make_order = static function ( bool $interrupted ) use ( $book_id, $print_id, $key ): WC_Order {
	$order = wc_create_order();
	$order->set_payment_method( 'dummy' );
	$order->set_billing_email( $key . ( $interrupted ? '-retry' : '-buyer' ) . '@example.test' );
	$order->add_product( wc_get_product( $book_id ), 1 );
	$order->add_product( wc_get_product( $print_id ), 2 );
	$order->calculate_totals();
	$order->save();
	commerce_check( array() === $order->get_downloadable_items(), 'Unpaid order received downloads.' );
	$order->payment_complete();
	commerce_check( $order->is_paid() && 1 === count( $order->get_downloadable_items() ), 'Native paid order did not receive its download.' );
	if ( $interrupted ) {
		// Reproduce a process dying after status persistence and before stock/grant cleanup.
		remove_action( 'woocommerce_order_status_cancelled', 'wc_maybe_increase_stock_levels' );
		$order->update_status( 'cancelled' );
		add_action( 'woocommerce_order_status_cancelled', 'wc_maybe_increase_stock_levels' );
	}
	return $order;
};
$order = $make_order( false );
$interrupted = $make_order( true );
commerce_check( 43 === wc_get_product( $print_id )->get_stock_quantity() && array() !== commerce_permissions( $interrupted->get_id() ), 'Interrupted cleanup fixture did not retain stock and grants.' );
$download = current( $order->get_downloadable_items() );
$permissions = WC_Data_Store::load( 'customer-download' )->get_downloads( array( 'order_id' => $order->get_id() ) );
$permissions[0]->set_downloads_remaining( 3 );
$permissions[0]->save();
$before_permissions = wp_json_encode( commerce_permissions( $order->get_id() ) );
$store['products'][0]['asset'] = commerce_ref( 'private/replacement.pdf', $replacement );
commerce_result( $plugin->catalog->apply( commerce_bundle( $store, array( $replacement ) ), $options ) );
$after = current( wc_get_order( $order->get_id() )->get_downloadable_items() );
commerce_check( $before_permissions === wp_json_encode( commerce_permissions( $order->get_id() ) ) && $download['download_id'] === $after['download_id'] && PrivateFiles::download_id( $digital['key'] ) === $after['download_id'], 'Asset replacement changed previous buyer grants.' );
commerce_check( $replacement === file_get_contents( $after['file']['file'] ), 'Previous buyer did not receive the current source asset.' );
$physical_conversion = $store;
$physical_conversion['products'][0]['kind'] = 'physical';
$physical_conversion['products'][0]['asset'] = null;
$refuse( $physical_conversion, 'download-retained' );
commerce_check( $before_permissions === wp_json_encode( commerce_permissions( $order->get_id() ) ) && 1 === count( wc_get_order( $order->get_id() )->get_downloadable_items() ), 'A digital-to-physical refusal revoked an earlier buyer download.' );
commerce_check( 'Revised field guide' === current( $order->get_items() )->get_name(), 'Apply rewrote a purchased order line.' );

// Query failure is not an empty store, for either native order datastore.
global $wpdb;
$order_count_before_fault = SpacefastCommerce\Orders::count();
$counter_before_fault = $plugin->store->counter();
$hash_before_fault = $plugin->store->store_hash();
$mode_before_fault = $plugin->store->mode();
$origin_before_fault = $plugin->store->canonical_origin();
$order_table = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
$held_table = $order_table . '_retention_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$suppress_errors_before_fault = $wpdb->suppress_errors( true );
wp_cache_flush();
commerce_check( false !== $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $order_table, $held_table ) ), 'Could not isolate the disposable order table.' );
try {
	$unavailable_orders = SpacefastCommerce\Orders::has_orders();
	commerce_check( is_wp_error( $unavailable_orders ) && 'orders-unavailable' === $unavailable_orders->get_error_code(), 'Unavailable order history was reported as empty.' );
	$unavailable_status = $plugin->provisioning->status();
	commerce_check( is_wp_error( $unavailable_status ) && 'orders-unavailable' === $unavailable_status->get_error_code(), 'Status advertised a zero-order receipt during a database failure.' );
	$unavailable_mode = $plugin->mode->set( 'live', 'https://must-not-commit.example' );
	commerce_check( is_wp_error( $unavailable_mode ) && 'orders-unavailable' === $unavailable_mode->get_error_code(), 'Go-live ignored unavailable test-order cleanup.' );
	commerce_check( $mode_before_fault === $plugin->store->mode() && $origin_before_fault === $plugin->store->canonical_origin(), 'Go-live changed mode or canonical origin without readable order history.' );
	$unavailable_apply = $plugin->catalog->apply( $directory, $options );
	commerce_check( is_wp_error( $unavailable_apply ) && 'orders-unavailable' === $unavailable_apply->get_error_code(), 'Catalog apply ignored unavailable order history.' );
	commerce_check( $counter_before_fault === $plugin->store->counter() && $hash_before_fault === $plugin->store->store_hash(), 'An unavailable history refusal changed the native catalog receipt.' );
} finally {
	$restored_table = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $held_table, $order_table ) );
	$wpdb->suppress_errors( $suppress_errors_before_fault );
	wp_cache_flush();
	commerce_check( false !== $restored_table, 'Restore the disposable fixture order table.' );
}
commerce_check( $order_count_before_fault === SpacefastCommerce\Orders::count(), 'Order history did not recover after the database was restored.' );

// Download history has its own datastore and must also fail closed before an apply writes.
$downloads_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
$held_downloads = $downloads_table . '_held';
$suppress_errors_before_fault = $wpdb->suppress_errors( true );
commerce_check( false !== $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $downloads_table, $held_downloads ) ), 'Could not isolate disposable download permissions.' );
try {
	$refuse( $physical_conversion, 'downloads-unavailable' );
} finally {
	$restored_downloads = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $held_downloads, $downloads_table ) );
	$wpdb->suppress_errors( $suppress_errors_before_fault );
	commerce_check( false !== $restored_downloads, 'Restore the disposable download permissions.' );
}
commerce_check( $before_permissions === wp_json_encode( commerce_permissions( $order->get_id() ) ), 'Download history did not survive a datastore read failure.' );

// A move away from Woo may stop sales, but it cannot erase purchased entitlements.
$native_before_disable = array( 'hash' => $plugin->store->store_hash(), 'counter' => $plugin->store->counter(), 'mode' => $plugin->store->mode(), 'orders' => SpacefastCommerce\Orders::count(), 'stock' => wc_get_product( $print_id )->get_stock_quantity() );
$unmanaged = new WC_Product_Simple();
$unmanaged->set_name( 'Native product outside the source' );
$unmanaged->set_status( 'publish' );
$unmanaged->save();
$interrupt_disable = static function ( WC_Product $product ) use ( $print_id ): void {
	if ( $product->get_id() === $print_id ) {
		throw new RuntimeException( 'Deliberately interrupted native disable.' );
	}
};
add_action( 'woocommerce_before_product_object_save', $interrupt_disable, 99 );
$interrupted_disable = $plugin->catalog->disable();
remove_action( 'woocommerce_before_product_object_save', $interrupt_disable, 99 );
commerce_check( is_wp_error( $interrupted_disable ) && 'apply-failed' === $interrupted_disable->get_error_code(), 'An interrupted disable was not retryable.' );
commerce_check( $native_before_disable['counter'] + 1 === $plugin->store->counter() && $native_before_disable['hash'] === $plugin->store->store_hash(), 'Partial disable did not invalidate the counter while preserving the last completed hash.' );
$disabled = commerce_result( $plugin->catalog->disable() );
commerce_check( null === $disabled['storeHash'] && $native_before_disable['counter'] + 2 === $disabled['counter'], 'Disable receipt did not settle the null catalog.' );
commerce_check( 'draft' === wc_get_product( $book_id )->get_status() && 'draft' === wc_get_product( $print_id )->get_status() && 'publish' === wc_get_product( $unmanaged->get_id() )->get_status(), 'Disable changed an unmanaged product or left a managed product for sale.' );
commerce_check( $native_before_disable['mode'] === $plugin->store->mode() && $native_before_disable['orders'] === SpacefastCommerce\Orders::count() && $native_before_disable['stock'] === wc_get_product( $print_id )->get_stock_quantity(), 'Disable changed mode, stock or native order history.' );
commerce_check( $before_permissions === wp_json_encode( commerce_permissions( $order->get_id() ) ) && 1 === count( wc_get_order( $order->get_id() )->get_downloadable_items() ), 'Disable revoked a previous buyer grant.' );
commerce_result( $plugin->catalog->apply( commerce_bundle( $store, array( $replacement ) ), $options ) );
commerce_check( $book_id === wc_get_product_id_by_sku( $digital['key'] ) && 'publish' === wc_get_product( $book_id )->get_status(), 'Re-enable did not restore the managed product in place.' );
$unmanaged->delete( true );

unset( $_SERVER['HTTP_HOST'] );
$plugin->emails->store( array() );
try {
	$native_email = commerce_result( $plugin->emails->render_order( $order->get_id(), 'customer_processing_order' ) );
	commerce_check( str_contains( $native_email['html'], current( $order->get_items() )->get_name() ) && ! str_contains( $native_email['html'], 'https://store.example.test/brand.png' ), 'Removing an email binding did not restore native content under a warm template cache.' );
} finally {
	$plugin->emails->store( $store['emails'] );
}
$mailer = WC()->mailer();
$previous_emails = $mailer->emails;
$force_block_editor = static fn() => 'yes';
add_filter( 'pre_option_woocommerce_feature_block_email_editor_enabled', $force_block_editor );
try {
	$processing = new WC_Email_Customer_Processing_Order();
	$new_account = new WC_Email_Customer_New_Account();
	commerce_check( true === $processing->block_email_editor_enabled && true === $new_account->block_email_editor_enabled, 'The in-memory feature probe did not enable core block emails.' );
	$filtered_emails = apply_filters( 'woocommerce_email_classes', array( 'WC_Email_Customer_Processing_Order' => $processing, 'WC_Email_Customer_New_Account' => $new_account ) );
	$processing = $filtered_emails['WC_Email_Customer_Processing_Order'];
	$new_account = $filtered_emails['WC_Email_Customer_New_Account'];
	commerce_check( false === $processing->block_email_editor_enabled && true === $new_account->block_email_editor_enabled, 'Source binding did not disable only its own block email.' );
	$eligible = apply_filters( 'woocommerce_transactional_emails_for_block_editor', array( $processing->id, $new_account->id ) );
	commerce_check( array( $new_account->id ) === $eligible, 'The editor exposed a source-bound email or hid an unbound email.' );
	$mailer->emails['WC_Email_Customer_Processing_Order'] = $processing;
	foreach ( array_keys( $store['emails'] ) as $email_id ) {
		$rendered = commerce_result( $plugin->emails->render_order( $order->get_id(), $email_id ) );
		commerce_check( str_contains( $rendered['html'], 'Test order' ) && str_contains( $rendered['html'], 'https://store.example.test/brand.png' ) && ! str_contains( $rendered['html'], '<sf-' ), 'Native trigger omitted template, canonical URL, slot contents or test banner.' );
		commerce_check( ! str_contains( $rendered['html'], get_option( 'home' ) ) && ( 'new_order' === $email_id || str_contains( $rendered['html'], 'download_file=' ) ), 'Native email download used a request host instead of canonical origin.' );
	}
} finally {
	$mailer->emails = $previous_emails;
	remove_filter( 'pre_option_woocommerce_feature_block_email_editor_enabled', $force_block_editor );
}
wp_set_current_user( $administrator->ID );
$expires = time() + 3600;
$token = WP_Session_Tokens::get_instance( $administrator->ID )->create( $expires );
$auth_cookie = wp_generate_auth_cookie( $administrator->ID, $expires, 'auth', $token );
$logged_cookie = wp_generate_auth_cookie( $administrator->ID, $expires, 'logged_in', $token );
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_cookie;
$context = array(
	'bookId' => $book_id, 'physicalId' => $print_id, 'bookKey' => $digital['key'], 'physicalKey' => $physical['key'],
	'bundle' => $directory, 'testOrders' => array( $order->get_id(), $interrupted->get_id() ), 'stockBeforeOrders' => 47,
	'orderCount' => SpacefastCommerce\Orders::count(), 'buyerEmail' => $order->get_billing_email(),
	'downloadUrl' => $after['download_url'], 'downloadSha256' => hash( 'sha256', $replacement ),
	'orderReceivedUrl' => $order->get_checkout_order_received_url(),
	'origin' => get_option( 'home' ),
	'shippingZoneId' => $plugin->shipping->zone_id(),
	'adminCookie' => AUTH_COOKIE . '=' . $auth_cookie . '; ' . LOGGED_IN_COOKIE . '=' . $logged_cookie,
);
$context['shippingNonce'] = wp_create_nonce( 'wc_shipping_zones_nonce' );
file_put_contents( '/tmp/commerce-m1-context.json', wp_json_encode( $context ) );
WP_CLI::line( 'PASS: bundle goldens, refusal atomicity, native catalog protection/repair, stock, per-order shipping, retained buyer grants and native email rendering' );
