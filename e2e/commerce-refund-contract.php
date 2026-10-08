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
$apply_disputes = static function ( array $disputes, bool $authenticated = true ) use ( $order, $binding, $credential, $identity ): WP_REST_Response {
	$request = new WP_REST_Request( 'PUT', '/spacefast-commerce/v1/orders/' . $order->get_id() . '/payment/disputes' );
	if ( $authenticated ) {
		$request->set_headers( array( 'Authorization' => 'Bearer ' . $credential, 'X-Spacefast-Space-Id' => $binding['space_id'],
			'X-Spacefast-Store-Id' => $binding['store_id'], 'X-Spacefast-Environment' => $binding['environment'] ) );
	}
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array_merge( $identity, array( 'disputes' => $disputes ) ) ) );
	return rest_do_request( $request );
};
$permissions = static fn (): array => array_map( static fn ( \WC_Customer_Download $permission ): array => $permission->get_data(), \WC_Data_Store::load( 'customer-download' )->get_downloads( array( 'order_id' => $order->get_id() ) ) );
$permissions_before = wp_json_encode( $permissions() );
$active_dispute = array( array( 'id' => 'dp_restore' . $order->get_id(), 'status' => 'needs_response' ) );
if ( 401 !== $apply_disputes( $active_dispute, false )->get_status() || 200 !== $apply_disputes( $active_dispute )->get_status() || 200 !== $apply_disputes( $active_dispute )->get_status() ) {
	throw new RuntimeException( 'Authenticated native dispute suspension did not replay.' );
}
$denied_url = preg_replace( '#^https?://[^/]+#', 'http://127.0.0.1', $fixture['original_url'] );
$denied_download = wp_remote_get( $denied_url, array( 'redirection' => 0 ) );
if ( 403 !== wp_remote_retrieve_response_code( $denied_download ) || hash( 'sha256', wp_remote_retrieve_body( $denied_download ) ) === $fixture['original_sha256'] ) {
	throw new RuntimeException( 'Native HTTP download delivered disputed bytes.' );
}
$held = wc_get_order( $order->get_id() );
if ( 'on-hold' !== $held->get_status() || $held->is_download_permitted() || $permissions_before !== wp_json_encode( $permissions() ) ) {
	throw new RuntimeException( 'Dispute suspension delivered bytes or changed consumed grant state.' );
}
$held->update_status( 'completed' );
if ( wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'A manual paid status bypassed native dispute denial.' );
}
if ( ! is_wp_error( $payments->for_gateway( $order->get_id() ) ) ) {
	throw new RuntimeException( 'A manual paid status bypassed disputed checkout acquisition denial.' );
}
$held = wc_get_order( $order->get_id() );
$view = new \SpacefastCommerce\Orders( $store );
$held_request = new WP_REST_Request( 'GET' );
$held_request->set_param( 'id', $order->get_id() );
$held_view = $view->detail( $held_request )->get_data()['data'];
if ( $held_view['can_resend'] || $held_view['can_ship'] || ! is_wp_error( $view->resend( $held_request ) ) ) {
	throw new RuntimeException( 'A manual paid status bypassed disputed merchant action denial.' );
}
// Return to hold through the operator path; automatic restoration must preserve that override.
$held->update_status( 'on-hold' );
$won_dispute = array( array( 'id' => $active_dispute[0]['id'], 'status' => 'won' ) );
if ( 200 !== $apply_disputes( $won_dispute )->get_status() || 'on-hold' !== wc_get_order( $order->get_id() )->get_status() ) {
	throw new RuntimeException( 'Resolved dispute overwrote an operator hold.' );
}
$held = wc_get_order( $order->get_id() );
$held->update_status( 'completed' );
$mail_ids = static function () use ( $fixture ): array {
	global $phpmailer;
	// Observe the mailbox that actually accepted this fixture's native SMTP mail.
	$response = wp_remote_get( 'http://' . $phpmailer->Host . ':8025/api/v1/search?query=' . rawurlencode( 'to:' . $fixture['original_email'] ) );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		throw new RuntimeException( 'Native dispute mail observation could not reach the captured SMTP mailbox.' );
	}
	$ids = array_column( json_decode( wp_remote_retrieve_body( $response ), true )['messages'], 'ID' );
	sort( $ids );
	return $ids;
};
$mail_before_automatic = $mail_ids();
$second_dispute = array( array( 'id' => 'dp_automatic' . $order->get_id(), 'status' => 'under_review' ) );
$interrupted_hold = false;
$interrupt_hold = static function ( \WC_Order $saved ) use ( $order, $second_dispute, &$interrupted_hold ): void {
	$states = $saved->get_meta( '_spacefast_payment_disputes' );
	if ( ! $interrupted_hold && $saved->get_id() === $order->get_id() && 'completed' === $saved->get_status() &&
		is_array( $states ) && 'under_review' === ( $states[$second_dispute[0]['id']] ?? null ) ) {
		$interrupted_hold = true;
		throw new RuntimeException( 'Native dispute hold interrupted after metadata persistence.' );
	}
};
add_action( 'woocommerce_after_order_object_save', $interrupt_hold );
try {
	$apply_disputes( $second_dispute );
} catch ( RuntimeException $error ) {
	if ( 'Native dispute hold interrupted after metadata persistence.' !== $error->getMessage() ) {
		throw $error;
	}
} finally {
	remove_action( 'woocommerce_after_order_object_save', $interrupt_hold );
}
if ( ! $interrupted_hold || wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'A lost hold transition left native disputed delivery permitted.' );
}
if ( 200 !== $apply_disputes( $second_dispute )->get_status() ) {
	throw new RuntimeException( 'Native second dispute suspension failed.' );
}
$second_won = array( array( 'id' => $second_dispute[0]['id'], 'status' => 'won' ) );
if ( 200 !== $apply_disputes( $second_won )->get_status() || 200 !== $apply_disputes( $second_won )->get_status() ||
	'completed' !== wc_get_order( $order->get_id() )->get_status() || ! wc_get_order( $order->get_id() )->is_download_permitted() || $permissions_before !== wp_json_encode( $permissions() ) ) {
	throw new RuntimeException( 'Won dispute did not restore the captured native paid status and exact consumed grant state.' );
}
if ( $mail_before_automatic !== $mail_ids() ) {
	throw new RuntimeException( 'Automatic dispute restoration repeated native paid-order email.' );
}
if ( 409 !== $apply_disputes( $second_dispute )->get_status() || ! wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'An older dispute result regressed a closed native outcome.' );
}
$partial_result = array( array( 'id' => 're_partial' . $order->get_id(), 'amount' => '1.00' ) );
if ( 401 !== $apply_refunds( $partial_result, false )->get_status() ) {
	throw new RuntimeException( 'An unauthenticated refund result reached native accounting.' );
}
$partial_dispute = array( array( 'id' => 'dp_partial' . $order->get_id(), 'status' => 'under_review' ) );
if ( 200 !== $apply_disputes( $partial_dispute )->get_status() ) {
	throw new RuntimeException( 'Partial refund/dispute fixture did not suspend.' );
}
$partial = $apply_refunds( $partial_result );
if ( 200 !== $partial->get_status() || 200 !== $apply_refunds( $partial_result )->get_status() || 1 !== count( wc_get_order( $order->get_id() )->get_refunds() ) ) {
	throw new RuntimeException( 'Verified partial refund did not persist once across response replay.' );
}
if ( 409 !== $apply_refunds( array( array( 'id' => $partial_result[0]['id'], 'amount' => '2.00' ) ) )->get_status() ) {
	throw new RuntimeException( 'Refund replay changed its captured provider amount.' );
}
if ( 200 !== $apply_disputes( array( array( 'id' => $partial_dispute[0]['id'], 'status' => 'won' ) ) )->get_status() ) {
	throw new RuntimeException( 'Partial refund prevented native dispute restoration.' );
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
$refund_dispute = array( array( 'id' => 'dp_refunded' . $order->get_id(), 'status' => 'needs_response' ) );
if ( 200 !== $apply_disputes( $refund_dispute )->get_status() ) {
	throw new RuntimeException( 'Refund/dispute ordering fixture did not suspend.' );
}
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
if ( 200 !== $apply_disputes( array( array( 'id' => $refund_dispute[0]['id'], 'status' => 'won' ) ) )->get_status() || 'refunded' !== wc_get_order( $order->get_id() )->get_status() ) {
	throw new RuntimeException( 'A won dispute restored a fully refunded order.' );
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
$pending_dispute = new WP_REST_Request( 'PUT', '/spacefast-commerce/v1/orders/' . $pending->get_id() . '/payment/disputes' );
$pending_dispute->set_headers( array( 'Authorization' => 'Bearer ' . $credential, 'X-Spacefast-Space-Id' => $binding['space_id'],
	'X-Spacefast-Store-Id' => $binding['store_id'], 'X-Spacefast-Environment' => $binding['environment'], 'Content-Type' => 'application/json' ) );
$pending_dispute->set_body( wp_json_encode( array_merge( $pending_identity, array( 'disputes' => array( array( 'id' => 'dp_beforePaid' . $pending->get_id(), 'status' => 'needs_response' ) ) ) ) ) );
if ( 200 !== rest_do_request( $pending_dispute )->get_status() || 'on-hold' !== wc_get_order( $pending->get_id() )->get_status() ) {
	throw new RuntimeException( 'A dispute arriving before paid completion did not hold native acquisition.' );
}
$pending_paid = new WP_REST_Request( 'PUT' );
$pending_paid->set_param( 'id', $pending->get_id() );
$pending_paid->set_header( 'Content-Type', 'application/json' );
$pending_paid->set_body( wp_json_encode( array_merge( $pending_identity, array( 'action' => 'paid' ) ) ) );
$refused_paid = $payments->apply( $pending_paid );
if ( ! is_wp_error( $refused_paid ) || 'payment_order_closed' !== $refused_paid->get_error_code() ) {
	throw new RuntimeException( 'A late paid result reopened a disputed native order.' );
}
$pending_dispute->set_body( wp_json_encode( array_merge( $pending_identity, array( 'disputes' => array( array( 'id' => 'dp_beforePaid' . $pending->get_id(), 'status' => 'lost' ) ) ) ) ) );
if ( 200 !== rest_do_request( $pending_dispute )->get_status() || wc_get_order( $pending->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'A lost dispute restored native delivery.' );
}
$pending_dispute->set_body( wp_json_encode( array_merge( $pending_identity, array( 'disputes' => array( array( 'id' => 'dp_beforePaid' . $pending->get_id(), 'status' => 'won' ) ) ) ) ) );
if ( 409 !== rest_do_request( $pending_dispute )->get_status() || 'on-hold' !== wc_get_order( $pending->get_id() )->get_status() ) {
	throw new RuntimeException( 'A changed terminal dispute outcome restored a lost native order.' );
}
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
echo "PASS: native dispute suspend/restore preserves grants, operator changes and refund precedence; verified refund imports persist once; partial refund preserves native paid access; full refund revokes it; late payment cannot reopen it\n";
