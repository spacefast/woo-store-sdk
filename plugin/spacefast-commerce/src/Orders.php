<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Native Woo order views; no parallel order ledger or current-catalog dependency. */
final class Orders {
	public function __construct( private Store $store ) {}

	public function register(): void {
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
		} );
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

	public function detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order || $order instanceof \WC_Order_Refund || 'spacefast_connect' !== $order->get_payment_method() ) {
			return new \WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		foreach ( array( 'space_id', 'store_id', 'environment' ) as $field ) {
			if ( $order->get_meta( '_spacefast_' . $field ) !== ( $this->store->binding()[$field] ?? null ) ) {
				return new \WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
			}
		}
		return new \WP_REST_Response( array( 'data' => array_merge( $this->summary( $order ), array(
			'buyer_email' => $order->get_billing_email(),
			'shipping' => $order->get_address( 'shipping' ),
			'refunded_total' => wc_format_decimal( $order->get_total_refunded(), wc_get_price_decimals() ),
		) ) ) );
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
