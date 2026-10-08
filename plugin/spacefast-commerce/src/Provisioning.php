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
		// Do not change the authoritative storage mode of existing native orders.
		$receipt = $this->readiness();
		echo 'SPACEFAST_COMMERCE_PREPARED ' . wp_json_encode( $receipt ) . PHP_EOL;
		if ( ! $receipt['catalog_ready'] ) {
			\WP_CLI::error( 'Commerce setup is incomplete. Check the readiness receipt.' );
		}
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
			'catalog_ready' => array() !== $binding && $schema_ready && ! is_wp_error( $private ),
		);
	}
}
