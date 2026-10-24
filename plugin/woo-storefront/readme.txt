=== Woo Storefront ===
Contributors: woocommerce
Tags: woocommerce, headless, checkout, storefront
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hosted checkout, customer authentication, shopper accounts, and cache signals for headless WooCommerce storefronts.

== Description ==

Woo Storefront fills the server-side gaps needed by the Woo Headless SDK while keeping WooCommerce's native payment, shipping, and tax extension compatibility.

Features:

* Fresh, short-lived hosted checkout URLs on Store API cart responses.
* Isolated guest Cart-Token issuance for server-rendered storefronts.
* Private guest-cart clones in a theme-independent Checkout Block shell.
* Signed post-purchase returns restricted to the configured storefront origin.
* Customer access and refresh JWTs with logout revocation.
* Customer profile, order, and address endpoints.
* HMAC-signed catalog revalidation webhooks.
* Structured checkout branding settings.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the `woo-storefront` directory to `/wp-content/plugins/`.
3. Activate Woo Storefront.
4. Store settings in the `woo_storefront_settings` option or through the WordPress settings REST API.

The settings object accepts `revalidate_url`, `shared_secret`, and a `branding` object with `logo`, `colors`, and `typography`.

Guest checkout uses WooCommerce's native session cloning. Each handoff starts
from the storefront's current cart. A paid order clears that source cart only
when it still matches the checkout snapshot; later cart changes are preserved.
Payment callbacks settle the cart without requiring a confirmation-page visit.

== REST API ==

The `woo-storefront/v1` namespace provides:

* `POST /auth/login`
* `POST /auth/refresh`
* `POST /auth/logout`
* `POST /auth/register`
* `POST /session`
* `GET /customer`
* `GET /customer/orders`
* `GET /customer/orders/{id}`
* `PUT /customer/profile`
* `PUT /customer/address`

Account routes require `Authorization: Bearer <customer-access-token>`.

== Changelog ==

= 0.1.2 =
* Return a typed authentication error when an account request has no Authorization header.

= 0.1.1 =
* Issue a distinct guest Cart-Token before the first headless cart mutation.
* Add authenticated customer profile updates.

= 0.1.0 =
* Initial feature-plugin implementation.
