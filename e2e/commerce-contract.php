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
$native_storage_mode = get_option( 'woocommerce_custom_orders_table_enabled' );
$binding_file = tempnam( sys_get_temp_dir(), 'commerce-binding-' );
file_put_contents( $binding_file, wp_json_encode( $binding ) );
try {
	WP_CLI::runcommand( 'spacefast-commerce prepare ' . escapeshellarg( $binding_file ) );
	WP_CLI::runcommand( 'spacefast-commerce prepare ' . escapeshellarg( $binding_file ) );
} finally {
	unlink( $binding_file );
}
commerce_check( $native_storage_mode === get_option( 'woocommerce_custom_orders_table_enabled' ), 'Provisioning changed native order storage authority.' );

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
$readiness = commerce_request( 'GET', '/readiness', $headers );
commerce_check( 200 === $readiness->get_status() && true === $readiness->get_data()['data']['catalog_ready'], 'Prepared native schemas and private storage are not ready.' );
commerce_check( 401 === commerce_request( 'GET', '/readiness', array() )->get_status(), 'Readiness leaked to an unauthenticated caller.' );
$key = 'contract-' . strtolower( wp_generate_password( 8, false ) );
$path = '/products/' . $key;
$product_data = array( 'generation' => (int) get_option( 'spacefast_commerce_catalog_generation', 0 ) + 1, 'name' => 'Source product', 'description' => '<p>Source description</p>', 'price' => '12.50', 'enabled' => true, 'kind' => 'physical' );
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
// Digital replacement uses Woo's native grant table and preserves already-issued file IDs.
$file_bodies = array( 'purchased.pdf' => "%PDF-1.4\nPurchased version\n", 'replacement.pdf' => "%PDF-1.4\nReplacement version\n" );
$file_receipts = array();
foreach ( $file_bodies as $filename => $bytes ) {
	$sha = hash( 'sha256', $bytes );
	$upload = new WP_REST_Request( 'PUT', '/spacefast-commerce/v1/files/' . $sha );
	$upload->set_headers( $headers );
	$upload->set_header( 'X-Spacefast-Filename', rawurlencode( $filename ) );
	$upload->set_body( $bytes );
	$unauthenticated = clone $upload;
	$unauthenticated->set_header( 'Authorization', '' );
	commerce_check( 401 === rest_do_request( $unauthenticated )->get_status(), 'Private ingestion accepted an anonymous caller.' );
	$wrong_bytes = clone $upload;
	$wrong_bytes->set_body( $bytes . 'modified' );
	commerce_check( 422 === rest_do_request( $wrong_bytes )->get_status(), 'Private ingestion accepted mismatched bytes.' );
	$traversal = clone $upload;
	$traversal->set_header( 'X-Spacefast-Filename', rawurlencode( '../public.pdf' ) );
	commerce_check( 422 === rest_do_request( $traversal )->get_status(), 'Private ingestion accepted a traversal filename.' );
	$receipt = rest_do_request( $upload );
	commerce_check( 200 === $receipt->get_status(), 'Private upload failed: ' . wp_json_encode( $receipt->get_data() ) );
	$file_receipts[] = array( 'sha256' => $sha, 'filename' => $filename );
	$retry = rest_do_request( $upload );
	commerce_check( $receipt->get_data() === $retry->get_data(), 'Private upload retry changed receipt.' );
}
$product_data['generation']++;
$product_data['enabled'] = true;
$product_data['kind'] = 'digital';
$product_data['downloads'] = array();
commerce_check( 422 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Digital product accepted missing protected files.' );
$product_data['downloads'] = array( $file_receipts[0] );
$product_data['download_limit'] = 5;
$product_data['download_expiry'] = 30;
commerce_check( 200 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Download catalog apply failed.' );
$digital_order = wc_create_order();
$buyer_email = 'buyer-' . $key . '@example.test';
$digital_order->set_billing_email( $buyer_email );
$digital_order->add_product( wc_get_product( $id ), 1 );
$digital_order->calculate_totals();
$digital_order->save();
commerce_check( array() === $digital_order->get_downloadable_items(), 'Unpaid order has downloads.' );
$digital_order->payment_complete( 'local-contract-payment' );
$original_downloads = $digital_order->get_downloadable_items();
commerce_check( 1 === count( $original_downloads ), 'Woo did not grant the paid file.' );
$original_download = current( $original_downloads );
$product_data['generation']++;
$product_data['downloads'] = array( $file_receipts[1] );
commerce_check( 200 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Download replacement failed.' );
$old_downloads = wc_get_order( $digital_order->get_id() )->get_downloadable_items();
commerce_check( 1 === count( $old_downloads ) && $original_download['download_id'] === current( $old_downloads )['download_id'], 'Replacement invalidated a native grant.' );
commerce_check( $file_bodies['purchased.pdf'] === file_get_contents( current( $old_downloads )['file']['file'] ), 'Purchased file reference changed bytes.' );
$new_order = wc_create_order();
$new_order->set_billing_email( 'next-buyer@example.test' );
$new_order->add_product( wc_get_product( $id ), 1 );
$new_order->calculate_totals();
$new_order->save();
$new_order->payment_complete( 'local-contract-new-payment' );
$new_downloads = $new_order->get_downloadable_items();
commerce_check( 1 === count( $new_downloads ) && current( $new_downloads )['download_id'] !== $original_download['download_id'], 'New purchase received historical files.' );
commerce_check( $file_bodies['replacement.pdf'] === file_get_contents( current( $new_downloads )['file']['file'] ), 'New purchase received the wrong bytes.' );
$product_data['generation']++;
$product_data['enabled'] = false;
commerce_check( 200 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Digital archive failed.' );
// The HTTP runner uses these native URLs to prove the actual handler, not just metadata.
file_put_contents( '/tmp/commerce-download-contract.json', wp_json_encode( array(
	'original_url' => $original_download['download_url'],
	'replacement_url' => current( $new_downloads )['download_url'],
	'original_sha256' => $file_receipts[0]['sha256'],
	'replacement_sha256' => $file_receipts[1]['sha256'],
	'original_order_id' => $digital_order->get_id(),
	'original_email' => $buyer_email,
	'product_id' => $id,
) ) );
$rotated = array_merge( $binding, array( 'credential' => bin2hex( random_bytes( 32 ) ) ) );
commerce_check( true === $store->bind( $rotated ), 'Credential rotation failed.' );
commerce_check( 401 === commerce_request( 'GET', '/products', $headers )->get_status(), 'Revoked credential still works.' );
echo "PASS: bound store authentication, catalog retry/fencing, managed writes, native counters, order history, archive, receipt policy, credential rotation, private ingestion, native grants and file replacement\n";
