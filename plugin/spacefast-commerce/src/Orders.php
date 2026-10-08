<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;
use Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils;

/** Native Woo order views; no parallel order ledger or current-catalog dependency. */
final class Orders {
	public function __construct( private Store $store ) {}

	public function register(): void {
		add_action( 'woocommerce_before_order_item_object_save', array( $this, 'capture_shipping' ) );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'cpt_scope' ), 10, 2 );
		add_action( 'rest_api_init', function (): void {
			register_rest_route( 'spacefast-commerce/v1', '/orders', array(
				'methods' => 'GET', 'permission_callback' => array( $this->store, 'authorize' ),
				'callback' => array( $this, 'listing' ),
				'args' => array(
					'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
				),
			) );
			register_rest_route( 'spacefast-commerce/v1', '/orders/(?P<id>[1-9][0-9]*)', array(
				'methods' => 'GET', 'permission_callback' => array( $this->store, 'authorize' ), 'callback' => array( $this, 'detail' ),
			) );
			foreach ( array( 'resend', 'ship' ) as $action ) {
				register_rest_route( 'spacefast-commerce/v1', '/orders/(?P<id>[1-9][0-9]*)/' . $action, array(
					'methods' => 'POST', 'permission_callback' => array( $this->store, 'authorize' ),
					'callback' => array( $this, $action ),
				) );
			}
		} );
	}

	/** Keep the purchased shipping requirement when a later source deploy changes product kind. */
	public function capture_shipping( \WC_Order_Item $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || $item->get_id() ) {
			return;
		}
		$product = $item->get_product();
		if ( $product && get_post_meta( $product->get_id(), '_spacefast_space_id', true ) === ( $this->store->binding()['space_id'] ?? null ) ) {
			$item->update_meta_data( '_spacefast_requires_shipping', $product->is_virtual() ? 'no' : 'yes' );
		}
	}

	/** Woo's CPT adapter does not accept HPOS meta_query arguments directly. */
	public function cpt_scope( array $query, array $vars ): array {
		if ( isset( $vars['spacefast_order_scope'] ) ) {
			$query['meta_query'][] = $vars['spacefast_order_scope'];
		}
		return $query;
	}

	public function listing( \WP_REST_Request $request ): \WP_REST_Response {
		$binding = $this->store->binding();
		$scope = array( 'relation' => 'AND' );
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			$scope[] = array( 'key' => '_spacefast_' . $field, 'value' => $binding[$field], 'compare' => '=' );
		}
		$args = array(
			'type' => 'shop_order', 'payment_method' => 'spacefast_connect', 'paginate' => true,
			'limit' => (int) $request['limit'], 'page' => (int) $request['page'], 'orderby' => 'ID', 'order' => 'DESC',
		);
		$key = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'meta_query' : 'spacefast_order_scope';
		$args[$key] = $scope;
		$page = wc_get_orders( $args );
		return new \WP_REST_Response( array( 'data' => array(
			'orders' => array_map( fn ( \WC_Order $order ): array => $this->summary( $order ), $page->orders ),
			'next_page' => (int) $request['page'] < $page->max_num_pages ? (int) $request['page'] + 1 : null,
		) ) );
	}

	private function order( int $id ): \WC_Order|\WP_Error {
		$order = wc_get_order( $id );
		if ( ! $order || $order instanceof \WC_Order_Refund || 'spacefast_connect' !== $order->get_payment_method() ) {
			return new \WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			if ( $order->get_meta( '_spacefast_' . $field ) !== ( $this->store->binding()[$field] ?? null ) ) {
				return new \WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
			}
		}
		return $order;
	}

	public function detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = $this->order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		return new \WP_REST_Response( array( 'data' => array_merge( $this->summary( $order ), array(
			'buyer_email' => $order->get_billing_email(),
			'shipping' => $order->get_address( 'shipping' ),
			'refunded_total' => wc_format_decimal( $order->get_total_refunded(), wc_get_price_decimals() ),
		) ) ) );
	}

	/** Native order-details email; this neither renews nor recreates download permissions. */
	public function resend( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = $this->order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! $order->is_paid() || ! is_email( $order->get_billing_email() ) ) {
			return new \WP_Error( 'order_delivery_unavailable', 'A paid order with a buyer email is required.', array( 'status' => 409 ) );
		}
		$email = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'];
		$sent = false;
		$observe = static function ( bool $result, string $id, \WC_Email $message ) use ( $email, $order, &$sent ): void {
			if ( $message === $email && $message->object instanceof \WC_Order && $message->object->get_id() === $order->get_id() ) {
				$sent = $result;
			}
		};
		add_action( 'woocommerce_email_sent', $observe, 10, 3 );
		try {
			WC()->mailer()->customer_invoice( $order );
		} finally {
			remove_action( 'woocommerce_email_sent', $observe, 10 );
		}
		if ( ! $sent ) {
			return new \WP_Error( 'order_email_failed', 'Native order email was not accepted by the mail transport.', array( 'status' => 503 ) );
		}
		return new \WP_REST_Response( array( 'data' => array( 'order_id' => $order->get_id(), 'status' => 'requested' ) ) );
	}

	/** Shipment data and retry identity live on Woo's native fulfillment record. */
	public function ship( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = $request->get_json_params();
		$url = is_array( $input ) && is_string( $input['tracking_url'] ?? null ) ? wp_parse_url( $input['tracking_url'] ) : false;
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'request_id', 'tracking_number', 'tracking_url' ) ) ||
			! is_string( $input['request_id'] ?? null ) || ! wp_is_uuid( $input['request_id'] ) ||
			! is_string( $input['tracking_number'] ?? null ) || '' === trim( $input['tracking_number'] ) || strlen( $input['tracking_number'] ) > 100 ||
			preg_match( '/[\x00-\x1f\x7f]/', $input['tracking_number'] ) ||
			! is_array( $url ) || 'https' !== ( $url['scheme'] ?? null ) || empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) || strlen( $input['tracking_url'] ) > 2048 ) {
			return new \WP_Error( 'shipment_invalid', 'Supply a request UUID, tracking number and HTTPS tracking URL.', array( 'status' => 422 ) );
		}
		if ( 'yes' !== get_option( 'woocommerce_feature_fulfillments_enabled' ) ) {
			return new \WP_Error( 'fulfillments_unavailable', 'Native fulfillments must be prepared first.', array( 'status' => 503 ) );
		}
		global $wpdb;
		// Serialize with verified payment application before deciding whether paid stock can ship.
		$lock = 'sf_pay_' . substr( hash( 'sha256', $this->store->binding()['store_id'] . ':' . $request['id'] ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return new \WP_Error( 'order_busy', 'Order is busy. Retry the same shipment.', array( 'status' => 409 ) );
		}
		try {
			$order = $this->order( (int) $request['id'] );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$records = \WC_Data_Store::load( 'order-fulfillment' )->read_fulfillments( \WC_Order::class, (string) $order->get_id() );
			foreach ( $records as $record ) {
				if ( $record->get_meta( '_spacefast_shipment_request' ) === $input['request_id'] ) {
					if ( $record->get_tracking_number() !== $input['tracking_number'] || $record->get_tracking_url() !== $input['tracking_url'] ) {
						return new \WP_Error( 'shipment_conflict', 'This shipment request already has different tracking.', array( 'status' => 409 ) );
					}
					return $this->shipment_receipt( $order, $record );
				}
			}
			if ( ! $order->is_paid() ) {
				return new \WP_Error( 'order_not_paid', 'Only a paid native order can be shipped.', array( 'status' => 409 ) );
			}
			$items = array();
			foreach ( FulfillmentUtils::get_pending_items( $order, $records ) as $pending ) {
				if ( 'yes' === $pending['item']->get_meta( '_spacefast_requires_shipping' ) ) {
					$items[] = array( 'item_id' => $pending['item_id'], 'qty' => $pending['qty'] );
				}
			}
			if ( array() === $items ) {
				return new \WP_Error( 'shipment_empty', 'No unshipped physical items remain.', array( 'status' => 409 ) );
			}
			$fulfillment = new Fulfillment();
			$fulfillment->set_entity_type( \WC_Order::class );
			$fulfillment->set_entity_id( (string) $order->get_id() );
			$fulfillment->set_status( 'fulfilled' );
			$fulfillment->set_items( $items );
			$fulfillment->set_tracking_number( $input['tracking_number'] );
			$fulfillment->set_tracking_url( $input['tracking_url'] );
			$fulfillment->update_meta_data( '_spacefast_shipment_request', $input['request_id'] );
			$fulfillment->save();
			// Native fulfillment hooks own order status and customer notification content.
			do_action( 'woocommerce_fulfillment_created_notification', $order->get_id(), $fulfillment, wc_get_order( $order->get_id() ) );
			return $this->shipment_receipt( $order, $fulfillment );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function shipment_receipt( \WC_Order $order, Fulfillment $fulfillment ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'data' => array(
			'order_id' => $order->get_id(), 'fulfillment_id' => $fulfillment->get_id(), 'status' => $fulfillment->get_status(),
			'tracking_number' => $fulfillment->get_tracking_number(), 'tracking_url' => $fulfillment->get_tracking_url(),
		) ) );
	}

	private function summary( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array( 'name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'total' => $item->get_total() );
		}
		return array(
			'order_id' => $order->get_id(), 'number' => $order->get_order_number(), 'status' => $order->get_status(),
			'created_at' => $order->get_date_created()?->format( DATE_ATOM ),
			'total' => $order->get_total(), 'currency' => $order->get_currency(), 'paid' => $order->is_paid(), 'items' => $items,
		);
	}
}
