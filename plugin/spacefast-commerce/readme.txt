=== Spacefast Commerce ===
Requires at least: 7.0
Requires PHP: 8.1
Requires Plugins: woocommerce, woo-storefront
Stable tag: 0.1.0
License: GPLv2 or later

Managed commerce for one Space and environment per WordPress installation.
Stripe platform secrets never belong in this plugin or its binding.

== Development status ==

This is the managed-catalog foundation of the Sell replacement. Gateway,
provisioning readiness and operational actions are
still under implementation. Do not enable live payments with this version.

== Store binding ==

The trusted runtime installer supplies a private JSON file to:

wp spacefast-commerce bind /private/path/store.json

Required fields: space_id, store_id, environment (test or live), origin (HTTPS
origin), currency, country and credential (32-256 URL-safe random characters).
Country and currency come from explicit merchant configuration. Binding does
not activate payments, guess tax policy or install Woo's schema. Repeating the
same binding is safe; another Space/store/environment is rejected. Rotating the
credential revokes the previous one. Remove the provisioning file after use.

== Deploy-owned catalog ==

GET /wp-json/spacefast-commerce/v1/products reads the managed projection.
PUT /wp-json/spacefast-commerce/v1/products/{key} applies one pipeline-selected
product. Headers: Authorization: Bearer <credential>, X-Spacefast-Space-Id,
X-Spacefast-Store-Id and X-Spacefast-Environment. The pipeline computes the diff
and sequences writes; the plugin does not discover source or reconcile it.

Product body: generation (positive monotonic deployment integer), name,
description, price (decimal string), enabled (boolean), kind (digital/physical),
downloads (list of sha256/filename declarations), download_limit and
download_expiry (days). Omitted limits are unlimited and have no expiry.
The generation is stable across operation retries. An older generation cannot
apply after a newer one starts. A source removal uses enabled=false and retains
native Woo order history. No product deletion endpoint is supplied.

Normal Woo UI, REST and CRUD writes cannot change source-managed fields.
Transactional writes such as native sales counters remain permitted. Root
operators can override the database; the next source apply is authoritative.

== Verification ==

Against a disposable WordPress with WooCommerce, Woo Storefront and this plugin:

wp eval-file e2e/commerce-contract.php

For the full native HTTP, email and refund contract, use:

pnpm test:e2e:commerce:fixture

This creates a dedicated Docker Compose fixture with pinned images, verifies
the Woo and WP-CLI download hashes, installs the native plugins and runs the
contract. Local URLs default to http://127.0.0.1:28983 (WordPress) and
http://127.0.0.1:28984 (Mailpit). COMMERCE_HTTP_PORT and COMMERCE_MAIL_PORT can
override them. Credentials are intentionally disposable test-only values.
The fixture is retained for inspection; remove it with:

pnpm test:e2e:commerce:down

For an already prepared disposable installation, run pnpm test:e2e:commerce.

The runner requires a dedicated disposable Docker WordPress container
(COMMERCE_WP_CONTAINER, default sell-managed-woo-wp) with PHP WP-CLI at
/usr/local/bin/wp-cli.phar, these three plugins activated, pretty permalinks,
writable private storage and uploads owned by www-data. The mail fixture below
must connect to a dedicated Mailpit instance. The runner synchronizes native
order tables and exercises both CPT and HPOS, leaving HPOS enabled afterward.
It creates native products/orders and retains them in the disposable fixture.
Never point this runner at a merchant installation.

== Private downloads ==

The installer must configure SPACEFAST_COMMERCE_PUBLIC_ROOT to the actual
public document root and SPACEFAST_COMMERCE_PRIVATE_ROOT to a writable
persistent directory outside it. Do not infer the document root from ABSPATH:
managed hosts can keep WordPress core in a separate shared tree. Missing or
public storage configuration fails closed.

PUT /wp-json/spacefast-commerce/v1/files/{sha256} accepts raw bytes and an
X-Spacefast-Filename header (URL-encoded safe filename), with the same bound
store authentication. Uploads verify the content hash before publishing a
private immutable file. Apply a download declaration only after upload succeeds.

Woo grants and checks native download permissions, sends its native order
emails, and serves Force Downloads without a redirect fallback. File updates
retain historical references in product metadata and keep old bytes. New
orders receive only current files; old grants retain their purchased bytes.
Archival stops new sales and preserves native download links. This boundary
still requires wp.cloud transport/cache/route acceptance before launch.

For actual HTTP delivery and email verification, copy the fixture output
/tmp/commerce-download-contract.json from the WordPress container and run:

node e2e/commerce-http-contract.mjs /path/to/commerce-download-contract.json

The disposable mail fixture uses e2e/fixtures/commerce-mail.php and Mailpit on
COMMERCE_MAILPIT_URL (default http://127.0.0.1:28982). Never install that fixture
on a merchant site.
