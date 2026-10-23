<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Native order jobs recover missed provider notifications without creating payments. */
final class Reconciliation {
	public function __construct( private Store $store ) {}

	public function register(): void {
		add_action( 'woocommerce_after_order_object_save', array( $this, 'schedule' ) );
		add_action( 'spacefast_commerce_reconcile_payment', array( $this, 'run' ), 10, 2 );
	}

	public function schedule( \WC_Order $order ): void {
		if ( ! $this->owns( $order ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action( time() + 60, 'spacefast_commerce_reconcile_payment',
			array( $order->get_id(), $order->get_meta( '_spacefast_payment_attempt' ) ), 'spacefast-commerce', true );
	}

	private function owns( \WC_Order $order ): bool {
		if ( $order instanceof \WC_Order_Refund || 'spacefast_connect' !== $order->get_payment_method() ||
			'' === $order->get_meta( '_spacefast_payment_requested_at' ) || '' === $order->get_meta( '_spacefast_payment_attempt' ) ) {
			return false;
		}
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			if ( $order->get_meta( '_spacefast_' . $field ) !== ( $this->store->binding()[$field] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	public function run( int $order_id, string $attempt_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->owns( $order ) || $attempt_id !== $order->get_meta( '_spacefast_payment_attempt' ) ) {
			return;
		}
		$args = array( $order_id, $attempt_id );
		try {
			$binding = $this->store->binding();
			if ( ! defined( 'SPACEFAST_COMMERCE_API_ORIGIN' ) || ! defined( 'SPACEFAST_COMMERCE_API_CREDENTIAL' ) ||
				! is_string( SPACEFAST_COMMERCE_API_ORIGIN ) || ! is_string( SPACEFAST_COMMERCE_API_CREDENTIAL ) ||
				'https' !== wp_parse_url( SPACEFAST_COMMERCE_API_ORIGIN, PHP_URL_SCHEME ) ) {
				throw new \RuntimeException( 'Payment reconciliation needs the authenticated API connection.' );
			}
			$response = wp_remote_post( rtrim( SPACEFAST_COMMERCE_API_ORIGIN, '/' ) . '/commerce/reconcile', array(
				'timeout' => 20, 'redirection' => 0,
				'headers' => array(
					'Authorization' => 'Bearer ' . SPACEFAST_COMMERCE_API_CREDENTIAL, 'Content-Type' => 'application/json',
					'X-Spacefast-Space-Id' => $binding['space_id'], 'X-Spacefast-Store-Id' => $binding['store_id'],
					'X-Spacefast-Environment' => $binding['environment'],
				),
				'body' => wp_json_encode( array( 'order_id' => $order_id, 'attempt_id' => $attempt_id ) ),
			) );
			$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ||
				! is_string( $data['data']['operation_id'] ?? null ) || ! preg_match( '/^op_[A-Za-z0-9]+$/D', $data['data']['operation_id'] ) ) {
				throw new \RuntimeException( 'Payment reconciliation was not accepted. The native attempt will retry.' );
			}
		} finally {
			// The current action is running, so uniqueness would suppress its successor.
			$pending = as_get_scheduled_actions( array( 'hook' => 'spacefast_commerce_reconcile_payment',
				'args' => $args, 'group' => 'spacefast-commerce', 'status' => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1 ), 'ids' );
			if ( ! $pending ) {
				as_schedule_single_action( time() + ( $order->needs_payment() ? 300 : 3600 ),
					'spacefast_commerce_reconcile_payment', $args, 'spacefast-commerce' );
			}
		}
	}
}
