<?php
/** Continue the disposable contract after its paid native download was exercised over HTTP. */
$fixture = json_decode( file_get_contents( '/tmp/commerce-download-contract.json' ), true );
$order = wc_get_order( $fixture['original_order_id'] );
$items = $order->get_items();
$item = current( $items );
$partial = wc_create_refund( array(
	'order_id' => $order->get_id(), 'amount' => '1.00', 'reason' => 'Local native partial refund contract',
	'refund_payment' => false,
) );
if ( is_wp_error( $partial ) || ! wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'Native partial refund unexpectedly revoked paid access.' );
}
$view = new \SpacefastCommerce\Orders( new \SpacefastCommerce\Store() );
$request = new WP_REST_Request( 'GET' );
$request->set_param( 'id', $order->get_id() );
$partial_view = $view->detail( $request )->get_data()['data'];
if ( '1.00' !== $partial_view['refunded_total'] || !$partial_view['paid'] ) {
	throw new RuntimeException( 'Native order view lost the partial refund amount or paid state.' );
}
$full = wc_create_refund( array(
	'order_id' => $order->get_id(), 'amount' => (string) ( (float) $order->get_total() - 1 ),
	'reason' => 'Local native full refund contract', 'refund_payment' => false,
	'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => (string) ( (float) $item->get_total() - 1 ) ) ),
) );
if ( is_wp_error( $full ) || wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'Native full refund retained paid access.' );
}
$full_view = $view->detail( $request )->get_data()['data'];
if ( wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) !== $full_view['refunded_total'] || 'refunded' !== $full_view['status'] || $full_view['paid'] ) {
	throw new RuntimeException( 'Native order view did not converge to the complete native refund.' );
}
$payments = new \SpacefastCommerce\PaymentOrders( new \SpacefastCommerce\Store() );
$snapshot = $payments->for_gateway( $order->get_id() );
$late = new WP_REST_Request( 'PUT' );
$late->set_param( 'id', $order->get_id() );
$late->set_header( 'Content-Type', 'application/json' );
$late->set_body( wp_json_encode( array(
	'action' => 'paid', 'attempt_id' => $snapshot['attempt_id'], 'intent_id' => $snapshot['intent_id'],
	'total' => $snapshot['total'], 'currency' => $snapshot['currency'],
) ) );
$result = $payments->apply( $late );
if ( ! is_wp_error( $result ) || 'payment_order_closed' !== $result->get_error_code() || wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'Late paid result reopened a refunded order.' );
}
echo "PASS: native partial refund preserves paid access; native full refund revokes it; late payment result cannot reopen it\n";
