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
$gateways = WC()->payment_gateways()->payment_gateways();
commerce_check( isset( $gateways['spacefast_connect'] ) && false === $gateways['spacefast_connect']->is_available(), 'Managed gateway must stay unavailable before API configuration.' );
$block_registry = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class );
commerce_check( $block_registry->is_registered( 'spacefast_connect' ), 'Native Checkout Block payment method is not registered.' );
commerce_check( is_wp_error( $store->bind( array_merge( $binding, array( 'environment' => 'live' ) ) ) ), 'Store crossed environments.' );
commerce_check( 'force' === get_option( 'woocommerce_file_download_method' ), 'Woo download transport is not protected.' );
commerce_check( false === apply_filters( 'woo_storefront_checkout_redirect_after_order', true ), 'Managed checkout skips Woo receipt.' );
commerce_check( $binding['origin'] === apply_filters( 'woo_storefront_checkout_return_url', 'https://untrusted.example' ), 'Return target is caller-controlled.' );
$native_storage_mode = get_option( 'woocommerce_custom_orders_table_enabled' );
$checkout_id = wc_get_page_id( 'checkout' );
$checkout_content = get_post( $checkout_id )->post_content;
$provisioning = new \SpacefastCommerce\Provisioning( $store, new \SpacefastCommerce\PrivateFiles( $store ) );
update_post_meta( $checkout_id, '_spacefast_space_id', 'spc_other' );
$conflict = $provisioning->prepare_pages();
commerce_check( is_wp_error( $conflict ) && 'checkout_page_scope_conflict' === $conflict->get_error_code(), 'Provisioning took another Space checkout page.' );
commerce_check( 'spc_other' === get_post_meta( $checkout_id, '_spacefast_space_id', true ), 'Conflicting checkout ownership changed.' );
delete_post_meta( $checkout_id, '_spacefast_space_id' );
$binding_file = tempnam( sys_get_temp_dir(), 'commerce-binding-' );
file_put_contents( $binding_file, wp_json_encode( $binding ) );
try {
	WP_CLI::runcommand( 'spacefast-commerce prepare ' . escapeshellarg( $binding_file ) );
	WP_CLI::runcommand( 'spacefast-commerce prepare ' . escapeshellarg( $binding_file ) );
} finally {
	unlink( $binding_file );
}
// CLI provisioning runs in a separate process; emulate the next HTTP request's fresh post cache.
foreach ( array( 'cart', 'checkout' ) as $page_name ) {
	clean_post_cache( wc_get_page_id( $page_name ) );
}
commerce_check( $native_storage_mode === get_option( 'woocommerce_custom_orders_table_enabled' ), 'Provisioning changed native order storage authority.' );
commerce_check( $checkout_content === get_post( $checkout_id )->post_content, 'Provisioning overwrote native checkout content.' );
$scoped_checkout = get_posts( array( 'post_type' => 'page', 'include' => array( $checkout_id ), 'meta_key' => '_spacefast_space_id', 'meta_value' => $binding['space_id'] ) );
commerce_check( 1 === count( $scoped_checkout ) && $checkout_id === $scoped_checkout[0]->ID, 'Native checkout is hidden from Space-scoped queries.' );

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
commerce_check( true === $readiness->get_data()['data']['checkout_pages_ready'], 'Prepared native checkout pages are not ready.' );
commerce_check( 401 === commerce_request( 'GET', '/readiness', array() )->get_status(), 'Readiness leaked to an unauthenticated caller.' );
$key = 'contract-' . strtolower( wp_generate_password( 8, false ) );
$path = '/products/' . $key;
$product_data = array( 'generation' => (int) get_option( 'spacefast_commerce_catalog_generation', 0 ) + 1, 'name' => 'Source product', 'description' => '<p>Source description</p>', 'price' => '12.50', 'enabled' => true, 'kind' => 'physical', 'shipping' => array( 'included' => true, 'allowedCountries' => array( 'PL' ), 'policy' => 'Ships to Poland. Shipping is included.' ) );
commerce_check( 401 === commerce_request( 'PUT', $path, array(), $product_data )->get_status(), 'Unauthenticated catalog write succeeded.' );
$wrong_headers = array_merge( $headers, array( 'X-Spacefast-Environment' => 'live' ) );
commerce_check( 403 === commerce_request( 'PUT', $path, $wrong_headers, $product_data )->get_status(), 'Cross-environment catalog write succeeded.' );
commerce_check( 422 === commerce_request( 'PUT', $path, $headers, array_merge( $product_data, array( 'description' => '<script>unsafe()</script>' ) ) )->get_status(), 'Source description was silently rewritten instead of refused.' );
$response = commerce_request( 'PUT', $path, $headers, $product_data );
commerce_check( 200 === $response->get_status(), 'Catalog create failed: ' . wp_json_encode( $response->get_data() ) );
$id = $response->get_data()['data']['id'];
$retry = commerce_request( 'PUT', $path, $headers, $product_data );
commerce_check( $id === $retry->get_data()['data']['id'], 'Retry duplicated a product.' );
$read = commerce_request( 'GET', '/products', $headers )->get_data()['data']['products'];
commerce_check( in_array( $id, array_column( $read, 'id' ), true ), 'Managed catalog cannot read its own products.' );
$missing_archive = commerce_request( 'PUT', '/products/absent-' . $key, $headers, array( 'generation' => $product_data['generation'], 'action' => 'archive' ) );
commerce_check( 200 === $missing_archive->get_status() && null === $missing_archive->get_data()['data'], 'Archiving a missing key changed a different managed product.' );
$ordinary = new WC_Product_Simple();
$ordinary->set_name( 'Unmanaged catalog neighbor' );
$ordinary->set_status( 'publish' );
$ordinary->save();
$managed_read = commerce_request( 'GET', '/products', $headers )->get_data()['data']['products'];
commerce_check( ! in_array( $ordinary->get_id(), array_column( $managed_read, 'id' ), true ), 'Managed catalog read included an unmanaged product.' );
$ordinary->delete( true );
$product = wc_get_product( $id );
commerce_check( $product->is_sold_individually() && ! $product->is_virtual(), 'Simple physical product policy failed.' );
commerce_check( $product_data['shipping'] === $response->get_data()['data']['shipping'] && 'USD' === $response->get_data()['data']['currency'], 'Catalog projection dropped merchant shipping or currency.' );
wc_load_cart();
WC()->cart->get_cart();
WC()->cart->empty_cart();
WC()->customer->set_shipping_country( 'PL' );
commerce_check( false !== WC()->cart->add_to_cart( $id, 1 ), 'Managed physical product could not enter the native cart.' );
WC()->cart->calculate_totals();
$packages = WC()->shipping()->get_packages();
commerce_check( isset( $packages[0]['rates']['spacefast_included'] ) && 0.0 === (float) WC()->cart->get_shipping_total(), 'Native cart did not apply included shipping: ' . wp_json_encode( array( 'needs' => WC()->cart->needs_shipping(), 'show' => WC()->cart->show_shipping(), 'destination' => WC()->cart->get_shipping_packages()[0]['destination'] ?? null, 'rates' => isset( $packages[0] ) ? array_keys( $packages[0]['rates'] ) : null ) ) );
commerce_check( array( 'PL' ) === array_keys( WC()->countries->get_shipping_countries() ), 'Checkout exposed undeclared shipping destinations.' );
$cart_controller = new \Automattic\WooCommerce\StoreApi\Utilities\CartController();
$cart_controller->validate_cart();
WC()->customer->set_shipping_country( 'DE' );
WC()->cart->calculate_totals();
commerce_check( array() === WC()->shipping()->get_packages()[0]['rates'], 'Undeclared destination received a shipping rate.' );
$denied = false;
try { $cart_controller->validate_cart(); } catch ( \Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException $error ) { $denied = true; }
commerce_check( $denied, 'Store API accepted an undeclared shipping destination.' );
$classic_errors = new WP_Error();
do_action( 'woocommerce_after_checkout_validation', array( 'billing_country' => 'DE' ), $classic_errors );
commerce_check( $classic_errors->has_errors(), 'Classic checkout accepted an undeclared shipping destination.' );
WC()->cart->empty_cart();

foreach ( array(
	fn () => ( function () use ( $id ): void { $product = wc_get_product( $id ); $product->set_regular_price( '99' ); $product->save(); } )(),
	fn () => update_post_meta( $id, '_price', '99' ),
	fn () => update_post_meta( $id, '_spacefast_shipping', array( 'included' => true, 'allowedCountries' => array( 'DE' ) ) ),
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
// A root SQL override bypasses hooks; the next source sync must observe and repair it.
global $wpdb;
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => '99.00' ), array( 'post_id' => $id, 'meta_key' => '_regular_price' ) );

$sale_starts = time() + 3600;
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => '_sale_price_dates_from', 'meta_value' => $sale_starts ) );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => '_sale_price_dates_to', 'meta_value' => $sale_starts + 3600 ) );
clean_post_cache( $id );
$drifted = commerce_request( 'GET', '/products', $headers )->get_data()['data']['products'];
$drifted = current( array_filter( $drifted, static fn ( array $row ): bool => $row['id'] === $id ) );
commerce_check( '99.00' === $drifted['price'] && $sale_starts === $drifted['date_on_sale_from'], 'Catalog read concealed a root-managed price override.' );
$product_data['generation']++;
$product_data['name'] = 'New source name';
$product_data['price'] = '15.00';
commerce_check( 200 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Source update failed.' );
commerce_check( '15.00' === wc_get_product( $id )->get_regular_price() && null === wc_get_product( $id )->get_date_on_sale_from() && null === wc_get_product( $id )->get_date_on_sale_to(), 'Source price was not applied.' );
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
$product_data['shipping'] = null;
$product_data['downloads'] = array();
commerce_check( 422 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Digital product accepted missing protected files.' );
$product_data['downloads'] = array( $file_receipts[0] );
$product_data['download_limit'] = 5;
$product_data['download_expiry'] = 30;
commerce_check( 200 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'Download catalog apply failed.' );
$projection = commerce_request( 'GET', '/products', $headers )->get_data()['data']['products'];
$projection = current( array_filter( $projection, static fn ( array $row ): bool => $row['id'] === $id ) );
commerce_check( array( $file_receipts[0] ) === $projection['downloads'] && 5 === $projection['download_limit'] && 30 === $projection['download_expiry'] && null === $projection['shipping'], 'Source sync cannot observe protected file declarations and download policy.' );
$digital_order = wc_create_order();
$buyer_email = 'buyer-' . $key . '@example.test';
$digital_order->set_billing_email( $buyer_email );
$digital_order->set_payment_method( 'spacefast_connect' );
$digital_order->add_product( wc_get_product( $id ), 1 );
$digital_order->calculate_totals();
$digital_order->save();
commerce_check( array() === $digital_order->get_downloadable_items(), 'Unpaid order has downloads.' );
$payment_path = '/orders/' . $digital_order->get_id() . '/payment';
commerce_check( 401 === commerce_request( 'GET', $payment_path, array() )->get_status(), 'Payment order accepted an anonymous caller.' );
commerce_check( 403 === commerce_request( 'GET', $payment_path, $wrong_headers )->get_status(), 'Payment order crossed environments.' );
commerce_check( 404 === commerce_request( 'GET', '/orders/' . $order->get_id() . '/payment', $headers )->get_status(), 'Payment boundary accepted an unrelated native order.' );
$payment = commerce_request( 'GET', $payment_path, $headers );
commerce_check( 200 === $payment->get_status(), 'Trusted native payment snapshot failed.' );
$snapshot = $payment->get_data()['data'];
commerce_check( $digital_order->get_total() === $snapshot['total'] && $digital_order->get_currency() === $snapshot['currency'] && '' !== $snapshot['attempt_id'], 'Payment snapshot did not use the native order.' );
$payment_result = array( 'action' => 'bind_intent', 'attempt_id' => $snapshot['attempt_id'], 'intent_id' => 'pi_contract' . $digital_order->get_id(), 'total' => $snapshot['total'], 'currency' => $snapshot['currency'] );
commerce_check( 409 === commerce_request( 'PUT', $payment_path, $headers, array_merge( $payment_result, array( 'total' => '0.01' ) ) )->get_status(), 'Payment accepted an amount different from the native order.' );
commerce_check( 401 === commerce_request( 'PUT', $payment_path, array(), array_merge( $payment_result, array( 'action' => 'paid' ) ) )->get_status(), 'Anonymous payment result completed a native order.' );
commerce_check( 200 === commerce_request( 'PUT', $payment_path, $headers, $payment_result )->get_status(), 'Native intent binding failed.' );
commerce_check( 409 === commerce_request( 'PUT', $payment_path, $headers, array_merge( $payment_result, array( 'intent_id' => 'pi_another' ) ) )->get_status(), 'Retry switched the order to another intent.' );
$recalculated = wc_get_order( $digital_order->get_id() );
$recalculated->set_total( wc_format_decimal( (float) $snapshot['total'] + 1, wc_get_price_decimals() ) );
$recalculated->save();
$payment_result['action'] = 'paid';
commerce_check( 409 === commerce_request( 'PUT', $payment_path, $headers, $payment_result )->get_status(), 'An old amount completed a recalculated order.' );
$payment_result['total'] = $recalculated->get_total();
commerce_check( 200 === commerce_request( 'PUT', $payment_path, $headers, $payment_result )->get_status(), 'Verified payment result did not complete the order.' );
commerce_check( 200 === commerce_request( 'PUT', $payment_path, $headers, $payment_result )->get_status(), 'Verified payment retry failed.' );
$digital_order = wc_get_order( $digital_order->get_id() );
commerce_check( $digital_order->is_paid() && $payment_result['intent_id'] === $digital_order->get_transaction_id(), 'Verified payment did not persist native transaction identity.' );
$original_downloads = $digital_order->get_downloadable_items();
commerce_check( 1 === count( $original_downloads ), 'Woo did not grant the paid file.' );
$original_download = current( $original_downloads );
// Root can corrupt backing bytes; the same authenticated source upload restores the granted path.
file_put_contents( $original_download['file']['file'], 'Root-corrupted download bytes' );
$corrupt = commerce_request( 'GET', '/products', $headers )->get_data()['data']['products'];
$corrupt = current( array_filter( $corrupt, static fn ( array $row ): bool => $row['id'] === $id ) );
commerce_check( null === $corrupt['downloads'], 'Source sync hid corrupted protected bytes.' );
$repair = new WP_REST_Request( 'PUT', '/spacefast-commerce/v1/files/' . $file_receipts[0]['sha256'] );
$repair->set_headers( $headers );
$repair->set_header( 'X-Spacefast-Filename', rawurlencode( 'purchased.pdf' ) );
$repair->set_body( $file_bodies['purchased.pdf'] );
commerce_check( 200 === rest_do_request( $repair )->get_status(), 'Hash-verified source upload did not repair corrupted bytes.' );
commerce_check( $file_bodies['purchased.pdf'] === file_get_contents( $original_download['file']['file'] ) && $original_download['download_id'] === current( wc_get_order( $digital_order->get_id() )->get_downloadable_items() )['download_id'], 'Repair changed the native grant or its purchased bytes.' );
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => maybe_serialize( array( 'root_override' => array( 'name' => 'Public drift', 'file' => 'https://untrusted.example/private.pdf' ) ) ) ), array( 'post_id' => $id, 'meta_key' => '_downloadable_files' ) );
clean_post_cache( $id );
$drifted = commerce_request( 'GET', '/products', $headers )->get_data()['data']['products'];
$drifted = current( array_filter( $drifted, static fn ( array $row ): bool => $row['id'] === $id ) );
commerce_check( null === $drifted['downloads'] && ! str_contains( wp_json_encode( $drifted ), 'untrusted.example' ), 'Unsafe download drift leaked a backing URL instead of a repair signal.' );
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
$new_order->set_payment_method( 'spacefast_connect' );
$new_order->save();
$new_payment_path = '/orders/' . $new_order->get_id() . '/payment';
$new_snapshot = commerce_request( 'GET', $new_payment_path, $headers )->get_data()['data'];
commerce_check( '' === $new_snapshot['intent_id'], 'Lost-response fixture already had a bound intent.' );
$new_result = array( 'action' => 'paid', 'attempt_id' => $new_snapshot['attempt_id'],
	'intent_id' => 'pi_contract' . $new_order->get_id(), 'total' => $new_snapshot['total'], 'currency' => $new_snapshot['currency'] );
commerce_check( 200 === commerce_request( 'PUT', $new_payment_path, $headers, $new_result )->get_status(), 'Verified paid result did not recover a lost setup response.' );
$new_order = wc_get_order( $new_order->get_id() );
$new_downloads = $new_order->get_downloadable_items();
commerce_check( 1 === count( $new_downloads ) && current( $new_downloads )['download_id'] !== $original_download['download_id'], 'New purchase received historical files.' );
commerce_check( $file_bodies['replacement.pdf'] === file_get_contents( current( $new_downloads )['file']['file'] ), 'New purchase received the wrong bytes.' );
$product_data['generation']++;
commerce_check( 200 === commerce_request( 'PUT', $path, $headers, array( 'generation' => $product_data['generation'], 'action' => 'archive' ) )->get_status(), 'Digital archive failed.' );
$retired = new WP_REST_Request( 'GET', '/spacefast-commerce/v1/products' );
$retired->set_headers( $headers );
$retired->set_param( 'keys', array( $key ) );
$retired = rest_do_request( $retired )->get_data()['data']['products'];
$retired = current( array_filter( $retired, static fn ( array $row ): bool => $row['id'] === $id ) );
commerce_check( false === $retired['enabled'] && array( $file_receipts[1] ) === $retired['downloads'], 'Desired-key read lost retained native archive state.' );
$prior_generation = (int) get_option( 'spacefast_commerce_catalog_generation' );
update_option( 'woocommerce_currency', 'EUR' );
try {
	$empty = commerce_request( 'GET', '/products', $headers )->get_data()['data'];
	commerce_check( 'EUR' === $empty['currency'] && array() === $empty['products'], 'Empty catalog hid native currency drift.' );
	commerce_check( 409 === commerce_request( 'PUT', '/catalog/fence', $headers, array( 'generation' => $prior_generation + 1 ) )->get_status(), 'Currency drift advanced an empty catalog fence.' );
	commerce_check( $prior_generation === (int) get_option( 'spacefast_commerce_catalog_generation' ) && 'USD' === wc_get_order( $digital_order->get_id() )->get_currency(), 'Currency refusal changed catalog ownership or historical order currency.' );
} finally {
	update_option( 'woocommerce_currency', 'USD' );
}
$fence = array( 'generation' => $product_data['generation'] + 1 );
commerce_check( 200 === commerce_request( 'PUT', '/catalog/fence', $headers, $fence )->get_status(), 'Empty-catalog generation was not fenced.' );
commerce_check( 409 === commerce_request( 'PUT', $path, $headers, $product_data )->get_status(), 'An older apply reopened an archived product after an empty-catalog fence.' );
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
echo "PASS: native source drift/repair, included shipping/destinations, empty-catalog fence, managed guards, protected ingestion, historical grants, native orders and trusted payment retry\n";
