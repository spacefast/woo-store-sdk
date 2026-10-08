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
$full = wc_create_refund( array(
	'order_id' => $order->get_id(), 'amount' => (string) ( (float) $order->get_total() - 1 ),
	'reason' => 'Local native full refund contract', 'refund_payment' => false,
	'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => (string) ( (float) $item->get_total() - 1 ) ) ),
) );
if ( is_wp_error( $full ) || wc_get_order( $order->get_id() )->is_download_permitted() ) {
	throw new RuntimeException( 'Native full refund retained paid access.' );
}
echo "PASS: native partial refund preserves paid access; native full refund revokes it\n";
