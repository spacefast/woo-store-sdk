# Woo Store SDK

Woo Store SDK is a typed, framework-friendly storefront client for WooCommerce. It provides catalog queries, cart-token sessions, hosted checkout, customer accounts, and adapters for React, Next.js, and TanStack Start.

## Packages

- `@woo/storefront-core` — typed Store API client and framework-neutral sessions
- `@woo/storefront-react` — React hooks and providers
- `@woo/storefront-browser` — serverless browser adapter for the React hooks (Store API + `Cart-Token` in `sessionStorage`)
- `@woo/storefront-next` — Next.js server sessions, RPC handlers, and cache helpers
- `@woo/storefront-start` — TanStack Start adapter
- `woo-storefront` — setup and verification CLI
- `plugin/woo-storefront` — WordPress feature plugin for hosted checkout and customer APIs

## Shop example

[`examples/shop`](./examples/shop) is a Woo-native fork of Vercel Shop. It preserves the default demo's catalog, search, collections, product recommendations, bundle surface, buy-now flow, cart, content pages, policies, journal, sitemaps, Markdown routes, and `llms.txt`, then adds optional Woo customer accounts. Shopify-specific accelerated checkout is replaced by Woo hosted checkout in the SDK and a deliberately fake public checkout in the demo.

The fake checkout never sends card data or creates an order. It accepts only Stripe's published example card numbers and makes the no-charge/no-shipping boundary explicit.

```bash
pnpm install
pnpm build
cp examples/shop/.env.example examples/shop/.env.local
pnpm dev:shop
```

See the example README for environment variables and verification commands.

## Astro proof store

[`examples/astro-store`](./examples/astro-store) is a static Astro store: React islands on `@woo/storefront-browser`, its own `/cart/` and order confirmation pages, and a Spacefast Woo-backend declaration (`sf.jsonc`, `sell/products.json`, `_emails/`).

```bash
pnpm build
pnpm --filter woo-astro-store build
```

`node e2e/browser-adapter-e2e.mjs` runs the adapter against a real Woo (a `deploy/woo` container seeded by `e2e/seed-proof-store.sh`).

## Deployment

The public reference deployment uses an existing managed WordPress/WooCommerce site on Pressable as its commerce origin and deploys only the Next.js storefront to Spacefast. Set `WOO_STORE_URL` to that WordPress origin and keep `WOO_SESSION_SECRET` and `WOO_REVALIDATE_SECRET` in the Spacefast runtime environment.

The repository also includes separate production images for local or self-hosted verification:

- `deploy/shop/Dockerfile` builds the SDK packages and Next.js shop.
- `deploy/woo/Dockerfile` builds WordPress, WooCommerce, the feature plugin, and idempotent demo catalog seeding.

The Woo image needs a persistent `/var/www/html` volume and MySQL. It is not required for the Pressable-backed public demo. No Stripe secret or payment processor is used by the demo.

## Native managed-commerce fixture

`pnpm test:e2e:commerce:fixture` prepares pinned WordPress/Woo/MariaDB/Mailpit and
runs the native CPT/HPOS contract. Remove the retained disposable project with
`pnpm test:e2e:commerce:down`.

`node e2e/commerce-fixture.mjs prepare` prepares the store without running tests.
The companion consumes a portable `store.json` bundle through WP-CLI and uses the
installed native gateways. A unique `COMMERCE_FIXTURE_PROJECT`
and `COMMERCE_HTTP_PORT=0 COMMERCE_MAIL_PORT=0` create an isolated project on
dynamic local ports. `COMMERCE_FIXTURE_READY` reports its container and origins;
`node e2e/commerce-fixture.mjs down` removes that named project and its volumes.
Set `COMMERCE_ENGINE_ROOT` to a Spacefast `runtime/engine` directory to also prove
the companion against the real engine's request principal and capability filters.
See [the companion reference](plugin/spacefast-commerce/TECHNICAL.md) for commands,
source-field ownership, modes, portable host setup and release packaging.
