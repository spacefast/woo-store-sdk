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

[`examples/shop`](./examples/shop) is a Woo-native fork of Vercel Shop. It covers products, search, categories, carts, coupons, and a deliberately fake public checkout. The checkout never sends card data or creates an order; it only accepts Stripe's published example card numbers and makes the no-charge/no-shipping boundary explicit.

```bash
pnpm install
pnpm build
cp examples/shop/.env.example examples/shop/.env.local
pnpm dev:shop
```

See the example README for environment variables and verification commands.

## Deployment

The repository includes separate production images for the public storefront and its WooCommerce origin:

- `deploy/shop/Dockerfile` builds the SDK packages and Next.js shop.
- `deploy/woo/Dockerfile` builds WordPress, WooCommerce, the feature plugin, and idempotent demo catalog seeding.

Deploy the Woo image with a persistent `/var/www/html` volume and MySQL, then set the shop's `WOO_STORE_URL` to that public origin. Runtime secrets remain environment variables. No Stripe secret or payment processor is used by the demo.
