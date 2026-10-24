# Quiet Press — static Astro store

A two-product storefront on `@woo/storefront-browser` and the unchanged React
hooks. Astro builds static catalog, cart and confirmation pages. WooCommerce
owns checkout, orders, payment, shipping, email and download authorization.
A guest Cart-Token in sessionStorage authenticates the browser.

Use Node 24 and pnpm 11.16.0. From the workspace root:

```bash
pnpm install --frozen-lockfile
pnpm build
pnpm --filter woo-astro-store dev
```

Without `WOO_ORIGIN`, the local dev server reports `source: "preview"`
at `/__spacefast/commerce.json` and serves the public display catalog at
`/_spacefast/catalog.json`. Products render and checkout stays off. These local
routes are development middleware, not files uploaded to the store. Spacefast's
compiler owns the production preview catalog.

For an existing local Woo store, keep the storefront and Woo on one public
origin. Set Woo's home/site URLs and the companion's canonical origin to that
origin, then proxy the native paths to its backend:

```bash
WOO_ORIGIN=http://127.0.0.1:8890 pnpm --filter woo-astro-store dev --host 127.0.0.1 --port 4321
```

The proxy target is local development plumbing. Production needs no proxy or
Astro server: the Space serves static files and the native Woo paths together.
Install WooCommerce, `woo-storefront` and the native companion, and apply the
compiled declaration before testing this example against a real store. The
ebook is a short `.txt` sample, kept outside the public output.

## Build and publish with Spacefast

Run from the workspace root so the installer can resolve the workspace packages:

```bash
sf build . --root-directory examples/astro-store --install-directory .
sf publish . --build --root-directory examples/astro-store --install-directory . --team YOUR_TEAM
```

The team needs WooCommerce enabled. `sf.jsonc` declares public access, two
products, flat shipping and the processing-order email template. The ebook path
is relative to `sell/products.json`. The build's public archive contains no
private ebook, product source or email template. Its restricted sidecar carries
the private bundle bytes; do not put that sidecar on a public website.

A new Space starts in test mode. Use the test payment method to buy the ebook
and paperback, then check the order email and download. Test mode leaves other
gateways' WooCommerce settings intact.

```bash
sf sell status --space YOUR_SPACE
sf sell go-live --space YOUR_SPACE
```

Go live only after Stripe is configured in WooCommerce and the Space is public.
The explicit go-live action cancels dummy orders, restores their stock and
revokes their downloads. For staging, publish the same source to another Space
and keep that Space in test mode. Version hosts show the public preview catalog
with checkout off. Rollback never changes the Space's mode.

## Shopper flow

The catalog uses `useProducts` and `useAddToCart`. `/cart/` uses `useCart`,
`useUpdateCartItem`, `useRemoveCartItem` and `useCheckoutUrl`. A `Cart-Token`
survives navigation in this tab's `sessionStorage`; it is not a customer token.
Checkout hands that cart to Woo at `/checkout/c/{token}`. After payment, Woo
returns to `/order-confirmation/?order_id=…&order_key=…`. The confirmation page
asks for the billing email before reading the guest order. Its receipt link
opens Woo's native receipt and authorized downloads.

The React island uses Astro's official
[`@astrojs/react` integration](https://docs.astro.build/en/guides/integrations-guide/react/)
with `client:only="react"`, so no store request runs during the static build.
