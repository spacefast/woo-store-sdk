<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * D3: one store-owned shipping zone holding the declared countries and one flat rate charged
 * per order. Other zones the merchant creates in wp-admin stay theirs.
 */
final class Shipping {
	public function __construct( private ManagedProducts $managed ) {}

	public function register(): void {
		add_action( 'woocommerce_before_shipping_zone_object_save', array( $this, 'guard_zone' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'guard_rest' ), 10, 3 );
		foreach ( array( 'shipping_zones_save_changes', 'shipping_zone_add_method', 'shipping_zone_remove_method', 'shipping_zone_methods_save_changes', 'shipping_zone_methods_save_settings' ) as $action ) {
			add_action( 'wp_ajax_woocommerce_' . $action, array( $this, 'guard_ajax' ), 1 );
		}
		$method = (int) get_option( 'spacefast_commerce_shipping_method_id', 0 );
		if ( $method ) {
			add_filter( 'pre_update_option_woocommerce_flat_rate_' . $method . '_settings', fn ( mixed $value, mixed $old ): mixed => $this->managed->applying() ? $value : $old, 10, 2 );
		}
	}

	private static function managed_message(): string {
		return __( 'This shipping zone and rate are managed by your store source. Edit the source and publish.', 'spacefast-commerce' );
	}

	/** Woo's REST deletion and method endpoints write directly through the data store. */
	public function guard_rest( mixed $response, array $handler, \WP_REST_Request $request ): mixed {
		if ( null !== $response || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $response;
		}
		if ( preg_match( '#^/wc/v[1-3]/shipping/zones/(\d+)(?:/|$)#', $request->get_route(), $match ) && (int) $match[1] === $this->zone_id() ) {
			return new \WP_Error( 'source_managed_shipping', self::managed_message(), array( 'status' => 403 ) );
		}
		return $response;
	}

	/** Block wp-admin's direct zone/method mutations before Woo's AJAX handlers run. */
	public function guard_ajax(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce and capability are verified below, before responding.
		$zone = $this->zone_id();
		$changes = isset( $_POST['changes'] ) && is_array( $_POST['changes'] ) ? $_POST['changes'] : array();
		$managed = $zone && ( ( isset( $_POST['zone_id'] ) && $zone === absint( $_POST['zone_id'] ) ) || isset( $changes[ $zone ] ) ||
			( isset( $_POST['instance_id'] ) && (int) get_option( 'spacefast_commerce_shipping_method_id' ) === absint( $_POST['instance_id'] ) ) );
		$nonce = isset( $_POST['wc_shipping_zones_nonce'] ) && is_string( $_POST['wc_shipping_zones_nonce'] ) ? wp_unslash( $_POST['wc_shipping_zones_nonce'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $managed && current_user_can( 'manage_woocommerce' ) && wp_verify_nonce( $nonce, 'wc_shipping_zones_nonce' ) ) {
			wp_send_json_error( self::managed_message() );
		}
	}

	public function zone_id(): int {
		return (int) get_option( 'spacefast_commerce_shipping_zone_id', 0 );
	}

	/** The zone's name and countries are managed: revert wp-admin edits; apply repairs anything else. */
	public function guard_zone( \WC_Shipping_Zone $zone ): void {
		if ( $this->managed->applying() || ! $zone->get_id() || $zone->get_id() !== $this->zone_id() ) {
			return;
		}
		$stored = new \WC_Shipping_Zone( $zone->get_id() );
		$zone->set_zone_name( $stored->get_zone_name( 'edit' ) );
		$zone->set_zone_order( $stored->get_zone_order( 'edit' ) );
		$zone->set_locations( array_map( static fn ( $location ): array => array( 'code' => $location->code, 'type' => $location->type ), $stored->get_zone_locations( 'edit' ) ) );
	}

	public static function amount( int $amount_minor, string $currency ): string {
		return 0 === $amount_minor ? '0' : (string) Planner::price_decimal( $amount_minor, $currency );
	}

	public function apply( ?array $shipping, string $currency ): true|\WP_Error {
		global $wpdb;
		$zone_id = $this->zone_id();
		$exists = $zone_id && $wpdb->get_var( $wpdb->prepare( "SELECT zone_id FROM {$wpdb->prefix}woocommerce_shipping_zones WHERE zone_id = %d", $zone_id ) );
		if ( null === $shipping ) {
			if ( $exists ) {
				\WC_Shipping_Zones::delete_zone( $zone_id );
			}
			delete_option( 'spacefast_commerce_shipping_zone_id' );
			delete_option( 'spacefast_commerce_shipping_method_id' );
			delete_option( 'spacefast_commerce_shipping_policy' );
			return true;
		}
		$zone = new \WC_Shipping_Zone( $exists ? $zone_id : null );
		$zone->set_zone_name( __( 'Store shipping', 'spacefast-commerce' ) );
		$zone->set_zone_order( 0 );
		$zone->set_locations( array_map( static fn ( string $country ): array => array( 'code' => $country, 'type' => 'country' ), $shipping['countries'] ) );
		$zone->save();
		update_option( 'spacefast_commerce_shipping_zone_id', $zone->get_id(), false );
		$method_id = (int) get_option( 'spacefast_commerce_shipping_method_id', 0 );
		$found = false;
		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $instance_id => $method ) {
			if ( $instance_id === $method_id && 'flat_rate' === $method->id ) {
				$found = true;
				continue;
			}
			// The zone is owned outright: one flat rate, nothing else.
			$zone->delete_shipping_method( $instance_id );
		}
		if ( ! $found ) {
			$method_id = (int) $zone->add_shipping_method( 'flat_rate' );
			if ( ! $method_id ) {
				return new \WP_Error( 'shipping-failed', 'The flat-rate shipping method could not be added.' );
			}
			update_option( 'spacefast_commerce_shipping_method_id', $method_id, false );
		}
		$wpdb->update( $wpdb->prefix . 'woocommerce_shipping_zone_methods', array( 'is_enabled' => 1 ), array( 'instance_id' => $method_id ) );
		update_option( 'woocommerce_flat_rate_' . $method_id . '_settings', array(
			'title' => __( 'Shipping', 'spacefast-commerce' ),
			'tax_status' => 'none',
			'cost' => self::amount( $shipping['rate']['amountMinor'], $currency ),
			'type' => 'order',
		) );
		update_option( 'spacefast_commerce_shipping_policy', $shipping['policy'], false );
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		return true;
	}
}
