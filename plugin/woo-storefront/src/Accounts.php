<?php
/**
 * JWT-authenticated shopper account endpoints.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes only the authenticated customer's profile, orders, and addresses.
 */
final class Accounts {
	/**
	 * Auth service.
	 *
	 * @var Auth
	 */
	private Auth $auth;

	/**
	 * Constructor.
	 *
	 * @param Auth $auth Auth service.
	 */
	public function __construct( Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register account routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			Auth::REST_NAMESPACE,
			'/customer',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'customer' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		register_rest_route(
			Auth::REST_NAMESPACE,
			'/customer/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'orders' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'page'    => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
					'perPage' => array( 'default' => 10, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			Auth::REST_NAMESPACE,
			'/customer/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'order' ),
				'permission_callback' => array( $this, 'authorize_order' ),
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			Auth::REST_NAMESPACE,
			'/customer/address',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_address' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Authenticate a request and bind its customer id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ): true|WP_Error {
		return $this->auth->authorize( $request );
	}

	/**
	 * Authenticate and verify order ownership in the permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_order( WP_REST_Request $request ): true|WP_Error {
		$authorized = $this->auth->authorize( $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$order       = wc_get_order( absint( $request->get_param( 'id' ) ) );
		$customer_id = $this->customer_id( $request );
		if ( ! $order || (int) $order->get_customer_id() !== $customer_id ) {
			return new WP_Error(
				'woo_storefront_order_not_found',
				__( 'Order not found.', 'woo-storefront' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Get the authenticated customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function customer( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Auth::customer_payload( $this->customer_id( $request ) ), 200 );
	}

	/**
	 * List the authenticated customer's orders.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function orders( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = max( 1, min( 100, absint( $request->get_param( 'perPage' ) ) ) );
		$result   = wc_get_orders(
			array(
				'customer_id' => $this->customer_id( $request ),
				'limit'       => $per_page,
				'page'        => $page,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$orders = is_object( $result ) && isset( $result->orders ) && is_array( $result->orders ) ? $result->orders : array();
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );
		$pages  = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

		$response = new WP_REST_Response(
			array(
				'items'      => array_map( array( self::class, 'order_summary' ), $orders ),
				'total'      => $total,
				'totalPages' => $pages,
			),
			200
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}

	/**
	 * Get one owned order (ownership was enforced by authorize_order()).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function order( WP_REST_Request $request ): WP_REST_Response {
		$order = wc_get_order( absint( $request->get_param( 'id' ) ) );
		return new WP_REST_Response( self::order_payload( $order ), 200 );
	}

	/**
	 * Update the authenticated customer's billing or shipping address.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_address( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type    = (string) $request->get_param( 'type' );
		$address = $request->get_param( 'address' );
		if ( ! in_array( $type, array( 'billing', 'shipping' ), true ) || ! is_array( $address ) ) {
			return new WP_Error(
				'woo_storefront_invalid_address',
				__( 'A billing or shipping address is required.', 'woo-storefront' ),
				array( 'status' => 400 )
			);
		}

		$customer = new \WC_Customer( $this->customer_id( $request ) );
		$fields   = array(
			'firstName' => 'first_name',
			'lastName'  => 'last_name',
			'company'   => 'company',
			'address1'  => 'address_1',
			'address2'  => 'address_2',
			'city'      => 'city',
			'state'     => 'state',
			'postcode'  => 'postcode',
			'country'   => 'country',
		);

		if ( 'billing' === $type ) {
			$fields['email'] = 'email';
			$fields['phone'] = 'phone';
		}

		foreach ( $fields as $input => $property ) {
			if ( ! array_key_exists( $input, $address ) ) {
				continue;
			}

			$value  = 'email' === $property ? sanitize_email( (string) $address[ $input ] ) : sanitize_text_field( (string) $address[ $input ] );
			$setter = 'set_' . $type . '_' . $property;
			$customer->{$setter}( $value );
		}

		$customer->save();
		return new WP_REST_Response( Auth::customer_payload( $customer->get_id() ), 200 );
	}

	/**
	 * Get the authenticated id set by the permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	private function customer_id( WP_REST_Request $request ): int {
		return (int) $request->get_param( '_woo_storefront_customer_id' );
	}

	/**
	 * Convert an order to the summary contract.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private static function order_summary( \WC_Order $order ): array {
		$date = $order->get_date_created();
		return array(
			'id'           => $order->get_id(),
			'status'       => $order->get_status(),
			'dateCreated'  => $date ? $date->date( DATE_ATOM ) : '',
			'total'        => (string) $order->get_total(),
			'currencyCode' => $order->get_currency(),
			'itemsCount'   => $order->get_item_count(),
		);
	}

	/**
	 * Convert an order to the detailed contract.
	 *
	 * @param \WC_Order|false $order Order.
	 * @return array<string, mixed>
	 */
	private static function order_payload( \WC_Order|false $order ): array {
		if ( ! $order ) {
			return array();
		}

		return array_merge(
			self::order_summary( $order ),
			array(
				'items'           => array_values( array_map( static fn( \WC_Order_Item_Product $item ): array => self::order_item( $order, $item ), $order->get_items() ) ),
				'billingAddress'  => self::order_address( $order, 'billing' ),
				'shippingAddress' => self::order_address( $order, 'shipping' ),
			)
		);
	}

	/**
	 * Convert an order line to the CartItem-compatible contract.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line item.
	 * @return array<string, mixed>
	 */
	private static function order_item( \WC_Order $order, \WC_Order_Item_Product $item ): array {
		$product     = $item->get_product();
		$quantity    = max( 1, $item->get_quantity() );
		$minor_unit  = wc_get_price_decimals();
		$line_total  = wc_add_number_precision( (float) $item->get_total(), false );
		$line_subtotal = wc_add_number_precision( (float) $item->get_subtotal(), false );
		$image_id    = $product ? $product->get_image_id() : 0;
		$image_src   = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : false;
		$thumbnail   = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : false;

		$variation = array();
		foreach ( $item->get_formatted_meta_data( '' ) as $meta ) {
			$variation[] = array(
				'attribute' => wp_strip_all_tags( (string) $meta->display_key ),
				'value'     => wp_strip_all_tags( (string) $meta->display_value ),
			);
		}

		$images = array();
		if ( $image_src ) {
			$images[] = array(
				'id'        => $image_id,
				'src'       => $image_src,
				'thumbnail' => $thumbnail ?: $image_src,
				'alt'       => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			);
		}

		return array(
			'key'       => (string) $item->get_id(),
			'id'        => $item->get_product_id(),
			'name'      => $item->get_name(),
			'quantity'  => $quantity,
			'images'    => $images,
			'prices'    => array(
				'price'             => (string) (int) round( $line_total / $quantity ),
				'currencyCode'      => $order->get_currency(),
				'currencyMinorUnit' => $minor_unit,
			),
			'totals'    => array(
				'lineTotal'    => (string) $line_total,
				'lineSubtotal' => (string) $line_subtotal,
			),
			'variation' => $variation,
		);
	}

	/**
	 * Convert an order address to the Address contract.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $type  billing|shipping.
	 * @return array<string, string>
	 */
	private static function order_address( \WC_Order $order, string $type ): array {
		$prefix = 'get_' . $type . '_';
		$address = array(
			'firstName' => (string) $order->{ $prefix . 'first_name' }(),
			'lastName'  => (string) $order->{ $prefix . 'last_name' }(),
			'company'   => (string) $order->{ $prefix . 'company' }(),
			'address1'  => (string) $order->{ $prefix . 'address_1' }(),
			'address2'  => (string) $order->{ $prefix . 'address_2' }(),
			'city'      => (string) $order->{ $prefix . 'city' }(),
			'state'     => (string) $order->{ $prefix . 'state' }(),
			'postcode'  => (string) $order->{ $prefix . 'postcode' }(),
			'country'   => (string) $order->{ $prefix . 'country' }(),
		);

		if ( 'billing' === $type ) {
			$address['email'] = $order->get_billing_email();
			$address['phone'] = $order->get_billing_phone();
		}

		return $address;
	}
}
