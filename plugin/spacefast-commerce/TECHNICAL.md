# Spacefast Commerce

The companion reconciles a portable source bundle into native WooCommerce products,
media, shipping and email layouts. Orders, stock counts, tax configuration and payment
processing remain in WooCommerce. It does not add a payment gateway or a REST apply API.

## Requirements

PHP 8.2+, WordPress 7.0+, WooCommerce, Woo Storefront, the WooCommerce Dummy Payments
gateway for test checkout, and the WooCommerce Stripe gateway for live checkout. The
integration fixture pins WooCommerce 11.2.0, Dummy Payments 2.0.0 and Stripe 11.1.1.
On an ordinary WordPress host, define `SPACEFAST_COMMERCE_PRIVATE_ROOT` as a writable
absolute directory outside the web root. Spacefast supplies that directory through its runtime.

## Commands

Load `cli.php` with WP-CLI's `--require` option before WooCommerce installation to run
`wp spacefast-commerce preflight`. It refuses existing checkout/account slug conflicts.
After activating the dependencies and companion, run:

```sh
wp spacefast-commerce setup
wp spacefast-commerce apply /path/to/bundle --canonical-origin=https://shop.example
wp spacefast-commerce disable
wp spacefast-commerce status
wp spacefast-commerce mode test
wp spacefast-commerce mode live
wp spacefast-commerce origin https://shop.example
wp spacefast-commerce email render 123 customer_completed_order
```

Every command emits one JSON document on stdout. Success has `ok: true`; failure has
`ok: false` and `error: { code, detail, pointer, key }`, and exits with status 1.
A successful apply includes the monotonic `counter`, exact `storeHash`, product `changes`
and `warnings`. The bundle has `store.json` with `schemaVersion: 1` and content-addressed
`files/<sha256>` entries. File references include `path`, `size`, and `sha256`. Previously
held files can be omitted. No store identity, credentials or test/live mode belongs in the bundle.

Apply refuses removals, SKU conflicts, currency changes under products/orders, invalid images,
invalid email templates and missing/corrupt files before changing the catalog or counter.
A MySQL named lock serializes setup, apply, disable, origin and mode changes. An infrastructure failure during writes
requires retrying the same bundle; apply repairs native drift and updates stable identities.

Without a baseline flag, every previously managed product is in the removal baseline.
A forward deploy must keep its key and can set `active: false` to stop selling it.
`--origin=rollback` deactivates products missing from the target. A hosting coordinator can
pass `--baseline=key1,key2` for the actual live version or `--no-live-catalog` when none is
live. Leftovers outside both baseline and target are deactivated. An empty `--baseline=` is
an error. This distinguishes failed activation leftovers from intentional product removals.

`disable` drafts every managed product and returns `storeHash: null` with the next
counter. It preserves unmanaged products, orders, stock, download grants and payment
mode. A later apply restores the same product identities. An interrupted disable
returns `apply-failed`; its advanced counter invalidates the last completed hash
until a retry finishes. Post-write apply faults also return `apply-failed`.

Source fields are protected in native product editing, REST, store settings and the declared
shipping zone. Other shipping zones remain editable. `stock.initial` seeds new products only;
stock counts, categories, tax settings and the state part of the store address survive apply.
REST setting changes return `source_managed_setting` (403), including mixed settings batches
before any item is saved. The guard follows WordPress's effective setting ID, including body
parameters. Native General settings explain source ownership and report rejected saves.
Checkout and account pages have fixed slugs; the storefront owns the cart page. Native shop
and product pages redirect to the storefront.

Digital products use a stable download ID per source key. Replacing an asset updates the file
for existing buyers while preserving their native grants, expiry and remaining downloads.
Bytes live outside the web root; WooCommerce authorizes and transfers them.
Physical targets with existing buyer download grants refuse before writes; keep
the digital key and declare a separate physical key. An unavailable native
permissions datastore returns `downloads-unavailable` rather than authorizing removal.

The origin command updates canonical links without changing payment mode or
running order cleanup. It uses the same `--generation` and `--command-generation`
fence as mode and catalog writes.

## Modes and emails

Order facts are read through Woo's native datastore with query caching disabled.
A failed count returns `orders-unavailable`; status and catalog preflight preserve
that refusal. `Orders::has_orders()` exposes the qualified retention fact for the
hosting runtime. Unavailable history cannot authorize permanent deletion.

Test mode enables the dummy gateway and displays test checkout/email banners. A public test
store can deliver real downloads through a free dummy payment. Live mode requires the Stripe
gateway to be enabled and removes both dummy gateway and Checkout Block registration on every
request. Going live cancels dummy-paid orders silently, restores reduced stock, revokes their
download grants and retains order history. Retrying repairs an interrupted cleanup.

`customer_processing_order`, `customer_completed_order` and `new_order` can bind compiled,
static HTML layouts. The six slots are `sf-store-name`, `sf-email-heading`, `sf-order-details`,
`sf-customer-details`, `sf-additional-content` and `sf-store-link`. Order details are required.
Layouts must begin with `<!doctype html>`, fit within 100 KiB and contain no scripts, forms,
event handlers, JavaScript URLs or other `sf-*` slots. Stored HTML is data and is never
executed. Woo keeps native triggers, recipients, subjects, headings, CSS inlining and text mail.
All links use the canonical origin, including sends from cron and WP-CLI without a request host.
Bindings are selected after Woo's cached template lookup, so adding or removing a layout takes
effect on the next render without retaining an earlier native or source-owned template path.

On Spacefast, the companion extends the engine's request principal with named Woo capabilities.
It grants no plugin/theme installation, user administration or `manage_options`, and password
sign-in is disabled, and the guest account page points shoppers to their order links instead
of showing a password form. On ordinary WordPress hosts, native administrator authentication
and its account login form remain.

## Verification and packaging

`node e2e/commerce-fixture.mjs` creates the pinned disposable fixture and runs the native
contract against both CPT and HPOS order storage. It exercises reconciliation and refusal
atomicity, protected editing, shipping quantities, retained grants, real HTTP downloads,
native SMTP email, mode cleanup/retry, and two real MySQL connections contending for the lock.
The JSON fixtures also exercise the shared compiler-to-plugin bundle contract.

Set `COMMERCE_ENGINE_ROOT` to an actual Spacefast `runtime/engine` directory to include the
engine principal integration proof. This loads the real kernel in WordPress; no principal or
capability implementation is substituted.

Commit the plugin subtree, then run `node scripts/package-plugin.mjs spacefast-commerce`.
The deterministic ZIP and checksum manifest are written to `dist/plugin-releases`.
The parked payment code is excluded from the archive and never loaded. It is retained as
prior art for a future optional payment integration.
