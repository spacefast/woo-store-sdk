<?php
/**
 * `wp spacefast-commerce …` (interfaces §2). Loaded by the plugin, and loadable on its own with
 * `wp --skip-plugins --require=<this file> spacefast-commerce preflight` before WooCommerce exists.
 *
 * @package SpacefastCommerce
 */
declare(strict_types=1);

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'SPACEFAST_COMMERCE_CLI' ) ) {
	return;
}
define( 'SPACEFAST_COMMERCE_CLI', true );
require_once __DIR__ . '/src/Cli.php';
\SpacefastCommerce\Cli::register();
