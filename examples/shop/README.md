# Woo Store SDK shop

A production-shaped Next.js 16 storefront powered by WooCommerce and Woo Store SDK. The interface is a surgical fork of Vercel's Shop example; its commerce provider, sessions, cart mutations, search, collections, and products are implemented with Woo Store SDK.

## Run it

From the repository root:

```bash
pnpm install
cp examples/shop/.env.example examples/shop/.env.local
pnpm build
pnpm dev:shop
```

Set `WOO_STORE_URL` to a WordPress site with WooCommerce and the bundled `woo-storefront` plugin active. `WOO_SESSION_SECRET` must contain at least 32 random characters.

The app exposes the SDK's signed server bridge at `/api/store/*`. Browser cart changes use that bridge; catalog reads and the initial cart render stay on the server. The public example routes checkout to an explicit, browser-only simulation using Stripe's published example cards. It sends no payment data, charges nothing, and creates no order. The underlying SDK and feature plugin still expose hosted WooCommerce checkout for applications that need it.

## Verify

```bash
pnpm test
pnpm test:shop
pnpm build:shop
```

The repository E2E harness additionally exercises catalog, content routes, cart-token continuity, hosted checkout, customer registration, profile updates, addresses, order history, and signed cache revalidation against a real WooCommerce instance.

## Upstream

UI and route structure were forked from [vercel/shop](https://github.com/vercel/shop) at commit `16f672bba377719073d6a2d848b2e2b5edd63cb9`. See [UPSTREAM.md](./UPSTREAM.md) and [LICENSE](./LICENSE).
