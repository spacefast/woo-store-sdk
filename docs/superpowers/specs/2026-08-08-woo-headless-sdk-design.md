# Woo Headless SDK — Design Spec

Status: approved draft v0.3 · 2026-08-08
Public doc: https://verbose-gravatar-x73fnjqtv.view.fast/ · Artifact: claude.ai/code/artifact/a086cec0-0ed7-467e-bc32-db646a50697f

**Thesis:** Shopify's architecture, TanStack's portability, every Woo payment gateway on day one.

Two coordinated deliverables designed against one contract:

1. **JS library** (npm, working scope `@woo/storefront-*`) for headless WooCommerce storefronts in Next.js and TanStack Start, built on TanStack Query conventions.
2. **Feature plugin** (PHP, `plugin/`) providing what core lacks: hosted-checkout entry, customer JWT auth, shopper account endpoints, cache webhooks. Built to merge upstream into WooCommerce core (the Blocks playbook).

## 1. Developer experience (the product)

```ts
// lib/woo.ts — the only configuration
import { createStorefront } from '@woo/storefront-next'
export const woo = createStorefront({ url: process.env.WOO_URL })

// app/api/store/[...path]/route.ts
export const { GET, POST } = woo.handlers

// server component — cached, tagged, typed
const products = await woo.products.list({ perPage: 24 })

// client — hydrated, optimistic
const { data: cart } = useCart()
const addToCart = useAddToCart()

// checkout — every gateway
redirect(await woo.cart.checkoutUrl())
```

`npx woo-storefront init` detects the framework, mounts the route, writes config. The developer never touches Cart-Token, CORS, nonces, or session code.

## 2. Architecture: server-first (BFF)

Browser → app server (Next Route Handler / Start server function) → WooCommerce. The browser never talks to Woo and never sees a token. Rationale: industry consensus (vercel/shop, Medusa, Catalyst, Saleor) and WooCommerce core has no CORS support — the BFF makes that moot. Catalog reads flow through the server cache.

## 3. Official API surfaces

- **Store API** (`/wp-json/wc/store/v1`) — stable; the SDK's transport (products, cart, checkout).
- **Cart Tokens** — stable; core's headless session primitive. JWT (claims `user_id`, `exp`, `iss:"store-api"`; signed with `'@' . wp_salt()`; 48h default via `wc_session_expiration`). Sent as `Cart-Token` header; replaces cookie sessions and nonces.
- **REST API v4** — in development; admin-side; out of scope.
- **WooCommerce Dual API** — experimental (`dual_code_graphql_api` flag, PHP 8.1+); code-first PHP classes generating GraphQL. Plugin endpoints are schema-first so they can later be re-declared as Dual API classes. Not a v1 dependency.
- **Agentic Commerce Protocol** (`checkout_sessions`) — experimental off-site checkout for AI agents; our `checkout_url` is the human-storefront sibling of the same arc.
- v1 depends only on stable surfaces; nothing behind a feature flag.

## 4. Packages

| Package | Role |
|---|---|
| `@woo/storefront-core` | Typed Store API client, `queryOptions` factories, cache profiles, mutation→invalidation map, pluggable `SessionStore`, typed errors. Zero framework imports. ~90% of code. |
| `@woo/storefront-react` | Thin hooks (`useCart`, `useProducts`, `useAddToCart`, `useCustomer`…), `<StoreProvider>`, hydration helpers. |
| `@woo/storefront-next` | `createStorefront()`: Route Handler factory, cookie SessionStore, cache profiles → `'use cache'`/`cacheTag`/`cacheLife`, webhook → `revalidateTag`. |
| `@woo/storefront-start` | Same responsibilities via TanStack Start server routes/functions. |
| `woo-storefront` (CLI) | `init` scaffolding: detect framework, mount route, write config. |

The public API surface is pinned in `packages/core/src/contracts.ts` — all packages implement/consume those interfaces.

## 5. Session model

One signed httpOnly cookie (`woo_session`) sealing `{ cartToken, customerToken?, cacheId }`:

- Tamper-proof (signed with app secret; HMAC-SHA256).
- Rotation-transparent: when Store API returns a fresh `Cart-Token`, the adapter re-seals the cookie on the response.
- Merge-friendly: on login, server calls plugin auth with both tokens; guest cart merges server-side; cookie re-issued with customer JWT inside.
- `SessionStore` is an interface in core; adapters ship cookie-backed impls; tests use in-memory.

## 6. Caching: one taxonomy, two layers

Cache profiles declared once per query factory; consumed as TanStack Query `staleTime` client-side and framework cache tags server-side.

| Profile | Applies to | Client | Server |
|---|---|---|---|
| `catalog` | products, categories, reviews | 5 min, SWR | tags `products`, `product-{id}`; long life; webhook revalidation |
| `settings` | currencies, countries, shipping | 1 h | tag `settings`; 1 day |
| `session` | cart, checkout, customer, orders | staleTime 0 | per-visitor tag `cart-{cacheId}`; **no-store when customerToken present** |

Rules: (1) no-store invariant — any request carrying a customer token is never server-cached; (2) per-visitor tags for personalized data; (3) every mutation declares an invalidation map (e.g. applyCoupon → cart; login → all session-scoped) applied client-side by TQ and server-side as `revalidateTag`. Plugin webhooks on product/stock/price change hit the adapter's revalidate endpoint; leans on core's `Last-Modified` (10.7+).

## 7. Hosted checkout handoff

Shopify's model: checkout is a token-carrying redirect into first-party infrastructure, not headless.

Flow: `woo.cart.checkoutUrl()` → plugin adds `checkout_url` to cart responses (fresh, signed, short-lived key) → buyer redirected to `GET /checkout/c/{cart-token}` → plugin validates Cart-Token JWT, adopts session, renders Checkout Block in an isolated theme-independent shell (branding via structured schema: logo/colors/type) → any installed gateway pays → 302 to signed `return_url` → app renders confirmation from Store API.

Every gateway/shipping/tax extension works day one because it IS Woo checkout. True headless `POST /wc/store/v1/checkout` (with `expected_total`) stays exposed in core package for per-gateway embedded checkout in v2. Checkout shell ships as a forkable reference.

## 8. Customer accounts (v1)

Plugin provides: customer JWT (issue/refresh/revoke), shopper account endpoints (orders, addresses, saved payment methods, profile), guest→customer cart merge. Library: `woo.auth.*`, `useCustomer()`, `useOrders()` — all `session` profile. Customer JWT travels only inside the sealed cookie.

## 9. Feature plugin contract

| Area | Provides |
|---|---|
| Checkout entry | `GET /checkout/c/{cart-token}` — validate JWT, adopt session, render shell; signed `return_url` |
| Cart response | `checkout_url` field on Store API cart responses |
| Customer auth | JWT issue/refresh/revoke; cart merge on login |
| Account surface | `/wp-json/woo-storefront/v1/` shopper endpoints: orders, addresses, payment methods, profile |
| Cache signals | Webhooks on product/stock/price change → adapter revalidate endpoint |
| Branding | Structured checkout-shell customization schema |

## 10. Errors & testing

- Typed errors: `StoreApiError` base; `CartConflictError` (409 carries refreshed cart data), `AuthExpiredError`, `NotFoundError`, `RateLimitedError`.
- core/react/adapters: vitest; integration against dockerized `wp-env` Woo in CI (no mocks on integration paths).
- Plugin: PHPUnit + Playwright e2e on the full handoff.

## 11. Roadmap

- **v1 (this spec):** catalog + cart + hosted checkout handoff + accounts; Next.js & Start adapters; cache profiles + webhooks; init CLI; forkable checkout shell.
- **v2:** embedded per-gateway checkout (WooPayments, Stripe) over `POST /checkout`; Vue/Svelte bindings; starter template.
- **Upstream:** feature plugin → core (`checkout_url`, customer JWTs, account endpoints; possibly via Dual API for REST+GraphQL parity).

## Design lineage

Server-first identity + httpOnly cookies (vercel/shop, Medusa, Catalyst, Saleor) · signed session envelope (Catalyst) · single-credential session + rotation via response (Swell) · per-visitor cache tags (Medusa) · no-store invariant (Catalyst) · webhook→revalidateTag (Saleor, vercel/shop) · mutation→invalidation map on TQ (Salesforce commerce-sdk-react) · hosted checkout via URL+token, forkable (Shopify, Commerce Layer).
