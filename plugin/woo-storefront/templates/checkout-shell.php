<?php
/**
 * Theme-independent Checkout Block shell.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

use WooStorefront\Branding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$woo_storefront_settings = Branding::get_settings();
$woo_storefront_branding = $woo_storefront_settings['branding'];
$woo_storefront_colors   = $woo_storefront_branding['colors'];
$woo_storefront_type     = $woo_storefront_branding['typography'];
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
	<style id="woo-storefront-checkout-shell">
		:root {
			--woo-storefront-primary: <?php echo esc_html( $woo_storefront_colors['primary'] ); ?>;
			--woo-storefront-background: <?php echo esc_html( $woo_storefront_colors['background'] ); ?>;
			--woo-storefront-text: <?php echo esc_html( $woo_storefront_colors['text'] ); ?>;
			--woo-storefront-font: <?php echo esc_html( $woo_storefront_type['font_family'] ); ?>;
			--woo-storefront-size: <?php echo absint( $woo_storefront_type['base_size'] ); ?>px;
		}
		html, body { margin: 0; min-height: 100%; }
		body {
			background: var(--woo-storefront-background);
			color: var(--woo-storefront-text);
			font-family: var(--woo-storefront-font);
			font-size: var(--woo-storefront-size);
		}
		.woo-storefront-shell { box-sizing: border-box; margin: 0 auto; max-width: 1200px; padding: 32px 20px 64px; }
		.woo-storefront-logo { display: block; height: auto; margin: 0 auto 32px; max-height: 72px; max-width: min(260px, 80vw); }
		.woo-storefront-name { display: block; margin: 0 auto 32px; text-align: center; color: inherit; font-size: 24px; text-decoration: none; }
		.woo-storefront-shell .wc-block-components-button:not(.is-link),
		.woo-storefront-checkout .woo-storefront-shell .button {
			--wp-components-color-accent: var(--woo-storefront-primary);
			background-color: var(--woo-storefront-primary);
			border-color: var(--woo-storefront-primary);
			color: <?php echo esc_html( Branding::button_text_color( $woo_storefront_colors['primary'] ) ); ?>;
		}
	</style>
</head>
<body <?php body_class( 'woo-storefront-checkout' ); ?>>
	<?php wp_body_open(); ?>
	<main class="woo-storefront-shell">
		<?php if ( '' !== $woo_storefront_branding['logo'] ) : ?>
			<img class="woo-storefront-logo" src="<?php echo esc_url( $woo_storefront_branding['logo'] ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<?php else : ?>
			<a class="woo-storefront-name" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
		<?php endif; ?>
		<?php
		// Render the assigned Woo page, including its inner blocks and merchant settings.
		// A self-closing checkout block has no form fields in WooCommerce 11.2.
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		?>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
