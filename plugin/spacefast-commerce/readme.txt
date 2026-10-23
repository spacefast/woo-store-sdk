=== Spacefast Commerce ===
Requires at least: 7.0
Requires PHP: 8.2
Requires Plugins: woocommerce, woo-storefront
Stable tag: 0.2.2
License: GPLv2 or later

Source-managed WooCommerce stores with portable bundles, native checkout,
protected downloads, source-designed email layouts and test/live modes.

== Description ==

The companion reads store.json and files/<sha256> through WP-CLI, reconciles
native products in place, and protects source-managed fields in wp-admin and
REST. Stock counts, categories, tax settings and native order history remain
in WooCommerce. Physical products support multiple quantities and one declared
flat shipping charge per order. The storefront owns the cart; Woo owns checkout.

Test mode enables Dummy Payments and adds checkout/email banners. A public test
store can deliver real downloads through a free test purchase. Live mode requires
an enabled WooCommerce Stripe gateway and removes Dummy Payments from classic
and block checkout. Going live silently cancels test orders, restores their
stock and revokes download grants. Retrying repairs interrupted cleanup.

The plugin includes no custom payment gateway or credential-bound REST apply
endpoint. The parked payment integration is excluded from release archives.

== Installation ==

Install and activate WooCommerce, Woo Storefront, WooCommerce Dummy Payments,
WooCommerce Stripe and this companion. Configure a writable protected directory
outside the public document root using SPACEFAST_COMMERCE_PRIVATE_ROOT. On hosts
whose WordPress core sits outside the public root, also set
SPACEFAST_COMMERCE_PUBLIC_ROOT to the actual document root. Spacefast supplies
protected storage through its runtime.

Before WooCommerce creates pages, run:

wp --require=/path/to/spacefast-commerce/cli.php spacefast-commerce preflight

After activating the plugins:

wp spacefast-commerce setup
wp spacefast-commerce apply /path/to/bundle --canonical-origin=https://shop.example
wp spacefast-commerce status
wp spacefast-commerce mode test
wp spacefast-commerce mode live
wp spacefast-commerce email render 123 customer_completed_order

Every command emits exactly one JSON document and exits 0 on success or 1 on
failure. A refusal has error.code, detail, pointer and key. Apply refuses removed
products before writing; retain their source key with active:false to retire
sales. --origin=rollback deactivates products missing from the rollback target.
Hosting coordinators supply --baseline=key1,key2 for the live version or
--no-live-catalog when no catalog is live. Empty --baseline= is refused.

== Verification ==

The repository's node e2e/commerce-fixture.mjs command creates a dedicated,
pinned WordPress/WooCommerce/MariaDB/Mailpit fixture and runs the native contract
against CPT and HPOS order storage. COMMERCE_FIXTURE_PROJECT selects its name;
COMMERCE_HTTP_PORT=0 and COMMERCE_MAIL_PORT=0 use dynamic local ports. The runner
retains disposable products and orders for inspection. Never run it on a merchant
installation. node e2e/commerce-fixture.mjs down removes that named fixture.

The fixture credentials are intentionally disposable test-only values, contain
no merchant data, and are not reused outside that local infrastructure.

See TECHNICAL.md for the bundle, ownership, email, mode, native principal and
packaging contracts. Provider installation, public browser checkout and live
merchant payments have separate integration gates.

== Changelog ==

= 0.2.2 =
* Refuse unavailable native order counts instead of reporting an empty store.
* Expose the native order-retention fact for safe permanent-deletion checks.

= 0.2.1 =
* Disable managed sales while preserving orders, stock and buyer grants.
* Report post-write apply faults as retryable failures.

= 0.2.0 =
* Reconcile portable source bundles through serialized WP-CLI apply.
* Preserve native products, stock, order history and stable buyer download grants.
* Add source-managed store settings, shipping, pages and native email layouts.
* Add test/live modes with retryable native cleanup and named merchant capabilities.
* Park the previous payment relay and exclude it from release archives.
