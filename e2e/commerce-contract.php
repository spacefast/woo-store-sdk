<?php
/** Run with wp eval-file against a disposable, real Woo installation. */

function commerce_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
$store = new \SpacefastCommerce\Store();
$credential = bin2hex( random_bytes( 32 ) );
$binding = array(
	'space_id' => 'spc_contract', 'store_id' => 'contract', 'environment' => 'test',
	'origin' => 'https://contract.example.test', 'currency' => 'USD', 'country' => 'PL', 'credential' => $credential,
);
commerce_check( true === $store->bind( $binding ), 'Store binding failed.' );
commerce_check( true === $store->bind( $binding ), 'Binding retry failed.' );
commerce_check( is_wp_error( $store->bind( array_merge( $binding, array( 'environment' => 'live' ) ) ) ), 'Store crossed environments.' );
commerce_check( 'force' === get_option( 'woocommerce_file_download_method' ), 'Woo download transport is not protected.' );
commerce_check( false === apply_filters( 'woo_storefront_checkout_redirect_after_order', true ), 'Managed checkout skips Woo receipt.' );
commerce_check( $binding['origin'] === apply_filters( 'woo_storefront_checkout_return_url', 'https://untrusted.example' ), 'Return target is caller-controlled.' );

$headers = array(
	'Authorization' => 'Bearer ' . $credential,
	'X-Spacefast-Space-Id' => $binding['space_id'],
	'X-Spacefast-Store-Id' => $binding['store_id'],
	'X-Spacefast-Environment' => 'test',
);
function commerce_request( string $method, string $path, array $headers, ?array $body = null ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/spacefast-commerce/v1' . $path );
	$request->set_headers( $headers );
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
}
$key = 'contract-' . strtolower( wp_generate_password( 8, false ) );
$path = '/products/' . $key;
$product_data = array( 'generation' => time(), 'name' => 'Source product', 'description' => '<p>Source description</p>', 'price' => '12.50', 'enabled' => true, 'kind' => 'physical' );
commerce_check( 401 === commerce_request( 'PUT', $path, array(), $product_data )->get_status(), 'Unauthenticated catalog write succeeded.' );
$wrong_headers = array_merge( $headers, array( 'X-Spacefast-Environment' => 'live' ) );
commerce_check( 403 === commerce_request( 'PUT', $path, $wrong_headers, $product_data )->get_status(), 'Cross-environment catalog write succeeded.' );
$response = commerce_request( 'PUT', $path, $headers, $product_data );
commerce_check( 200 === $response->get_status(), 'Catalog create failed: ' . wp_json_encode( $response->get_data() ) );
$id = $response->get_data()['data']['id'];
$retry = commerce_request( 'PUT', $path, $headers, $product_data );
commerce_check( $id === $retry->get_data()['data']['id'], 'Retry duplicated a product.' );
$read = commerce_request( 'GET', '/products', $headers )->get_data()['data'];
commerce_check( in_array( $id, array_column( $read, 'id' ), true ), 'Managed catalog cannot read its own products.' );
$product = wc_get_product( $id );
commerce_check( $product->is_sold_individually() && ! $product->is_virtual(), 'Simple physical product policy failed.' );

foreach ( array(
	fn () => ( function () use ( $id ): void { $product = wc_get_product( $id ); $product->set_regular_price( '99' ); $product->save(); } )(),
	fn () => update_post_meta( $id, '_price', '99' ),
	fn () => wp_update_post( array( 'ID' => $id, 'post_title' => 'Browser edit' ) ),
) as $write ) {
	$denied = false;
	try { $write(); } catch ( WC_Data_Exception $error ) { $denied = 'source_managed_product' === $error->getErrorCode(); }
	commerce_check( $denied, 'Managed field edit was accepted.' );
}
$administrator = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $administrator->ID );
$native_edit = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $id );
$native_edit->set_param( 'regular_price', '99.00' );
commerce_check( 403 === rest_do_request( $native_edit )->get_status(), 'Native Woo REST allowed a managed-product edit.' );
wp_set_current_user( 0 );
commerce_check( false === wp_delete_post( $id, true ), 'Managed product history was deleted.' );
// Native operational writes must continue to work.
$product = wc_get_product( $id );
$product->set_total_sales( 3 );
$product->save();
commerce_check( 3 === wc_get_product( $id )->get_total_sales(), 'Source guard blocked Woo sales counters.' );
$order = wc_create_order();
$order->add_product( wc_get_product( $id ), 1 );
$order->calculate_totals();
$order->save();
$line = current( $order->get_items() );
$product_data['generation']++;
$product_data['name'] = 'New source name';
$product_data['price'] = '15.00';
commerce_check( 200 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Source update failed.' );
commerce_check( '15.00' === wc_get_product( $id )->get_regular_price(), 'Source price was not applied.' );
commerce_check( 'Source product' === $line->get_name() && '12.5' === (string) $line->get_total(), 'Product deploy changed purchased order lines.' );
$stale = array_merge( $product_data, array( 'generation' => $product_data['generation'] - 1 ) );
commerce_check( 409 === commerce_request( 'PUT', $path, $headers, $stale )->get_status(), 'Stale deployment overwrote catalog.' );
$product_data['enabled'] = false;
commerce_check( 200 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Archive failed.' );
commerce_check( 'draft' === wc_get_product( $id )->get_status(), 'Removed product remains available.' );
commerce_check( null !== wc_get_order( $order->get_id() ), 'Archive erased native order history.' );
$rotated = array_merge( $binding, array( 'credential' => bin2hex( random_bytes( 32 ) ) ) );
commerce_check( true === $store->bind( $rotated ), 'Credential rotation failed.' );
commerce_check( 401 === commerce_request( 'GET', '/products', $headers )->get_status(), 'Revoked credential still works.' );
echo "PASS: bound store authentication, catalog retry/fencing, managed writes, native counters, order history, archive, receipt policy, credential rotation\n";
