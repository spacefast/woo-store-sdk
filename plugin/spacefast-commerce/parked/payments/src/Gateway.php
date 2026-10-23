<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Card details stay in Stripe-hosted fields; only the API has platform secrets. */
final class Gateway extends \WC_Payment_Gateway {
	public function __construct() {
		$this->id = 'spacefast_connect';
		$this->method_title = 'Spacefast payments';
		$this->method_description = 'Payments through your connected Stripe account.';
		$this->title = 'Card';
		$this->description = 'Pay securely with Stripe.';
		$this->has_fields = false;
		$this->supports = array( 'products', 'refunds' );
		$this->enabled = 'yes';
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	public function is_available(): bool {
		return parent::is_available() && array() !== ( new Store() )->binding() &&
			defined( 'SPACEFAST_COMMERCE_API_ORIGIN' ) && defined( 'SPACEFAST_COMMERCE_API_CREDENTIAL' ) &&
			is_string( SPACEFAST_COMMERCE_API_ORIGIN ) && is_string( SPACEFAST_COMMERCE_API_CREDENTIAL ) &&
			boolval( preg_match( '/^[A-Za-z0-9_-]{32,256}$/D', SPACEFAST_COMMERCE_API_CREDENTIAL ) ) &&
			'https' === wp_parse_url( SPACEFAST_COMMERCE_API_ORIGIN, PHP_URL_SCHEME );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return ( new Refunds( new Store() ) )->gateway_result( (int) $order_id, $amount, $reason );
	}

	public function process_payment( $order_id ): array {
		$store = new Store();
		$binding = $store->binding();
		$payments = new PaymentOrders( $store );
		$snapshot = $payments->for_gateway( (int) $order_id );
		if ( ! $this->is_available() || is_wp_error( $snapshot ) || ! $snapshot['needs_payment'] ) {
			wc_add_notice( 'Payments are unavailable for this order.', 'error' );
			return array( 'result' => 'failure' );
		}
		$order = wc_get_order( $order_id );
		$order->update_meta_data( '_spacefast_payment_requested_at', gmdate( 'c' ) );
		$order->save();
		// This body is constructed in Woo, never forwarded from browser payment data.
		$response = wp_remote_post( rtrim( SPACEFAST_COMMERCE_API_ORIGIN, '/' ) . '/commerce/payments', array(
			'timeout' => 35, 'redirection' => 0,
			'headers' => array(
				'Authorization' => 'Bearer ' . SPACEFAST_COMMERCE_API_CREDENTIAL, 'Content-Type' => 'application/json',
				'X-Spacefast-Space-Id' => $binding['space_id'], 'X-Spacefast-Store-Id' => $binding['store_id'],
				'X-Spacefast-Environment' => $binding['environment'],
			),
			'body' => wp_json_encode( $snapshot ),
		) );
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? ( $data['data'] ?? null ) : null;
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ||
			! is_string( $data['intent_id'] ?? null ) || ! preg_match( '/^pi_[A-Za-z0-9]+$/D', $data['intent_id'] ) ||
			! is_string( $data['client_secret'] ?? null ) || ! preg_match( '/^' . preg_quote( $data['intent_id'], '/' ) . '_secret_[A-Za-z0-9]+$/D', $data['client_secret'] ) ||
			! is_string( $data['account_id'] ?? null ) || ! preg_match( '/^acct_[A-Za-z0-9]+$/D', $data['account_id'] ) ||
			! is_string( $data['publishable_key'] ?? null ) || ! preg_match( '/^pk_' . $binding['environment'] . '_[A-Za-z0-9]+$/D', $data['publishable_key'] ) ) {
			wc_add_notice( 'Payment setup is taking longer than expected. Retry this order.', 'error' );
			return array( 'result' => 'failure' );
		}
		$request = new \WP_REST_Request( 'PUT' );
		$request->set_param( 'id', (int) $order_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'action' => 'bind_intent', 'attempt_id' => $snapshot['attempt_id'], 'intent_id' => $data['intent_id'],
			'total' => $snapshot['total'], 'currency' => $snapshot['currency'],
		) ) );
		$result = $payments->apply( $request );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( 'This order changed. Review it and retry payment.', 'error' );
			return array( 'result' => 'failure' );
		}
		$order = wc_get_order( $order_id );
		$order->update_meta_data( '_spacefast_payment_confirmation', array_intersect_key( $data, array_flip( array( 'intent_id', 'client_secret', 'account_id', 'publishable_key' ) ) ) );
		$order->save();
		return array( 'result' => 'success', 'redirect' => $order->get_checkout_payment_url( true ) );
	}

	/** Woo validates guest order-pay access; retain that check at the confirmation boundary. */
	public function receipt_page( $order_id ): void {
		$order = wc_get_order( $order_id );
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order || ! $order->needs_payment() || ! hash_equals( $order->get_order_key(), $key ) ||
			is_wp_error( ( new PaymentOrders( new Store() ) )->for_gateway( (int) $order_id ) ) ) {
			return;
		}
		$data = $order->get_meta( '_spacefast_payment_confirmation' );
		if ( ! is_array( $data ) || empty( $data['client_secret'] ) ) {
			return;
		}
		wp_enqueue_script( 'spacefast-stripe', 'https://js.stripe.com/v3/', array(), null, true );
		wp_enqueue_script( 'spacefast-payment', plugins_url( 'assets/payment.js', dirname( __DIR__ ) . '/spacefast-commerce.php' ), array( 'spacefast-stripe' ), '0.1.0', true );
		wp_add_inline_script( 'spacefast-payment', 'window.spacefastPayment=' . wp_json_encode( array(
			'clientSecret' => $data['client_secret'], 'accountId' => $data['account_id'],
			'publishableKey' => $data['publishable_key'], 'returnUrl' => $order->get_checkout_order_received_url(),
		), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		echo '<form id="spacefast-payment"><div id="spacefast-payment-element"></div><p id="spacefast-payment-error" role="alert"></p><button type="submit">Pay now</button></form>';
	}
}
