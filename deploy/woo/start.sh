#!/usr/bin/env bash
set -euo pipefail

: "${WORDPRESS_URL:?WORDPRESS_URL is required}"
: "${WORDPRESS_ADMIN_PASSWORD:?WORDPRESS_ADMIN_PASSWORD is required}"

/usr/local/bin/docker-entrypoint.sh apache2-foreground &
apache_pid=$!
trap 'kill -TERM "$apache_pid" 2>/dev/null || true' TERM INT

until [[ -f /var/www/html/wp-config.php ]]; do
  if ! kill -0 "$apache_pid" 2>/dev/null; then
    wait "$apache_pid"
    exit $?
  fi
  sleep 2
done

cd /var/www/html
until wp core is-installed --allow-root; do
  if wp core install \
    --allow-root \
    --url="$WORDPRESS_URL" \
    --title="Woo Store SDK Demo" \
    --admin_user="${WORDPRESS_ADMIN_USER:-demo-admin}" \
    --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
    --admin_email="${WORDPRESS_ADMIN_EMAIL:-demo@example.com}" \
    --skip-email; then
    break
  fi
  if ! kill -0 "$apache_pid" 2>/dev/null; then
    wait "$apache_pid"
    exit $?
  fi
  sleep 2
done

cp -a /usr/src/wordpress/wp-content/plugins/woocommerce/. wp-content/plugins/woocommerce/
mkdir -p wp-content/plugins/woo-storefront
cp -a /usr/src/wordpress/wp-content/plugins/woo-storefront/. wp-content/plugins/woo-storefront/

wp option update home "$WORDPRESS_URL" --allow-root
wp option update siteurl "$WORDPRESS_URL" --allow-root
wp option update permalink_structure '/%postname%/' --allow-root
wp rewrite flush --hard --allow-root
wp plugin activate woocommerce woo-storefront --allow-root
wp option update woocommerce_currency USD --allow-root

wp eval --allow-root '
$products = [
  ["woo-sdk-mug", "SDK Debug Mug", "Ship fewer bugs. This one is imaginary.", "1800"],
  ["woo-sdk-tee", "Headless Commerce Tee", "Soft cotton, sharp contracts, zero fulfillment.", "3200"],
  ["woo-sdk-cap", "Store API Cap", "A demo cap for people who read response headers.", "2400"],
  ["woo-sdk-socks", "Deploy Day Socks", "Comfort for terminal deployment states.", "1600"],
];
foreach ($products as [$sku, $name, $description, $price]) {
  if (wc_get_product_id_by_sku($sku)) {
    continue;
  }
  $product = new WC_Product_Simple();
  $product->set_name($name);
  $product->set_sku($sku);
  $product->set_description($description);
  $product->set_short_description($description);
  $product->set_regular_price($price / 100);
  $product->set_status("publish");
  $product->set_virtual(true);
  $product->set_catalog_visibility("visible");
  $product->set_manage_stock(false);
  $product->save();
}
'

echo "Woo Store SDK demo is ready at $WORDPRESS_URL"
wait "$apache_pid"
