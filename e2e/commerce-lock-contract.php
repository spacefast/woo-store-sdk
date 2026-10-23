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
	$result = $store->with_lock( static fn (): int => $store->next_counter() );
}
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
WP_CLI::line( 'COUNTER_WRITTEN:' . $result );
