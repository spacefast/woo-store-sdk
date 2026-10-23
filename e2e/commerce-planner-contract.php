<?php
/**
 * Planner contract: every case of the control plane's `commerce/catalog.test.ts` (#3801), ported
 * before the TypeScript planner is deleted (plan D2). Each case names its TypeScript origin.
 * Where M1 changed the rule (removal is refused instead of archived), the case says so.
 * Run with `wp eval-file` in the fixture; pure, no store writes.
 */

use SpacefastCommerce\Planner;

function planner_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Planner contract: ' . $message );
	}
}

$merchant = array( 'currency' => 'usd', 'downloads' => array( 'limit' => 5, 'expiryDays' => 30 ) );
$declared = array(
	'key' => 'book', 'kind' => 'digital', 'name' => 'Book', 'summary' => null, 'description' => 'Download',
	'price' => array( 'amountMinor' => 1250, 'currency' => 'usd' ),
	'image' => array( 'url' => 'https://example.com/book.png' ), 'page' => null,
	'asset' => array( 'path' => 'private/book.pdf', 'sha256' => str_repeat( 'a', 64 ), 'size' => 123 ),
	'active' => true, 'stock' => null,
);
$desired = Planner::desired( $declared, 0, $merchant );
// catalog.ts `current`: the native projection of the same product, with Woo spelling the price "12.500".
$current = array_merge( $desired, array( 'id' => 12, 'price' => '12.500' ) );
$plan = static fn ( array $desired_list, array $current_list, string $origin = 'deploy', ?array $baseline = null, string $currency = 'usd', string $store_currency = 'USD' ) =>
	Planner::plan( $desired_list, $current_list, $origin, $baseline, $currency, $store_currency, array() !== $current_list );
$actions = static fn ( $result ): array => is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array_column( $result['changes'], 'action', 'key' );

// "source projection requires exact private receipts and explicit native delivery policy"
planner_check( '12.50' === $desired['price'] && 5 === $desired['download_limit'] && 30 === $desired['download_expiry'], 'amountMinor 1250 usd projects to 12.50 with the merchant download policy' );
planner_check( array( array( 'sha256' => str_repeat( 'a', 64 ), 'filename' => 'book.pdf' ) ) === $desired['downloads'], 'the asset projects to its content identity and file name' );
planner_check( '1250' === Planner::price_decimal( 1250, 'jpy' ) && is_wp_error( Planner::price_decimal( 1250, 'isk' ) ), 'zero-decimal and whole-only currencies keep catalog.ts amount rules' );

// "the deploy diff repairs owned drift …": unchanged, create, and each drift case.
planner_check( array( 'book' => 'unchanged' ) === $actions( $plan( array( $desired ), array( $current ) ) ), 'a matching native product (12.500 vs 12.50) is unchanged' );
planner_check( array( 'book' => 'created' ) === $actions( $plan( array( $desired ), array() ) ), 'a new key is created' );
planner_check( array( 'book' => 'updated' ) === $actions( $plan( array( $desired ), array( array_merge( $current, array( 'price' => '99.00', 'downloads' => null ) ) ) ) ), 'price drift and unreadable downloads are repaired' );
planner_check( array( 'book' => 'updated' ) === $actions( $plan( array( $desired ), array( array_merge( $current, array( 'image' => null ) ) ) ) ), 'a lost cover image is repaired' );
planner_check( array( 'book' => 'updated' ) === $actions( $plan( array( $desired ), array( array_merge( $current, array( 'date_on_sale_from' => 123 ) ) ) ) ), 'a scheduled sale is repaired' );

// catalog.ts "archives removed products": M1 refuses the removal instead (D2), except on rollback.
planner_check( array( 'error' => 'product-removed' ) === $actions( $plan( array(), array( $current ) ) ), 'removing a live product is refused (store baseline)' );
planner_check( array( 'error' => 'product-removed' ) === $actions( $plan( array(), array( array_merge( $current, array( 'key' => '0' ) ) ) ) ), 'numeric source keys keep the same removal refusal' );
planner_check( array( 'book' => 'deactivated' ) === $actions( $plan( array(), array( $current ), 'rollback' ) ), 'rollback deactivates a product missing from the target' );
// catalog.ts "does not re-archive a draft": a leftover outside the explicit live baseline needs no change.
planner_check( array() === $actions( $plan( array(), array( array_merge( $current, array( 'status' => 'draft' ) ) ), 'deploy', array() ) ), 'an already inactive leftover is left alone' );
// catalog.ts "duplicated": duplicate store identities and duplicate declared keys are refused.
planner_check( array( 'error' => 'identity-conflict' ) === $actions( $plan( array( $desired ), array( $current, $current ) ) ), 'duplicate managed identities are refused' );
planner_check( array( 'error' => 'duplicate-key' ) === $actions( $plan( array( $desired, $desired ), array() ) ), 'duplicate declared keys are refused' );
// catalog.ts "currency": the store currency must match the merchant's once the store has products or orders.
planner_check( array( 'error' => 'currency-mismatch' ) === $actions( $plan( array(), array( $current ), 'deploy', array(), 'eur', 'USD' ) ), 'a currency change under existing products is refused' );
planner_check( array() === $actions( $plan( array(), array(), 'deploy', null, 'eur', 'USD' ) ), 'the first apply may set the currency' );

// D2 baseline rule (E row "Removal baseline").
$leftover = array_merge( $current, array( 'key' => 'leftover', 'id' => 13 ) );
$other = array_merge( $desired, array( 'key' => 'other' ) );
planner_check( array( 'error' => 'product-removed' ) === $actions( $plan( array( $other ), array( $current ), 'deploy', array( 'book' ) ) ), '--baseline: a key dropped from the live version is refused' );
planner_check( array( 'other' => 'created', 'leftover' => 'deactivated' ) === $actions( $plan( array( $other ), array( $leftover ), 'deploy', array( 'other' ) ) ), '--baseline: a leftover outside target and baseline is deactivated' );
planner_check( array( 'other' => 'created', 'book' => 'deactivated', 'leftover' => 'deactivated' ) === $actions( $plan( array( $other ), array( $current, $leftover ), 'deploy', array() ) ), '--no-live-catalog: every leftover is deactivated' );
planner_check( array( 'error' => 'product-removed' ) === $actions( $plan( array( $other ), array( array_merge( $current, array( 'status' => 'draft' ) ) ) ) ), 'no flag: removal of a previously managed inactive product is refused' );

// D1/A3: physical quantity is the shopper's, digital is sold individually; stock.initial is create-only.
$physical = Planner::desired( array_merge( $declared, array( 'key' => 'print', 'kind' => 'physical', 'asset' => null, 'stock' => array( 'track' => true, 'initial' => 50 ) ) ), 1, $merchant );
planner_check( false === $physical['sold_individually'] && true === $desired['sold_individually'] && true === $physical['manage_stock'], 'physical products drop the quantity-1 cap' );
$tracked = array_merge( $physical, array( 'id' => 14, 'stock_initial' => 50 ) );
$restocked = Planner::plan( array( array_merge( $physical, array( 'stock_initial' => 80 ) ) ), array( $tracked ), 'deploy', null, 'usd', 'USD', true );
planner_check( array( 'print' => 'unchanged' ) === $actions( $restocked ) && 'stock-initial-ignored' === $restocked['warnings'][0]['code'] && '/products/0/stock/initial' === $restocked['warnings'][0]['pointer'], 'a later stock.initial change is a warning, not a write' );
$tracking = $plan( array( $physical ), array( array_merge( $tracked, array( 'manage_stock' => false, 'stock_initial' => null ) ) ) );
planner_check( 'updated' === $tracking['changes'][0]['action'] && 'stock-initial-ignored' === $tracking['warnings'][0]['code'], 'starting stock tracking on an existing product cannot re-seed stock.initial' );

WP_CLI::line( 'PASS: planner contract (catalog.ts cases + D2 baseline rule)' );
