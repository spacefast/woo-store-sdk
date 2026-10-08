<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Root provisioning runs native schema work before any buyer request. */
final class Provisioning {
	public function __construct( private Store $store, private PrivateFiles $files ) {}

	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'spacefast-commerce prepare', array( $this, 'prepare_command' ) );
		}
		add_action( 'rest_api_init', function (): void {
			register_rest_route( 'spacefast-commerce/v1', '/readiness', array(
				'methods' => 'GET',
				'permission_callback' => array( $this->store, 'authorize' ),
				'callback' => fn (): \WP_REST_Response => new \WP_REST_Response( array( 'data' => $this->readiness() ) ),
			) );
		} );
	}

	/** The installer writes a private file, not credentials in process arguments. */
	public function prepare_command( array $args ): void {
		if ( 1 !== count( $args ) || ! is_readable( $args[0] ) ) {
			\WP_CLI::error( 'Supply a readable store binding JSON file.' );
		}
		$result = $this->store->bind( json_decode( file_get_contents( $args[0] ), true ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( ! get_option( 'woocommerce_db_version' ) ) {
			\WC_Install::install();
		}
		// Woo's CLI synchronously drains its native batched schema callbacks.
		\WP_CLI::runcommand( 'wc update' );
		$synchronizer = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
		if ( ! $synchronizer->check_orders_table_exists() && ! $synchronizer->create_database_tables() ) {
			\WP_CLI::error( 'Woo order tables could not be prepared.' );
		}
		if ( 'yes' !== get_option( 'woocommerce_feature_fulfillments_enabled' ) ) {
			update_option( 'woocommerce_feature_fulfillments_enabled', 'yes' );
			wc_get_container()->get( \Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentsController::class )->initialize_fulfillments();
		}
		$pages = $this->prepare_pages();
		if ( is_wp_error( $pages ) ) {
			\WP_CLI::error( $pages->get_error_message() );
		}
		$zone = new \WC_Shipping_Zone( 0 );
		$included = false;
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			$included = $included || 'free_shipping' === $method->id;
		}
		if ( ! $included && ! $zone->add_shipping_method( 'free_shipping' ) ) {
			\WP_CLI::error( 'Included shipping could not be prepared.' );
		}
		// Do not change the authoritative storage mode of existing native orders.
		$receipt = $this->readiness();
		echo 'SPACEFAST_COMMERCE_PREPARED ' . wp_json_encode( $receipt ) . PHP_EOL;
		if ( ! $receipt['catalog_ready'] ) {
			\WP_CLI::error( 'Commerce setup is incomplete. Check the readiness receipt.' );
		}
	}

	/** Native pages remain native; the runtime's post queries require Space ownership. */
	public function prepare_pages(): true|\WP_Error {
		$space_id = $this->store->binding()['space_id'] ?? '';
		if ( '' === $space_id ) {
			return new \WP_Error( 'store_unbound', 'Bind the store before preparing checkout pages.' );
		}
		// Check every existing page before changing any ownership or creating pages.
		foreach ( array( 'cart', 'checkout' ) as $name ) {
			$id = wc_get_page_id( $name );
			$owner = $id > 0 ? get_post_meta( $id, '_spacefast_space_id', true ) : '';
			if ( '' !== $owner && $space_id !== $owner ) {
				return new \WP_Error( 'checkout_page_scope_conflict', 'A native checkout page belongs to another Space.' );
			}
		}
		\WC_Install::create_pages();
		foreach ( array( 'cart', 'checkout' ) as $name ) {
			$id = wc_get_page_id( $name );
			$page = $id > 0 ? get_post( $id ) : null;
			if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ||
				( ! has_block( 'woocommerce/' . $name, $page ) && ! has_shortcode( $page->post_content, 'woocommerce_' . $name ) ) ) {
				return new \WP_Error( 'checkout_page_invalid', 'Native cart and checkout pages must be published and contain the Woo block or shortcode.' );
			}
			$owner = get_post_meta( $id, '_spacefast_space_id', true );
			if ( '' !== $owner && $space_id !== $owner ) {
				return new \WP_Error( 'checkout_page_scope_conflict', 'A native checkout page belongs to another Space.' );
			}
		}
		foreach ( array( 'cart', 'checkout' ) as $name ) {
			update_post_meta( wc_get_page_id( $name ), '_spacefast_space_id', $space_id );
		}
		return true;
	}

	/** Inventory does not claim public routing, mail delivery, cron execution or payment readiness. */
	public function readiness(): array {
		global $wpdb;
		$missing = array();
		foreach ( array(
			'woocommerce_sessions', 'woocommerce_order_items', 'woocommerce_order_itemmeta',
			'woocommerce_downloadable_product_permissions', 'actionscheduler_actions',
			'actionscheduler_groups', 'actionscheduler_claims', 'actionscheduler_logs',
			'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta',
			'wc_order_fulfillments', 'wc_order_fulfillment_meta',
		) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				$missing[] = $suffix;
			}
		}
		$binding = $this->store->binding();
		$database_version = get_option( 'woocommerce_db_version', '' );
		$schema_ready = '' !== $database_version && ! \WC_Install::needs_db_update() && array() === $missing && \ActionScheduler::is_initialized();
		$private = $this->files->root();
		$pages_ready = array() !== $binding;
		foreach ( array( 'cart', 'checkout' ) as $name ) {
			$id = wc_get_page_id( $name );
			$page = $id > 0 ? get_post( $id ) : null;
			$pages_ready = $pages_ready && $page && 'publish' === $page->post_status &&
				get_post_meta( $id, '_spacefast_space_id', true ) === ( $binding['space_id'] ?? null ) &&
				( has_block( 'woocommerce/' . $name, $page ) || has_shortcode( $page->post_content, 'woocommerce_' . $name ) );
		}
		return array(
			'space_id' => $binding['space_id'] ?? null,
			'store_id' => $binding['store_id'] ?? null,
			'environment' => $binding['environment'] ?? null,
			'woocommerce_version' => WC_VERSION,
			'database_version' => $database_version,
			'schema_ready' => $schema_ready,
			'missing_tables' => $missing,
			'private_storage_ready' => ! is_wp_error( $private ),
			'private_storage_problem' => is_wp_error( $private ) ? $private->get_error_code() : null,
			'checkout_pages_ready' => $pages_ready,
			'catalog_ready' => array() !== $binding && $schema_ready && ! is_wp_error( $private ),
		);
	}
}
