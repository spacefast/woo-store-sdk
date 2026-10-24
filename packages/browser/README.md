# @woo/storefront-browser

Run `@woo/storefront-react` hooks with no storefront server. The adapter runs
the SDK core client in the page and calls the WooCommerce Store API directly.
It keeps the `Cart-Token` in `sessionStorage`. Use it for static storefronts
(Astro, plain HTML + React islands) served from the store's own origin.

```tsx
import { createWooQueryClient, StoreProvider } from '@woo/storefront-react'
import { createBrowserTransport, retryStoreQuery } from '@woo/storefront-browser'

const transport = createBrowserTransport()
const queryClient = createWooQueryClient({ defaultOptions: { queries: { retry: retryStoreQuery } } })

<StoreProvider transport={transport} queryClient={queryClient}>…</StoreProvider>
```

The hooks and their types are the same as with the RPC transport. You pick
the adapter in the provider config.

## Supported operations

| Operation          | Store API                                            | Hook                          |
| ------------------ | ---------------------------------------------------- | ----------------------------- |
| `products.list`    | `GET /wc/store/v1/products`                          | `useProducts`                 |
| `products.bySlug`  | `GET /wc/store/v1/products?slug=`                    | `useProduct('slug')`          |
| `products.byId`    | `GET /wc/store/v1/products/{id}`                     | `useProduct(id)`              |
| `products.byKey`   | `GET /wc/store/v1/products?sku=` (exact match)       | `transport.productByKey(key)` |
| `cart.get`         | `GET /wc/store/v1/cart`                              | `useCart`                     |
| `cart.addItem`     | `POST /wc/store/v1/cart/add-item`                    | `useAddToCart`                |
| `cart.updateItem`  | `POST /wc/store/v1/cart/update-item`                 | `useUpdateCartItem`           |
| `cart.removeItem`  | `POST /wc/store/v1/cart/remove-item`                 | `useRemoveCartItem`           |
| `cart.checkoutUrl` | `GET /wc/store/v1/cart?return_url=` → `checkout_url` | `useCheckoutUrl`              |
| `order.get`        | `GET /wc/store/v1/order/{id}?key=&billing_email=`    | `transport.order(lookup)`     |

Every other operation throws `UnsupportedOperationError` (`status: 501`,
`code: 'unsupported_operation'`). That covers categories, coupons, headless
`checkout.*`, `auth.*` and `customer.*`. Those need a server adapter
(`@woo/storefront-next`, `@woo/storefront-start`), because they depend on a
customer token that must never reach the browser.

## Checkout

The `woo-storefront` plugin signs a `/checkout/c/{token}` URL into every cart
response, so the browser needs no integration secret. `useCheckoutUrl()` asks for it with a
`return_url` of `confirmationPath` (default `/order-confirmation/`) on the page
origin. Navigate to the URL it returns. Woo adopts the cart, takes payment in
its checkout shell, then redirects to
`/order-confirmation/?order_id=…&order_key=…`.

On that page, call `transport.order({ id, key, billingEmail })`. For a guest
order, Woo's Store API asks for the billing email as well as the key. Without
it, the call fails with `woocommerce_rest_invalid_billing_email` (401), so ask
the shopper for the email first.

## Preview source

On first use, the adapter reads `/__spacefast/commerce.json` once:

- `{ "source": "preview" }`: products come from the build's
  `/_spacefast/catalog.json`. Every cart and order operation throws
  `CheckoutOffInPreviewError` (`code: 'checkout_off_in_preview'`). Check it with
  `isCheckoutOffInPreview(error)` and show "Checkout is off in previews".
- A 404 from a host without the endpoint: the live store.
- A network failure, other HTTP error or invalid body: a typed source error. Retry before showing store actions.

`transport.source()` resolves to `'store'` or `'preview'`. Use it to swap the
add-to-cart button before anyone clicks. Pass `source: 'store'` to skip the
request on hosts that never serve previews.

## Options

| Option               | Default                                               |
| -------------------- | ----------------------------------------------------- |
| `url`                | the page origin                                       |
| `session`            | `sessionStorage` under `woo-storefront:session:<url>` |
| `source`             | `'detect'`                                            |
| `commerceConfigPath` | `/__spacefast/commerce.json`                          |
| `catalogPath`        | `/_spacefast/catalog.json`                            |
| `confirmationPath`   | `/order-confirmation/`                                |

`sessionStorage` belongs to one tab, so a second tab starts with an empty cart.
