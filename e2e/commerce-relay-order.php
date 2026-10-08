<?php
/** Native order fixture for the separately opted-in API/Stripe/Woo relay contract. */
if ( 'create' === ( $args[0] ?? '' ) ) {
	$product = wc_get_product( (int) ( $args[1] ?? 0 ) );
	if ( ! $product ) {
		throw new RuntimeException( 'Relay product was not prepared.' );
	}
	$order = wc_create_order();
	$order->set_billing_email( $args[2] );
	$order->set_payment_method( 'spacefast_connect' );
	$order->add_product( $product, 1 );
	$order->calculate_totals();
	$order->save();
} else {
	$order = wc_get_order( (int) ( $args[1] ?? 0 ) );
}
if ( ! $order ) {
	throw new RuntimeException( 'Relay order was not found.' );
}
$payments = new \SpacefastCommerce\PaymentOrders( new \SpacefastCommerce\Store() );
if ( 'create' === $args[0] ) {
	$snapshot = $payments->for_gateway( $order->get_id() );
} else {
	$request = new WP_REST_Request( 'GET' );
	$request->set_param( 'id', $order->get_id() );
	$response = $payments->snapshot( $request );
	$snapshot = is_wp_error( $response ) ? $response : $response->get_data()['data'];
}
if ( is_wp_error( $snapshot ) ) {
	throw new RuntimeException( $snapshot->get_error_code() );
}
echo 'COMMERCE_ORDER_JSON ' . wp_json_encode( array(
	'payment' => $snapshot,
	'transaction_id' => $order->get_transaction_id(),
	'refunded_total' => wc_format_decimal( $order->get_total_refunded(), 2 ),
	'refund_count' => count( $order->get_refunds() ),
	'download_urls' => array_values( array_column( $order->get_downloadable_items(), 'download_url' ) ),
) ) . "\n";
