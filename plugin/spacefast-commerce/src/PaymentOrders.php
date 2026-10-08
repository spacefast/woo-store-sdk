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
			register_rest_route( 'spacefast-commerce/v1', '/orders/(?P<id>[1-9][0-9]*)/payment/refunds', array(
				'methods' => 'PUT', 'permission_callback' => array( $this->store, 'authorize' ), 'callback' => array( $this, 'apply_refunds' ),
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

	private function order( int $id, bool $acquisition = false ): \WC_Order|\WP_Error {
		$order = wc_get_order( $id );
		if ( ! $order || $order instanceof \WC_Order_Refund || 'spacefast_connect' !== $order->get_payment_method() ) {
			return new \WP_Error( 'payment_order_not_found', 'Payment order not found.', array( 'status' => 404 ) );
		}
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			if ( $order->get_meta( '_spacefast_' . $field ) !== ( $this->store->binding()[$field] ?? null ) ) {
				return new \WP_Error( 'payment_order_not_found', 'Payment order not found.', array( 'status' => 404 ) );
			}
		}
		if ( '' === $order->get_meta( '_spacefast_payment_attempt' ) || (float) $order->get_total() <= 0 ) {
			return new \WP_Error( 'payment_order_invalid', 'A positive managed payment order is required.', array( 'status' => 409 ) );
		}
		// Only new acquisition depends on today's catalog and merchant currency.
		// Verified results belong to the captured order, even after source removal.
		if ( $acquisition && $order->needs_payment() ) {
			$items = $order->get_items();
			$item = current( $items );
			if ( 1 !== count( $items ) || 1 !== $item->get_quantity() ||
				'' === get_post_meta( $item->get_product_id(), '_spacefast_product_key', true ) ||
				get_post_meta( $item->get_product_id(), '_spacefast_space_id', true ) !== $this->store->binding()['space_id'] ||
				$order->get_currency() !== $this->store->binding()['currency'] ) {
				return new \WP_Error( 'payment_order_invalid', 'A single-product order from the current managed catalog is required.', array( 'status' => 409 ) );
			}
		}
		return $order;
	}

	public function snapshot( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = $this->order( (int) $request['id'], true );
		return is_wp_error( $order ) ? $order : new \WP_REST_Response( array( 'data' => $this->receipt( $order ) ) );
	}

	/** The gateway reads the same trusted native snapshot without an HTTP loopback. */
	public function for_gateway( int $id ): array|\WP_Error {
		$order = $this->order( $id, true );
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
			$order = $this->order( (int) $request['id'], 'bind_intent' === $input['action'] );
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
			if ( ( 'bind_intent' === $input['action'] && ! $order->needs_payment() ) ||
				( 'paid' === $input['action'] && ( ( $order->is_paid() && $order->get_transaction_id() !== $intent ) ||
					( ! $order->is_paid() && ! $order->needs_payment() ) ) ) ) {
				return new \WP_Error( 'payment_order_closed', 'This native order no longer accepts this payment result.', array( 'status' => 409 ) );
			}
			if ( $order->needs_payment() ) {
				// A verified API result can arrive after Woo lost the setup response.
				// Bind and synchronize under this same lock before native completion.
				$order->update_meta_data( '_spacefast_payment_intent', $input['intent_id'] );
				$order->update_meta_data( '_spacefast_payment_total', $receipt['total'] );
				$order->update_meta_data( '_spacefast_payment_currency', $receipt['currency'] );
				$order->save();
			}
			if ( 'paid' === $input['action'] && ! $order->is_paid() ) {
				$order->payment_complete( $input['intent_id'] );
			}
			return new \WP_REST_Response( array( 'data' => $this->receipt( $order ) ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	/** Import API-verified successful Stripe refunds without initiating another payment refund. */
	public function apply_refunds( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'attempt_id', 'intent_id', 'total', 'currency', 'refunds' ) ) ||
			! is_string( $input['intent_id'] ?? null ) || ! preg_match( '/^pi_[A-Za-z0-9]+$/D', $input['intent_id'] ) ||
			! is_array( $input['refunds'] ?? null ) || ! array_is_list( $input['refunds'] ) || count( $input['refunds'] ) < 1 || count( $input['refunds'] ) > 100 ) {
			return new \WP_Error( 'payment_refunds_invalid', 'Verified payment refunds are required.', array( 'status' => 422 ) );
		}
		$refunds = array();
		foreach ( $input['refunds'] as $refund ) {
			if ( ! is_array( $refund ) || array_diff( array_keys( $refund ), array( 'id', 'amount' ) ) ||
				! is_string( $refund['id'] ?? null ) || ! preg_match( '/^re_[A-Za-z0-9]+$/D', $refund['id'] ) ||
				isset( $refunds[$refund['id']] ) || ! is_string( $refund['amount'] ?? null ) ||
				! preg_match( '/^(?:0|[1-9][0-9]{0,9})\.[0-9]{2}$/D', $refund['amount'] ) || wc_add_number_precision( (float) $refund['amount'] ) <= 0 ) {
				return new \WP_Error( 'payment_refunds_invalid', 'Each verified refund needs a unique ID and positive native amount.', array( 'status' => 422 ) );
			}
			$refunds[$refund['id']] = $refund['amount'];
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
					return new \WP_Error( 'payment_result_conflict', 'Refund result does not match the captured native order.', array( 'status' => 409 ) );
				}
			}
			if ( ( '' !== $receipt['intent_id'] && $receipt['intent_id'] !== $input['intent_id'] ) ||
				( '' !== $order->get_transaction_id() && $order->get_transaction_id() !== $input['intent_id'] ) ) {
				return new \WP_Error( 'payment_intent_conflict', 'Refund result does not match the bound payment intent.', array( 'status' => 409 ) );
			}
			$pending = $refunds;
			$seen = array();
			foreach ( $order->get_refunds() as $native ) {
				$id = $native->get_meta( '_spacefast_stripe_refund' );
				if ( ! isset( $refunds[$id] ) ) {
					continue;
				}
				if ( isset( $seen[$id] ) || wc_format_decimal( $native->get_amount(), 2 ) !== $refunds[$id] || $native->get_currency() !== $receipt['currency'] ) {
					return new \WP_Error( 'payment_refund_conflict', 'A native refund already records a different provider result.', array( 'status' => 409 ) );
				}
				$seen[$id] = true;
				unset( $pending[$id] );
			}
			$amount = array_sum( array_map( static fn ( string $value ): float => wc_add_number_precision( (float) $value ), $pending ) );
			$remaining = wc_add_number_precision( (float) $order->get_remaining_refund_amount() );
			if ( $amount > $remaining || ( $amount > 0 && ! $order->is_paid() && $amount < $remaining ) ) {
				return new \WP_Error( 'payment_refund_conflict', 'Verified refunds do not match the native refundable payment state.', array( 'status' => 409 ) );
			}
			if ( '' === $receipt['intent_id'] && $amount > 0 ) {
				// A verified full refund can precede the lost intent-setup response and paid relay.
				$order->update_meta_data( '_spacefast_payment_intent', $input['intent_id'] );
				$order->update_meta_data( '_spacefast_payment_total', $receipt['total'] );
				$order->update_meta_data( '_spacefast_payment_currency', $receipt['currency'] );
				$order->save();
			}
			foreach ( $pending as $id => $value ) {
				// Attach identity before Woo's first save, so a lost HTTP response cannot duplicate a refund.
				$bind = static function ( \WC_Order_Refund $refund ) use ( $id, $order ): void {
					if ( $refund->get_parent_id() === $order->get_id() ) {
						$refund->update_meta_data( '_spacefast_stripe_refund', $id );
					}
				};
				add_action( 'woocommerce_create_refund', $bind );
				try {
					$native = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $value,
						'reason' => 'Verified Stripe refund ' . $id, 'refund_payment' => false ) );
				} finally {
					remove_action( 'woocommerce_create_refund', $bind );
				}
				if ( is_wp_error( $native ) ) {
					return new \WP_Error( 'payment_refund_unavailable', 'Native refund could not be recorded. Retry the same result.', array( 'status' => 503 ) );
				}
			}
			$order = wc_get_order( $order->get_id() );
			// Recover a process lost after refund persistence but before Woo's status transition.
			if ( $order->get_total_refunded() > 0 && $order->get_remaining_refund_amount() <= 0 && ! $order->has_status( 'refunded' ) ) {
				$order->update_status( 'refunded' );
			}
			return new \WP_REST_Response( array( 'data' => array_merge( $this->receipt( $order ), array(
				'refunded_total' => wc_format_decimal( $order->get_total_refunded(), 2 ),
				'refunds' => array_map( static fn ( string $id, string $amount ): array => array( 'id' => $id, 'amount' => $amount ), array_keys( $refunds ), array_values( $refunds ) ),
			) ) ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

}
