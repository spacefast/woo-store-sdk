<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Parent-owned financial commands survive Woo deleting a failed temporary refund. */
final class Refunds {
	private static ?array $gateway_command = null;
	public function __construct( private Store $store ) {}

	public function register(): void {
		add_action( 'rest_api_init', function (): void {
			register_rest_route( 'spacefast-commerce/v1', '/orders/(?P<id>[1-9][0-9]*)/refund', array(
				'methods' => 'POST', 'permission_callback' => array( $this->store, 'authorize' ), 'callback' => array( $this, 'create' ),
			) );
		} );
	}

	/** Merchant recovery reads the captured command, including succeeded money awaiting accounting. */
	public static function pending( \WC_Order $order ): ?array {
		$commands = $order->get_meta( '_spacefast_refund_requests' );
		foreach ( is_array( $commands ) ? $commands : array() as $command ) {
			if ( ! in_array( $command['status'], array( 'failed', 'canceled', 'refused' ), true ) && empty( $command['accounted'] ) ) {
				return array_intersect_key( $command, array_flip( array( 'request_id', 'amount', 'reason', 'refund_application_fee', 'status' ) ) );
			}
		}
		return null;
	}

	private function error( string $message, int $status = 409 ): \WP_Error {
		return new \WP_Error( 'order_refund_unavailable', $message, array( 'status' => $status ) );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'request_id', 'amount', 'reason', 'refund_application_fee' ) ) ||
			! is_string( $input['request_id'] ?? null ) || ! wp_is_uuid( $input['request_id'] ) ||
			! is_string( $input['amount'] ?? null ) || ! preg_match( '/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/D', $input['amount'] ) || (float) $input['amount'] <= 0 ||
			! is_bool( $input['refund_application_fee'] ?? null ) || ! is_string( $input['reason'] ?? null ) || strlen( mb_convert_encoding( $input['reason'], 'UTF-16LE', 'UTF-8' ) ) > 1000 ) {
			return $this->error( 'A refund request ID, positive amount, reason and explicit fee decision are required.', 422 );
		}
		$input['reason'] = sanitize_text_field( $input['reason'] );
		global $wpdb;
		$lock = 'sf_pay_' . substr( hash( 'sha256', $this->store->binding()['store_id'] . ':' . $request['id'] ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return $this->error( 'Order is busy. Retry the same refund request.' );
		}
		try {
			$snapshot = ( new PaymentOrders( $this->store ) )->for_refund( (int) $request['id'] );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$order = wc_get_order( $snapshot['order_id'] );
			$commands = $order->get_meta( '_spacefast_refund_requests' );
			$commands = is_array( $commands ) ? $commands : array();
			$id = $input['request_id'];
			if ( isset( $commands[$id] ) ) {
				$command = $commands[$id];
				foreach ( array( 'amount', 'reason', 'refund_application_fee' ) as $field ) {
					if ( $command[$field] !== $input[$field] ) {
						return $this->error( 'Retry the captured refund amount, reason and fee decision.' );
					}
				}
				foreach ( array( 'order_id', 'space_id', 'store_id', 'environment', 'attempt_id', 'total', 'currency', 'intent_id' ) as $field ) {
					if ( $snapshot[$field] !== $command['payment'][$field] ) {
						return $this->error( 'The captured refund payment identity changed.' );
					}
				}
			} else {
				foreach ( $commands as $previous ) {
					if ( ! in_array( $previous['status'], array( 'failed', 'canceled', 'refused' ), true ) && empty( $previous['accounted'] ) ) {
						return $this->error( 'Retry the unresolved refund request before starting another.' );
					}
				}
				if ( count( $commands ) >= 100 || ! $order->is_paid() || PaymentOrders::delivery_suspended( $order ) ||
					$order->get_transaction_id() !== $snapshot['intent_id'] || '' === $snapshot['intent_id'] ||
					wc_add_number_precision( (float) $input['amount'] ) > wc_add_number_precision( (float) $order->get_remaining_refund_amount() ) ) {
					return $this->error( 'This paid order cannot refund the requested amount.' );
				}
				$command = array_merge( $input, array( 'payment' => $snapshot, 'status' => 'unknown' ) );
				$commands[$id] = $command;
				$order->update_meta_data( '_spacefast_refund_requests', $commands );
				$order->save();
			}
			// Resolve money before constructing a temporary native row. A webhook may already have imported it.
			$resolved = $this->resolve( $order, $command );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$command = $resolved;
			$order = wc_get_order( $order->get_id() );
			$native = null;
			foreach ( $order->get_refunds() as $existing ) {
				if ( $existing->get_meta( '_spacefast_stripe_refund' ) === $command['refund_id'] ) {
					if ( null !== $native || wc_format_decimal( $existing->get_amount(), 2 ) !== $command['amount'] || $existing->get_currency() !== $snapshot['currency'] ) {
						return $this->error( 'Native refund accounting conflicts with the captured provider refund.' );
					}
					$native = $existing;
				}
			}
			if ( null === $native ) {
				$bind = static function ( \WC_Order_Refund $refund, array $args ) use ( $order, $command ): void {
					if ( $refund->get_parent_id() === $order->get_id() && ! empty( $args['refund_payment'] ) ) {
						$refund->update_meta_data( '_spacefast_refund_request', $command['request_id'] );
						$refund->update_meta_data( '_spacefast_stripe_refund', $command['refund_id'] );
					}
				};
				self::$gateway_command = array( 'order_id' => $order->get_id(), 'request_id' => $id );
				add_action( 'woocommerce_create_refund', $bind, 10, 2 );
				try {
					$native = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $command['amount'],
						'reason' => $command['reason'], 'refund_payment' => true ) );
				} finally {
					self::$gateway_command = null;
					remove_action( 'woocommerce_create_refund', $bind, 10 );
				}
				if ( is_wp_error( $native ) ) {
					return $this->error( 'Provider refund is recorded. Retry the same request to finish native accounting.', 503 );
				}
			}
			$order = wc_get_order( $order->get_id() );
			if ( $order->get_remaining_refund_amount() <= 0 && ! $order->has_status( 'refunded' ) ) {
				$order->update_status( 'refunded' );
			}
			$commands = $order->get_meta( '_spacefast_refund_requests' );
			$commands[$id]['accounted'] = true;
			$order->update_meta_data( '_spacefast_refund_requests', $commands );
			$order->save();
			return new \WP_REST_Response( array( 'data' => array(
				'order_id' => $order->get_id(), 'refund_id' => $native->get_id(), 'payment_refund_id' => $command['refund_id'],
				'request_id' => $id, 'status' => 'refunded', 'amount' => $command['amount'],
				'refund_application_fee' => $command['refund_application_fee'], 'refunded_total' => wc_format_decimal( $order->get_total_refunded(), 2 ),
			) ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function resolve( \WC_Order $order, array $command ): array|\WP_Error {
		if ( 'succeeded' === $command['status'] && isset( $command['refund_id'] ) ) {
			return $command;
		}
		if ( in_array( $command['status'], array( 'failed', 'canceled', 'refused' ), true ) ) {
			return $this->error( 'This refund request was refused or failed. Review the order before starting another.' );
		}
		if ( ! defined( 'SPACEFAST_COMMERCE_API_ORIGIN' ) || ! is_string( SPACEFAST_COMMERCE_API_ORIGIN ) ||
			'https' !== wp_parse_url( SPACEFAST_COMMERCE_API_ORIGIN, PHP_URL_SCHEME ) ||
			! defined( 'SPACEFAST_COMMERCE_API_CREDENTIAL' ) || ! is_string( SPACEFAST_COMMERCE_API_CREDENTIAL ) ||
			! preg_match( '/^[A-Za-z0-9_-]{32,256}$/D', SPACEFAST_COMMERCE_API_CREDENTIAL ) ) {
			return $this->error( 'Refund transport is unavailable. Retry the same request after configuration is restored.', 503 );
		}
		$binding = $this->store->binding();
		$response = wp_remote_post( rtrim( SPACEFAST_COMMERCE_API_ORIGIN, '/' ) . '/commerce/refunds', array(
			'timeout' => 35, 'redirection' => 0,
			'headers' => array( 'Authorization' => 'Bearer ' . SPACEFAST_COMMERCE_API_CREDENTIAL, 'Content-Type' => 'application/json',
				'X-Spacefast-Space-Id' => $binding['space_id'], 'X-Spacefast-Store-Id' => $binding['store_id'], 'X-Spacefast-Environment' => $binding['environment'] ),
			'body' => wp_json_encode( array_merge( $command['payment'], array_intersect_key( $command, array_flip( array( 'request_id', 'amount', 'reason', 'refund_application_fee' ) ) ) ) ),
		) );
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		// An arbitrary refusal can follow a provider effect; only the API's explicit pre-money proof releases this command.
		if ( ! is_wp_error( $response ) && 409 === wp_remote_retrieve_response_code( $response ) && is_array( $data ) &&
			'conflict' === ( $data['code'] ?? null ) && ( $data['details']['refundRequestId'] ?? null ) === $command['request_id'] &&
			'not_created' === ( $data['details']['refundState'] ?? null ) ) {
			$command['status'] = 'refused';
			$commands = $order->get_meta( '_spacefast_refund_requests' );
			$commands[$command['request_id']] = $command;
			$order->update_meta_data( '_spacefast_refund_requests', $commands );
			$order->save();
			return $this->error( 'The provider refused this refund before creating it. Review the order before starting another.' );
		}
		$data = is_array( $data ) ? ( $data['data'] ?? null ) : null;
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ||
			! is_string( $data['refund_id'] ?? null ) || ! preg_match( '/^re_[A-Za-z0-9]+$/D', $data['refund_id'] ) ||
			( $data['amount'] ?? null ) !== $command['amount'] || ( $data['refund_application_fee'] ?? null ) !== $command['refund_application_fee'] ||
			! in_array( $data['status'] ?? null, array( 'succeeded', 'pending', 'requires_action', 'failed', 'canceled' ), true ) ||
			( isset( $command['refund_id'] ) && $command['refund_id'] !== $data['refund_id'] ) ) {
			return $this->error( 'Refund result is unresolved. Retry the same request.', 503 );
		}
		$command['refund_id'] = $data['refund_id'];
		$command['status'] = $data['status'];
		$commands = $order->get_meta( '_spacefast_refund_requests' );
		$commands[$command['request_id']] = $command;
		$order->update_meta_data( '_spacefast_refund_requests', $commands );
		$order->save();
		return 'succeeded' === $command['status'] ? $command : $this->error( 'Refund has not succeeded. Retry the same request.', in_array( $command['status'], array( 'failed', 'canceled' ), true ) ? 409 : 503 );
	}

	/** Stock Woo can acknowledge only the exact durable command resolved by the authenticated route. */
	public function gateway_result( int $id, $amount, $reason ): bool|\WP_Error {
		$snapshot = ( new PaymentOrders( $this->store ) )->for_refund( $id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$order = wc_get_order( $id );
		$commands = $order->get_meta( '_spacefast_refund_requests' );
		$active = self::$gateway_command['request_id'] ?? '';
		$command = is_array( $commands ) ? ( $commands[$active] ?? null ) : null;
		if ( ( self::$gateway_command['order_id'] ?? null ) !== $id || ! is_array( $command ) || 'succeeded' !== $command['status'] || ! isset( $command['refund_id'] ) ||
			wc_format_decimal( $amount, 2 ) !== $command['amount'] || $reason !== $command['reason'] ) {
			return $this->error( 'Use the authenticated order refund action with an explicit fee decision.' );
		}
		return true;
	}
}
