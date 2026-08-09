# Woo Store shop example

This is the reference Next.js storefront for Woo Store SDK.

- Keep commerce access behind `lib/woo/`; components consume the domain types in `lib/types.ts`.
- Use `@woo/storefront-next` for server sessions, RPC handlers, and cache integration.
- Cart state is server-owned. Browser mutations go through `/api/store/*` and the signed session cookie.
- Preserve the Vercel Shop attribution and MIT license in this directory.
- Test behavior by running the SDK and storefront against WooCommerce. Do not assert on implementation source text.
