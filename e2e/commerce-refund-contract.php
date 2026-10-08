<?php
/** Continue the disposable contract after its paid native download was exercised over HTTP. */
$fixture = json_decode( file_get_contents( '/tmp/commerce-download-contract.json' ), true );
$order = wc_get_order( $fixture['original_order_id'] );
$items = $order->get_items();
$item = current( $items );
$store = new \SpacefastCommerce\Store();
$binding = $store->binding();
$credential = bin2hex( random_bytes( 32 ) );
$store->bind( array_merge( $binding, array( 'credential' => $credential ) ) );
$payments = new \SpacefastCommerce\PaymentOrders( $store );
$snapshot = $payments->for_gateway( $order->get_id() );
$identity = array_intersect_key( $snapshot, array_flip( array( 'attempt_id', 'intent_id', 'total', 'currency' ) ) );
$apply_refunds = static function ( array $refunds, bool $authenticated = true ) use ( $order, $binding, $credential, $identity ): WP_REST_Response {
	$request = new WP_REST_Request( 'PUT', '/spacefast-commerce/v1/orders/' . $order->get_id() . '/payment/refunds' );
	if ( $authenticated ) {
		$request->set_headers( array( 'Authorization' => 'Bearer ' . $credential, 'X-Spacefast-Space-Id' => $binding['space_id'],
			'X-Spacefast-Store-Id' => $binding['store_id'], 'X-Spacefast-Environment' => $binding['environment'] ) );
	}
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array_merge( $identity, array( 'refunds' => $refunds ) ) ) );
	return rest_do_request( $request );
};
$partial_result = array( array( 'id' => 're_partial' . $order->get_id(), 'amount' => '1.00' ) );
if ( 401 !== $apply_refunds( $partial_result, false )->get_status() ) {
	throw new RuntimeException( 'An unauthenticated refund result reached native accounting.' );
}
$partial = $apply_refunds( $partial_result );
if ( 200 !== $partial->get_status() || 200 !== $apply_refunds( $partial_result )->get_status() || 1 !== count( wc_get_order( $order->get_id() )->get_refunds() ) ) {
	throw new RuntimeException( 'Verified partial refund did not persist once across response replay.' );
}
if ( 409 !== $apply_refunds( array( array( 'id' => $partial_result[0]['id'], 'amount' => '2.00' ) ) )->get_status() ) {
	throw new RuntimeException( 'Refund replay changed its captured provider amount.' );
}
if ( ! wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'Native partial refund unexpectedly revoked paid access.' );
}
$view = new \SpacefastCommerce\Orders( new \SpacefastCommerce\Store() );
$request = new WP_REST_Request( 'GET' );
$request->set_param( 'id', $order->get_id() );
$partial_view = $view->detail( $request )->get_data()['data'];
if ( '1.00' !== $partial_view['refunded_total'] || !$partial_view['paid'] ) {
	throw new RuntimeException( 'Native order view lost the partial refund amount or paid state.' );
}
$full_result = array_merge( $partial_result, array( array( 'id' => 're_full' . $order->get_id(),
	'amount' => wc_format_decimal( wc_get_order( $order->get_id() )->get_remaining_refund_amount(), 2 ) ) ) );
$full = $apply_refunds( $full_result );
if ( 200 !== $full->get_status() || 200 !== $apply_refunds( $full_result )->get_status() || 2 !== count( wc_get_order( $order->get_id() )->get_refunds() ) ) {
	throw new RuntimeException( 'Verified full refund did not converge once across response replay.' );
}
if ( wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'Native full refund retained paid access.' );
}
// Simulate loss after the native refund saves and before the final status transition.
$interrupted = wc_get_order( $order->get_id() );
$interrupted->set_status( 'completed' );
$interrupted->save();
if ( 200 !== $apply_refunds( $full_result )->get_status() || 'refunded' !== wc_get_order( $order->get_id() )->get_status() || 2 !== count( wc_get_order( $order->get_id() )->get_refunds() ) ) {
	throw new RuntimeException( 'Full refund replay did not recover the native final status without duplicate accounting.' );
}
$full_view = $view->detail( $request )->get_data()['data'];
$refunded_resend = $view->resend( $request );
if ( ! is_wp_error( $refunded_resend ) || 'order_delivery_unavailable' !== $refunded_resend->get_error_code() ) {
	throw new RuntimeException( 'Native resend revived delivery for a fully refunded order.' );
}
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
// A full refund may be verified before the delayed paid notification reaches Woo.
$pending = wc_create_order();
$pending->set_payment_method( 'spacefast_connect' );
$pending->set_billing_email( 'refund-before-payment@example.test' );
$pending->add_product( wc_get_product( $fixture['product_id'] ), 1 );
$pending->calculate_totals();
$pending->save();
$pending_snapshot = $payments->for_gateway( $pending->get_id() );
$pending_identity = array_intersect_key( $pending_snapshot, array_flip( array( 'attempt_id', 'intent_id', 'total', 'currency' ) ) );
$pending_identity['intent_id'] = 'pi_refundedBeforePaid' . $pending->get_id();
$pending_request = new WP_REST_Request( 'PUT' );
$pending_request->set_param( 'id', $pending->get_id() );
$pending_request->set_header( 'Content-Type', 'application/json' );
$pending_request->set_body( wp_json_encode( array_merge( $pending_identity, array( 'refunds' => array(
	array( 'id' => 're_beforePaid' . $pending->get_id(), 'amount' => $pending_snapshot['total'] ),
) ) ) ) );
$completion_count = 0;
$observe_completion = static function ( int $id ) use ( $pending, &$completion_count ): void {
	if ( $pending->get_id() === $id ) {
		$completion_count++;
	}
};
add_action( 'woocommerce_payment_complete', $observe_completion );
try {
	$before_paid = $payments->apply_refunds( $pending_request );
	$pending = wc_get_order( $pending->get_id() );
	if ( is_wp_error( $before_paid ) || 'refunded' !== $pending->get_status() || 0 !== $completion_count || $pending->is_download_permitted() || array() !== $pending->get_downloadable_items() ) {
		throw new RuntimeException( 'A full refund arriving first transiently completed payment or granted delivery.' );
	}
} finally {
	remove_action( 'woocommerce_payment_complete', $observe_completion );
}
echo "PASS: verified refund imports persist once; partial refund preserves native paid access; full refund revokes it; late payment cannot reopen it\n";
