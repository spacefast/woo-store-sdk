#!/bin/sh
# Seeds the Astro proof catalog (examples/astro-store/sell/products.json) into a
# running deploy/woo container, the way the companion's apply would: SKU = key,
# an EUR store, a flat-rate zone, cash on delivery for headless order placement.
# This seeds the plain-Woo transport fixture. For the native Spacefast path,
# build the declaration and use `wp spacefast-commerce apply` instead.
#
# Usage: e2e/seed-proof-store.sh [container]   (default woo-ba-wp)
set -eu
container="${1:-woo-ba-wp}"
wp() { docker exec "$container" wp --allow-root "$@"; }

wp option update woocommerce_currency EUR >/dev/null
wp option update woocommerce_default_country PL >/dev/null
wp option update woocommerce_calc_taxes no >/dev/null
wp option update woocommerce_enable_guest_checkout yes >/dev/null
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery","enable_for_virtual":"yes"}' --format=json >/dev/null

wp eval '
$products = [
  ["field-guide-ebook", "The Field Guide (ebook)", "PDF and EPUB, 180 pages.", "A practical guide to reading the land.", "12.00", "digital", null],
  ["field-guide-print", "The Field Guide (paperback)", "Printed on recycled paper.", "A practical guide to reading the land.", "29.00", "physical", 50],
];
foreach ($products as [$sku, $name, $summary, $description, $price, $kind, $stock]) {
  $id = wc_get_product_id_by_sku($sku);
  $product = $id ? wc_get_product($id) : new WC_Product_Simple();
  $product->set_name($name);
  $product->set_sku($sku);
  $product->set_short_description($summary);
  $product->set_description($description);
  $product->set_regular_price($price);
  $product->set_status("publish");
  $product->set_catalog_visibility("visible");
  $product->set_virtual("digital" === $kind);
  $product->set_downloadable("digital" === $kind);
  $product->set_sold_individually("digital" === $kind);
  $product->set_manage_stock(null !== $stock);
  if (null !== $stock && !$id) { $product->set_stock_quantity($stock); }
  $product->save();
}
$zones = WC_Shipping_Zones::get_zones();
$zone_name = "Proof store shipping";
$existing = array_filter($zones, fn ($zone) => $zone["zone_name"] === $zone_name);
if (!$existing) {
  $zone = new WC_Shipping_Zone();
  $zone->set_zone_name($zone_name);
  foreach (["PL", "DE", "CZ", "US"] as $country) { $zone->add_location($country, "country"); }
  $zone->save();
  $instance = $zone->add_shipping_method("flat_rate");
  update_option("woocommerce_flat_rate_" . $instance . "_settings", ["title" => "Flat rate", "tax_status" => "none", "cost" => "15.00"]);
}
'
echo "Proof store seeded in $container"
