import { cookieState, resetCookieState } from './start-server.mock'

import { SessionCodec } from '@woo/storefront-core'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import {
  SESSION_COOKIE_NAME,
  StartCookieSessionStore,
  TaggedCache,
  createStorefront,
} from '../src/index'

const sessionSecret = 'test-session-secret'

const emptyCartPayload = {
  items: [],
  items_count: 0,
  totals: {
    total_items: '0',
    total_price: '0',
    total_shipping: null,
    total_discount: '0',
    total_tax: '0',
    currency_code: 'USD',
    currency_minor_unit: 2,
  },
  coupons: [],
  needs_shipping: false,
}

function productPayload(id: number) {
  return {
    id,
    name: `Product ${id}`,
    slug: `product-${id}`,
    permalink: `https://woo.test/product/product-${id}`,
    description: '',
    short_description: '',
    sku: '',
    prices: {
      price: '1000',
      regular_price: '1000',
      sale_price: null,
      currency_code: 'USD',
      currency_minor_unit: 2,
    },
    images: [],
    categories: [],
    is_in_stock: true,
    is_on_sale: false,
    average_rating: '0',
    review_count: 0,
  }
}

beforeEach(resetCookieState)

describe('Start storefront RPC route', () => {
  it('implements the React transport GET-query and POST-RPC envelopes', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (
      input: RequestInfo | URL,
      init?: RequestInit,
    ) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/products')) {
        expect(url.searchParams.get('per_page')).toBe('12')
        return Response.json([productPayload(7)], {
          headers: { 'X-WP-Total': '1', 'X-WP-TotalPages': '1' },
        })
      }
      if (url.pathname.endsWith('/cart') && init?.method !== 'POST') {
        // ensureCartToken bootstrap fetch issued before the first mutation.
        return Response.json(emptyCartPayload, { headers: { 'Cart-Token': 'bootstrap-token' } })
      }
      expect(url.pathname).toMatch(/\/cart\/add-item$/u)
      expect(init?.method).toBe('POST')
      expect(JSON.parse(String(init?.body))).toEqual({ id: 7, quantity: 2 })
      return Response.json(emptyCartPayload)
    })
    const storefront = createStorefront({
      url: 'https://woo.test',
      sessionSecret,
      fetch: fetchMock,
    })

    const queryResponse = await storefront.serverRoute.handlers.GET({
      request: new Request(
        'https://app.test/api/store/query?op=products.list&args=%7B%22perPage%22%3A12%7D',
      ),
    })
    expect(queryResponse.status).toBe(200)
    expect(queryResponse.headers.get('Cache-Control')).toBe('no-store')
    await expect(queryResponse.json()).resolves.toMatchObject({
      data: { items: [{ id: 7 }], total: 1, totalPages: 1 },
    })

    const mutationResponse = await storefront.serverRoute.handlers.POST({
      request: new Request('https://app.test/api/store/rpc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ op: 'cart.addItem', args: { id: 7, quantity: 2 } }),
      }),
    })
    expect(mutationResponse.status).toBe(200)
    await expect(mutationResponse.json()).resolves.toMatchObject({
      data: { items: [], itemsCount: 0 },
    })
  })

  it('rejects mutations sent through the GET query endpoint', async () => {
    const fetchMock = vi.fn<typeof fetch>()
    const storefront = createStorefront({
      url: 'https://woo.test',
      sessionSecret,
      fetch: fetchMock,
    })
    const response = await storefront.serverRoute.handlers.GET({
      request: new Request(
        'https://app.test/api/store/query?op=cart.addItem&args=%7B%22id%22%3A7%7D',
      ),
    })

    expect(response.status).toBe(400)
    await expect(response.json()).resolves.toEqual({
      error: {
        code: 'invalid_rpc_request',
        status: 400,
        message: 'Mutations must use POST',
      },
    })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('persists Cart-Token rotation before completing an RPC response', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async () =>
      Response.json(emptyCartPayload, { headers: { 'Cart-Token': 'rotated-cart-token' } }),
    )
    const storefront = createStorefront({
      url: 'https://woo.test',
      sessionSecret,
      fetch: fetchMock,
    })
    const response = await storefront.serverRoute.handlers.POST({
      request: new Request('https://app.test/api/store/rpc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ op: 'cart.addItem', args: { id: 7 } }),
      }),
    })

    expect(response.status).toBe(200)
    const cookie = cookieState.values.get(SESSION_COOKIE_NAME)
    expect(cookie).toBeDefined()
    await expect(new SessionCodec().unseal(cookie!, sessionSecret)).resolves.toMatchObject({
      cartToken: 'rotated-cart-token',
    })
  })

  it('serializes typed Store API failures in the shared error envelope', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(
      Response.json(
        {
          code: 'cart_conflict',
          message: 'Cart changed',
          data: { cart: emptyCartPayload },
        },
        { status: 409 },
      ),
    )
    const storefront = createStorefront({
      url: 'https://woo.test',
      sessionSecret,
      fetch: fetchMock,
    })
    const response = await storefront.serverRoute.handlers.POST({
      request: new Request('https://app.test/api/store/rpc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ op: 'cart.updateItem', args: { key: 'item-1', quantity: 2 } }),
      }),
    })

    expect(response.status).toBe(409)
    await expect(response.json()).resolves.toMatchObject({
      error: {
        code: 'cart_conflict',
        status: 409,
        message: 'Cart changed',
        refreshedCart: { items: [], itemsCount: 0 },
      },
    })
  })

  it('serializes void mutation results as data: null so the RPC envelope survives', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async () => Response.json({}))
    const storefront = createStorefront({
      url: 'https://woo.test',
      sessionSecret,
      fetch: fetchMock,
    })
    const response = await storefront.serverRoute.handlers.POST({
      request: new Request('https://app.test/api/store/rpc', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ op: 'auth.logout' }),
      }),
    })

    expect(response.status).toBe(200)
    const body = await response.json()
    expect(body).toEqual({ data: null })
    expect(Object.prototype.hasOwnProperty.call(body, 'data')).toBe(true)
  })
})

describe('direct server access and caching', () => {
  it('caches catalog queryFns but invalidates their shared product tags via webhook', async () => {
    const cache = new TaggedCache()
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(
      async () => Response.json(productPayload(12)),
    )
    const storefront = createStorefront({
      url: 'https://woo.test',
      sessionSecret,
      fetch: fetchMock,
      cache,
      revalidateSecret: 'webhook-secret',
    })

    await expect(storefront.products.byId(12)).resolves.toMatchObject({ id: 12 })
    await storefront.products.byId(12).queryFn()
    expect(fetchMock).toHaveBeenCalledOnce()
    expect(cache.size).toBe(1)

    const body = JSON.stringify({ product_ids: [12], scopes: ['stock'] })
    const key = await crypto.subtle.importKey(
      'raw',
      new TextEncoder().encode('webhook-secret'),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['sign'],
    )
    const bytes = new Uint8Array(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body)))
    const signature = `sha256=${[...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')}`
    const response = await storefront.revalidateRoute({
      request: new Request('https://app.test/api/store/revalidate', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-woo-storefront-signature': signature,
        },
        body,
      }),
    })

    expect(response.status).toBe(200)
    await expect(response.json()).resolves.toEqual({
      revalidated: true,
      tags: ['products', 'product-12'],
    })
    expect(cache.size).toBe(0)

    await storefront.products.byId(12).queryFn()
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('enforces no-store for every query carrying a customer token', async () => {
    await new StartCookieSessionStore(sessionSecret).write({
      cacheId: 'customer-1',
      cartToken: 'cart-token',
      customerToken: 'customer-token',
    })
    const cache = new TaggedCache()
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(
      async () => Response.json(productPayload(7)),
    )
    const storefront = createStorefront({
      url: 'https://woo.test',
      sessionSecret,
      fetch: fetchMock,
      cache,
    })

    await storefront.products.byId(7).queryFn()
    await storefront.products.byId(7).queryFn()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(cache.size).toBe(0)
    const [, requestInit] = fetchMock.mock.calls[0]!
    expect(requestInit?.cache).toBe('no-store')
    expect(new Headers(requestInit?.headers).get('Authorization')).toBe('Bearer customer-token')
  })

  it('never process-caches session-profile queryFns for guest sessions either', async () => {
    const cache = new TaggedCache()
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(
      async () => Response.json(emptyCartPayload),
    )
    const storefront = createStorefront({
      url: 'https://woo.test',
      sessionSecret,
      fetch: fetchMock,
      cache,
    })

    const first = await storefront.cart.get().queryFn()
    const second = await storefront.cart.get().queryFn()

    expect(first).toEqual(second)
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(cache.size).toBe(0)
  })
})
