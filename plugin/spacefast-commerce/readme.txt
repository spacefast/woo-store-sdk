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
private file ingestion, provisioning readiness and operational actions are
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
description, price (decimal string), enabled (boolean), kind (digital/physical).
The generation is stable across operation retries. An older generation cannot
apply after a newer one starts. A source removal uses enabled=false and retains
native Woo order history. No product deletion endpoint is supplied.

Normal Woo UI, REST and CRUD writes cannot change source-managed fields.
Transactional writes such as native sales counters remain permitted. Root
operators can override the database; the next source apply is authoritative.

== Verification ==

Against a disposable WordPress with WooCommerce, Woo Storefront and this plugin:

wp eval-file e2e/commerce-contract.php

Run once with legacy order storage and once with HPOS enabled. The contract
creates native products/orders and retains them in the disposable fixture.
