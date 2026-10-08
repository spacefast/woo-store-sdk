<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Trusted native order snapshots and verified-result application, never a buyer payment API. */
final class PaymentOrders {
	public function __construct( private Store $store ) {}

	public function register(): void {
		add_action( 'woocommerce_before_order_object_save', array( $this, 'bind_order' ) );
		add_action( 'rest_api_init', function (): void {
			register_rest_route( 'spacefast-commerce/v1', '/orders/(?P<id>[1-9][0-9]*)/payment', array(
				array( 'methods' => 'GET', 'permission_callback' => array( $this->store, 'authorize' ), 'callback' => array( $this, 'snapshot' ) ),
				array( 'methods' => 'PUT', 'permission_callback' => array( $this->store, 'authorize' ), 'callback' => array( $this, 'apply' ) ),
			) );
		} );
	}

	/** Woo persists payment identity in both CPT and HPOS before calling the gateway. */
	public function bind_order( \WC_Order $order ): void {
		$binding = $this->store->binding();
		if ( array() === $binding || $order instanceof \WC_Order_Refund || 'spacefast_connect' !== $order->get_payment_method() ) {
			return;
		}
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			$key = '_spacefast_' . $field;
			$existing = $order->get_meta( $key );
			if ( '' !== $existing && $binding[$field] !== $existing ) {
				throw new \WC_Data_Exception( 'payment_order_scope_conflict', 'The payment order belongs to another store or environment.' );
			}
			$order->update_meta_data( $key, $binding[$field] );
		}
		if ( '' === $order->get_meta( '_spacefast_payment_attempt' ) ) {
			$order->update_meta_data( '_spacefast_payment_attempt', wp_generate_uuid4() );
		}
	}

	private function order( int $id ): \WC_Order|\WP_Error {
		$order = wc_get_order( $id );
		if ( ! $order || $order instanceof \WC_Order_Refund || 'spacefast_connect' !== $order->get_payment_method() ) {
			return new \WP_Error( 'payment_order_not_found', 'Payment order not found.', array( 'status' => 404 ) );
		}
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			if ( $order->get_meta( '_spacefast_' . $field ) !== ( $this->store->binding()[$field] ?? null ) ) {
				return new \WP_Error( 'payment_order_not_found', 'Payment order not found.', array( 'status' => 404 ) );
			}
		}
		$items = $order->get_items();
		$item = current( $items );
		if ( 1 !== count( $items ) || 1 !== $item->get_quantity() ||
			'' === get_post_meta( $item->get_product_id(), '_spacefast_product_key', true ) ||
			get_post_meta( $item->get_product_id(), '_spacefast_space_id', true ) !== $this->store->binding()['space_id'] ||
			'' === $order->get_meta( '_spacefast_payment_attempt' ) ||
			$order->get_currency() !== $this->store->binding()['currency'] || (float) $order->get_total() <= 0 ) {
			return new \WP_Error( 'payment_order_invalid', 'A positive single-product managed order is required.', array( 'status' => 409 ) );
		}
		return $order;
	}

	public function snapshot( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = $this->order( (int) $request['id'] );
		return is_wp_error( $order ) ? $order : new \WP_REST_Response( array( 'data' => $this->receipt( $order ) ) );
	}

	/** The gateway reads the same trusted native snapshot without an HTTP loopback. */
	public function for_gateway( int $id ): array|\WP_Error {
		$order = $this->order( $id );
		return is_wp_error( $order ) ? $order : $this->receipt( $order );
	}

	private function receipt( \WC_Order $order ): array {
		return array(
			'order_id' => $order->get_id(),
			'space_id' => $order->get_meta( '_spacefast_space_id' ),
			'store_id' => $order->get_meta( '_spacefast_store_id' ),
			'environment' => $order->get_meta( '_spacefast_environment' ),
			'attempt_id' => $order->get_meta( '_spacefast_payment_attempt' ),
			'total' => $order->get_total(), 'currency' => $order->get_currency(),
			'intent_id' => $order->get_meta( '_spacefast_payment_intent' ),
			'paid' => $order->is_paid(), 'needs_payment' => $order->needs_payment(), 'status' => $order->get_status(),
		);
	}

	/** The API verifies Stripe first; this boundary authenticates its exact store credential. */
	public function apply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'action', 'attempt_id', 'intent_id', 'total', 'currency' ) ) ||
			! in_array( $input['action'] ?? null, array( 'bind_intent', 'paid' ), true ) ||
			! is_string( $input['intent_id'] ?? null ) || ! preg_match( '/^pi_[A-Za-z0-9]+$/D', $input['intent_id'] ) ) {
			return new \WP_Error( 'payment_result_invalid', 'A bound payment result is required.', array( 'status' => 422 ) );
		}
		global $wpdb;
		$lock = 'sf_pay_' . substr( hash( 'sha256', $this->store->binding()['store_id'] . ':' . $request['id'] ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return new \WP_Error( 'payment_order_busy', 'Payment order is busy. Retry the same result.', array( 'status' => 409 ) );
		}
		try {
			$order = $this->order( (int) $request['id'] );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$receipt = $this->receipt( $order );
			foreach ( array( 'attempt_id', 'total', 'currency' ) as $field ) {
				if ( ( $input[$field] ?? null ) !== $receipt[$field] ) {
					return new \WP_Error( 'payment_result_conflict', 'Payment result does not match the current native order.', array( 'status' => 409 ) );
				}
			}
			$intent = $receipt['intent_id'];
			if ( '' !== $intent && $intent !== $input['intent_id'] ) {
				return new \WP_Error( 'payment_intent_conflict', 'Another payment intent is bound to this order.', array( 'status' => 409 ) );
			}
			if ( 'bind_intent' === $input['action'] ) {
				if ( ! $order->needs_payment() ) {
					return new \WP_Error( 'payment_order_closed', 'This native order no longer accepts payment.', array( 'status' => 409 ) );
				}
				$order->update_meta_data( '_spacefast_payment_intent', $input['intent_id'] );
				$order->update_meta_data( '_spacefast_payment_total', $receipt['total'] );
				$order->update_meta_data( '_spacefast_payment_currency', $receipt['currency'] );
				$order->save();
			} else {
				if ( '' === $intent || $order->get_meta( '_spacefast_payment_total' ) !== $receipt['total'] ||
					$order->get_meta( '_spacefast_payment_currency' ) !== $receipt['currency'] ||
					( $order->is_paid() && $order->get_transaction_id() !== $intent ) ||
					( ! $order->is_paid() && ! $order->needs_payment() ) ) {
					return new \WP_Error( 'payment_order_closed', 'The verified result cannot complete this native order.', array( 'status' => 409 ) );
				}
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $intent );
				}
			}
			return new \WP_REST_Response( array( 'data' => $this->receipt( $order ) ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
