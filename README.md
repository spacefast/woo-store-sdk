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

[`examples/shop`](./examples/shop) is a Woo-native fork of Vercel Shop. It covers products, search, categories, carts, coupons, and the handoff to WooCommerce checkout.

```bash
pnpm install
pnpm build
cp examples/shop/.env.example examples/shop/.env.local
pnpm dev:shop
```

See the example README for environment variables and verification commands.
