/**
 * A stateful stand-in for WooCommerce 11.2.1 + woo-storefront, answering with
 * Store API payloads captured from a real store (`deploy/woo` image seeded by
 * `e2e/seed-proof-store.sh`, 2026-10-23). Only the cart arithmetic and the
 * per-token cart state are computed here; every response body starts from a
 * captured shape in ../fixtures/store-api. The real-Woo run of the same flows
 * is `e2e/browser-adapter-e2e.mjs`.
 */
import products from '../fixtures/store-api/products.json'
import cartFixture from '../fixtures/store-api/cart.json'
import emptyCart from '../fixtures/store-api/cart-empty.json'
import order from '../fixtures/store-api/order.json'
import errors from '../fixtures/store-api/errors.json'

export const WOO_ORIGIN = 'https://store.example'
export const ORDER = { id: order.id, key: 'wc_order_fixture', billingEmail: order.billing_address.email }

type CartItem = (typeof cartFixture.items)[number]

export interface FakeWooOptions {
  /** Body for `/__spacefast/commerce.json`; `null` answers 404. */
  commerce?: { source: 'store' | 'preview' } | null
  /** Body for `/_spacefast/catalog.json`; `null` answers 404. */
  catalog?: unknown
}

export interface FakeWoo {
  fetch: typeof fetch
  /** Every request path + search, in order. */
  requests: string[]
  carts: Map<string, CartItem[]>
}

const clone = <T>(value: T): T => structuredClone(value)

function json(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  })
}

function itemTemplate(productId: number): CartItem | undefined {
  return cartFixture.items.find((item) => item.id === productId)
}

function cartBody(items: CartItem[], token: string, returnUrl: string | null) {
  const body = clone(emptyCart) as unknown as Record<string, unknown> & { totals: Record<string, unknown> }
  const itemsTotal = items.reduce((sum, item) => sum + Number(item.totals.line_total), 0)
  const needsShipping = items.some((item) => item.id === products.find((p) => p.sku === 'field-guide-print')?.id)
  const shipping = needsShipping ? 1500 : 0
  body.items = items
  body.items_count = items.reduce((sum, item) => sum + item.quantity, 0)
  body.needs_shipping = needsShipping
  body.totals = {
    ...body.totals,
    total_items: String(itemsTotal),
    total_shipping: String(shipping),
    total_price: String(itemsTotal + shipping),
  }
  const handoff = new URL(`/checkout/c/${encodeURIComponent(token)}`, WOO_ORIGIN)
  handoff.searchParams.set('expires', '1792778263')
  handoff.searchParams.set('key', 'signed-by-woo-storefront')
  if (returnUrl) {
    handoff.searchParams.set('return_url', returnUrl)
    handoff.searchParams.set('return_expires', '1792781563')
    handoff.searchParams.set('return_key', 'signed-by-woo-storefront')
  }
  body.checkout_url = handoff.toString()
  return body
}

function withQuantity(template: CartItem, quantity: number): CartItem {
  const item = clone(template)
  const unit = Number(item.prices.price)
  item.quantity = quantity
  item.totals.line_total = String(unit * quantity)
  item.totals.line_subtotal = String(unit * quantity)
  return item
}

export function createFakeWoo(options: FakeWooOptions = {}): FakeWoo {
  const carts = new Map<string, CartItem[]>()
  const requests: string[] = []
  let tokens = 0

  const handle = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = new URL(input instanceof Request ? input.url : String(input), WOO_ORIGIN)
    const method = init?.method ?? 'GET'
    const headers = new Headers(init?.headers)
    const body = init?.body ? JSON.parse(String(init.body)) as Record<string, unknown> : {}
    const path = url.pathname
    requests.push(`${method} ${path}${url.search}`)

    if (path === '/__spacefast/commerce.json') {
      return options.commerce ? json(options.commerce) : json({ code: 'not_found' }, 404)
    }
    if (path === '/_spacefast/catalog.json') {
      return options.catalog ? json(options.catalog) : json({ code: 'not_found' }, 404)
    }

    if (path === '/wp-json/woo-storefront/v1/session' && method === 'POST') {
      tokens += 1
      const token = `cart-token-${tokens}`
      carts.set(token, [])
      return json({ cartToken: token }, 201, { 'Cart-Token': token })
    }

    if (path === '/wp-json/wc/store/v1/products') {
      const sku = url.searchParams.get('sku')
      const slug = url.searchParams.get('slug')
      const matches = products.filter((product) =>
        (sku === null || sku.split(',').includes(product.sku)) &&
        (slug === null || product.slug === slug))
      return json(matches, 200, { 'X-WP-Total': String(matches.length), 'X-WP-TotalPages': '1' })
    }
    const productId = /^\/wp-json\/wc\/store\/v1\/products\/(\d+)$/u.exec(path)?.[1]
    if (productId) {
      const product = products.find((candidate) => candidate.id === Number(productId))
      return product ? json(product) : json(errors.productNotFound, 404)
    }

    const orderId = /^\/wp-json\/wc\/store\/v1\/order\/(\d+)$/u.exec(path)?.[1]
    if (orderId) {
      if (Number(orderId) !== ORDER.id || url.searchParams.get('key') !== ORDER.key) {
        return json(errors.orderInvalidKey, 401)
      }
      const email = url.searchParams.get('billing_email')
      if (!email) return json(errors.orderMissingBillingEmail, 401)
      if (email.toLowerCase() !== ORDER.billingEmail.toLowerCase()) {
        return json({ ...errors.orderMissingBillingEmail, message: 'Invalid billing email provided.' }, 401)
      }
      return json(order)
    }

    if (path.startsWith('/wp-json/wc/store/v1/cart')) {
      // Woo answers a token-less cart request with a fresh session's token.
      let token = headers.get('Cart-Token') ?? ''
      if (!carts.has(token)) {
        tokens += 1
        token = `cart-token-${tokens}`
        carts.set(token, [])
      }
      const items = carts.get(token)!
      const action = path.slice('/wp-json/wc/store/v1/cart'.length)

      if (action === '/add-item' && method === 'POST') {
        const template = itemTemplate(Number(body.id))
        if (!template) return json(errors.addItemInvalidProduct, 400, { 'Cart-Token': token })
        const existing = items.findIndex((item) => item.id === Number(body.id))
        const quantity = Number(body.quantity ?? 1) + (existing >= 0 ? items[existing]!.quantity : 0)
        if (existing >= 0) items[existing] = withQuantity(template, quantity)
        else items.push(withQuantity(template, quantity))
      } else if (action === '/update-item' && method === 'POST') {
        const index = items.findIndex((item) => item.key === body.key)
        if (index >= 0) items[index] = withQuantity(items[index]!, Number(body.quantity))
      } else if (action === '/remove-item' && method === 'POST') {
        const index = items.findIndex((item) => item.key === body.key)
        if (index >= 0) items.splice(index, 1)
      } else if (action !== '' && action !== '/') {
        return json({ code: 'rest_no_route' }, 404)
      }

      return json(cartBody(items, token, url.searchParams.get('return_url')), 200, { 'Cart-Token': token })
    }

    return json({ code: 'rest_no_route', message: `No fake route for ${method} ${path}` }, 404)
  }

  return { fetch: handle as typeof fetch, requests, carts }
}

/** The proof store's preview catalog, as the Spacefast build emits it (interfaces §3). */
export const PREVIEW_CATALOG = {
  schemaVersion: 1,
  currency: 'eur',
  products: [
    {
      key: 'field-guide-ebook',
      name: 'The Field Guide (ebook)',
      summary: 'PDF and EPUB, 180 pages.',
      description: 'A practical guide to reading the land.',
      price: { amountMinor: 1200, currency: 'eur' },
      image: '/images/ebook-cover.svg',
      page: '/books/field-guide/',
      kind: 'digital',
      active: true,
    },
    {
      key: 'field-guide-print',
      name: 'The Field Guide (paperback)',
      summary: 'Printed on recycled paper.',
      description: 'A practical guide to reading the land.\n\nShips in a recycled sleeve.',
      price: { amountMinor: 2900, currency: 'eur' },
      image: '/images/paperback.svg',
      page: '/books/field-guide/',
      kind: 'physical',
      active: true,
    },
    {
      key: 'field-guide-retired',
      name: 'The Field Guide (first edition)',
      summary: null,
      description: null,
      price: { amountMinor: 900, currency: 'eur' },
      image: null,
      page: null,
      kind: 'digital',
      active: false,
    },
  ],
} as const
