<?php
/** Two real WP-CLI connections exercise MySQL serialization and stale option caches. */
$store = SpacefastCommerce\Plugin::instance()->store;
$before = $store->counter();
if ( 'holder' === ( $args[0] ?? '' ) ) {
	$result = $store->with_lock( static function () use ( $store ): int {
		WP_CLI::line( 'LOCK_HELD' );
		if ( "release\n" !== fgets( STDIN ) ) {
			throw new RuntimeException( 'Lock holder did not receive release signal.' );
		}
		return $store->next_counter();
	} );
} else {
	WP_CLI::line( 'COUNTER_READ:' . $before );
	add_filter( 'pre_update_option_woocommerce_file_download_method', static function ( mixed $value ): mixed {
		global $wpdb;
		$lock = 'spacefast_commerce_' . substr( hash( 'sha256', $wpdb->prefix . DB_NAME ), 0, 32 );
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock ) );
		if ( (string) $owner !== (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) {
			throw new RuntimeException( 'Setup wrote native options without owning the commerce lock.' );
		}
		return $value;
	} );
	$setup = SpacefastCommerce\Plugin::instance()->provisioning->setup();
	if ( is_wp_error( $setup ) ) {
		throw new RuntimeException( $setup->get_error_message() );
	}
	$result = $store->with_lock( static fn (): int => $store->next_counter() );
}
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
WP_CLI::line( 'COUNTER_WRITTEN:' . $result );
