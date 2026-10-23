<?php
/** Run with the real Spacefast engine copied to /tmp/commerce-engine, after native WP boot. */
require '/tmp/commerce-engine/wordpress/content-kernel.php';
function engine_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'm1-companion-contract';
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'administrator';
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = array( 'kind' => 'service', 'actor_id' => 'm1-' . wp_generate_uuid4() );
$id = spacefast_content_principal_establish_user();
engine_check( $id > 0 && array() === wp_get_current_user()->roles, 'Engine principal must have a durable identity without a stored role.' );
foreach ( array( 'manage_woocommerce', 'view_woocommerce_reports', 'edit_shop_orders', 'edit_products', 'edit_shop_coupons' ) as $cap ) {
	engine_check( current_user_can( $cap ), 'Projected merchant cannot ' . $cap );
}
foreach ( array( 'manage_options', 'install_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'install_themes', 'switch_themes', 'edit_themes', 'create_users', 'delete_users', 'promote_users' ) as $cap ) {
	engine_check( ! current_user_can( $cap ), 'Projected merchant can administer the installation: ' . $cap );
}
// Native Woo settings use the projected merchant authority rather than manage_options.
$request = new WP_REST_Request( 'GET', '/wc/v3/settings/general' );
$response = rest_do_request( $request );
engine_check( 200 === $response->get_status(), 'Native Woo settings route refused the projected merchant: ' . wp_json_encode( $response->get_data() ) );
$other = wp_insert_user( array( 'user_login' => 'm1-other-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => '' ) );
engine_check( is_int( $other ) && ! user_can( $other, 'manage_woocommerce' ), 'The request principal granted merchant access to a different user.' );
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'editor';
engine_check( ! current_user_can( 'manage_woocommerce' ), 'Editor projection retained merchant administration.' );
$authentication = wp_authenticate( 'contract', 'disposable-commerce-admin' );
engine_check( is_wp_error( $authentication ) && 'spacefast_password_login_disabled' === $authentication->get_error_code(), 'Spacefast allowed native password authentication.' );
WP_CLI::line( 'PASS: real engine principal projection, named Woo authority, foreign-user isolation, installation refusal and password refusal' );
