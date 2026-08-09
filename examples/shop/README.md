# Woo Store SDK shop

A production-shaped Next.js 16 storefront powered by WooCommerce and Woo Store SDK. The interface is a surgical fork of Vercel's Shop example; its commerce provider, sessions, cart mutations, search, collections, products, and hosted checkout are implemented with Woo Store SDK.

## Run it

From the repository root:

```bash
pnpm install
cp examples/shop/.env.example examples/shop/.env.local
pnpm build
pnpm dev:shop
```

Set `WOO_STORE_URL` to a WordPress site with WooCommerce and the bundled `woo-storefront` plugin active. `WOO_SESSION_SECRET` must contain at least 32 random characters.

The app exposes the SDK's signed server bridge at `/api/store/*`. Browser cart changes use that bridge; catalog reads and the initial cart render stay on the server. Checkout hands the visitor to the WooCommerce checkout URL created by the feature plugin.

## Verify

```bash
pnpm test
pnpm test:shop
pnpm build:shop
```

The repository E2E harness additionally exercises catalog, search, cart-token continuity, coupons, hosted checkout, customer auth, addresses, and order placement against a real WooCommerce instance.

## Upstream

UI and route structure were forked from [vercel/shop](https://github.com/vercel/shop) at commit `16f672bba377719073d6a2d848b2e2b5edd63cb9`. See [UPSTREAM.md](./UPSTREAM.md) and [LICENSE](./LICENSE).
