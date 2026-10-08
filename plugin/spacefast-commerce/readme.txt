=== Spacefast Commerce ===
Requires at least: 7.0
Requires PHP: 8.1
Requires Plugins: woocommerce, woo-storefront
Stable tag: 0.1.0
License: GPLv2 or later

Managed commerce for one Space and environment per WordPress installation.
Stripe platform secrets never belong in this plugin or its binding.

== Development status ==

This is the managed-catalog and native payment boundary of the Sell replacement.
The API payment transport, verified event relay, full provisioning readiness
and operational actions are still under implementation. Do not enable live
payments with this version.

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
public storage configuration fails closed. On Spacefast, the verified runtime
loader supplies spacefast_commerce_runtime_private_root instead. It confines
each store to the runtime's protected .stattic/storage tree and rejects another
request Space, traversal and symlink backing. This location is visible to the
provider's PHP workers; SSH home directories above htdocs are not. The hosting
integration must prove anonymous URL denial and native download delivery.

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

== Native setup ==

The root installer runs wp spacefast-commerce prepare /private/path/store.json.
This validates the bound identity, runs Woo’s native synchronous database update
command and creates missing HPOS tables through Woo’s own synchronizer. Retries
preserve the existing authoritative order storage mode. Native cart and checkout
pages receive the store's Space ownership tag so runtime queries can see them.
Existing page content is preserved; another Space's ownership or a page without
the native Woo block/shortcode prevents preparation. The installer deletes
the private binding file afterward. No buyer request performs this setup.

GET /wp-json/spacefast-commerce/v1/readiness requires the same bound credential
and reports native schema/private-storage inventory plus checkout_pages_ready.
catalog_ready covers only schema/private-storage inventory. Page readiness
checks native content and Space ownership; it does not prove HTTP admission.
The platform must separately prove public routes, mail, cron
execution and payment connectivity before declaring the store ready.

== Native payment boundary ==

The spacefast_connect gateway is registered for classic checkout and Checkout
Block. It remains unavailable without installer-owned private constants
SPACEFAST_COMMERCE_API_ORIGIN (HTTPS) and SPACEFAST_COMMERCE_API_CREDENTIAL.
No platform Stripe key belongs in these constants. The API endpoint
POST /commerce/payments is still being implemented; configuring these
constants alone does not establish readiness.

Woo constructs the authenticated request from its own persisted order, with
stable store/environment/order/attempt identity and the final native total.
The API must derive the connected account and application fee and provide an
account-scoped PaymentIntent confirmation. The gateway forwards buyers to
Woo's protected order-pay page, where Stripe-hosted fields confirm using the
API-provided connected-account context. Return URLs come from native Woo.
Browser confirmation never marks an order paid.

GET /wp-json/spacefast-commerce/v1/orders/{id}/payment provides the trusted
native snapshot; PUT on the same path binds an intent or applies an API-verified
paid result. Both require the exact bound store credential and headers. The
body carries action (bind_intent or paid), attempt_id, intent_id, total (native
decimal string), and currency. Only source-managed single-product quantity-one
orders using this gateway are admitted. A different intent, attempt or amount
is refused. An unpaid recalculated total requires intent synchronization before
completion. Native order metadata and a database lock serialize result writes;
retries use Woo payment_complete once, retaining native grants and mail. Closed
or refunded orders cannot be reopened by a late paid result.

Native boundary tests prove these transitions in CPT and HPOS. Stripe.js,
real Connect payments, 3DS and API event delivery still require their integration
proof; the local native result contract does not claim it.
