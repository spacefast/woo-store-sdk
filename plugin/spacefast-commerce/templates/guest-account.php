<?php
/** Guest account view for stores that refuse password authentication. @package SpacefastCommerce */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$spacefast_store_url = \SpacefastCommerce\Plugin::instance()?->store->url( '/' ) ?? home_url( '/' );
?>
<section class="spacefast-commerce-guest-account">
	<h1><?php esc_html_e( 'No account needed', 'spacefast-commerce' ); ?></h1>
	<p><?php esc_html_e( 'Use the links on your order confirmation or in your email to view your order and any downloads.', 'spacefast-commerce' ); ?></p>
	<a href="<?php echo esc_url( $spacefast_store_url ); ?>"><?php esc_html_e( 'Back to store', 'spacefast-commerce' ); ?></a>
</section>
