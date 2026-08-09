# Woo Store SDK

Woo Store SDK is a typed, framework-friendly storefront client for WooCommerce. It provides catalog queries, cart-token sessions, hosted checkout, customer accounts, and adapters for React, Next.js, and TanStack Start.

## Packages

- `@woo/storefront-core` — typed Store API client and framework-neutral sessions
- `@woo/storefront-react` — React hooks and providers
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

## Deployment

The public reference deployment uses an existing managed WordPress/WooCommerce site on Pressable as its commerce origin and deploys only the Next.js storefront to Spacefast. Set `WOO_STORE_URL` to that WordPress origin and keep `WOO_SESSION_SECRET` and `WOO_REVALIDATE_SECRET` in the Spacefast runtime environment.

The repository also includes separate production images for local or self-hosted verification:

- `deploy/shop/Dockerfile` builds the SDK packages and Next.js shop.
- `deploy/woo/Dockerfile` builds WordPress, WooCommerce, the feature plugin, and idempotent demo catalog seeding.

The Woo image needs a persistent `/var/www/html` volume and MySQL. It is not required for the Pressable-backed public demo. No Stripe secret or payment processor is used by the demo.
