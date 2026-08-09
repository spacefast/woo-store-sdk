import { describe, expect, it, vi } from 'vitest'

import { createStorefrontClient, InMemorySessionStore } from '../src/index'

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  const headers = new Headers(init.headers)
  headers.set('Content-Type', 'application/json')
  return new Response(JSON.stringify(body), {
    ...init,
    status: init.status ?? 200,
    headers,
  })
}

const rawCart = {
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

describe('createStorefrontClient', () => {
  it('uses Store API paths and translates product list parameters', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse([], {
      headers: { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' },
    }))
    const client = createStorefrontClient(
      { url: 'https://shop.example', fetch: fetchMock },
      new InMemorySessionStore({ cacheId: 'visitor' }),
    )

    await client.products.list({ perPage: 24, onSale: true, minPrice: '1000' }).queryFn()

    const requestedUrl = String(fetchMock.mock.calls[0]?.[0])
    expect(requestedUrl).toBe(
      'https://shop.example/wp-json/wc/store/v1/products?per_page=24&on_sale=true&min_price=1000',
    )
  })

  it('always fetches a fresh cart for checkoutUrl', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({
      ...rawCart,
      checkout_url: 'https://shop.example/checkout/c/signed-token',
    }))
    const client = createStorefrontClient(
      { url: 'https://shop.example', fetch: fetchMock },
      new InMemorySessionStore({ cacheId: 'visitor' }),
    )

    await expect(client.cart.checkoutUrl()).resolves.toBe('https://shop.example/checkout/c/signed-token')
    expect(fetchMock).toHaveBeenCalledOnce()
    const requestedUrl = new URL(String(fetchMock.mock.calls[0]?.[0]))
    expect(requestedUrl.origin + requestedUrl.pathname).toBe(
      'https://shop.example/wp-json/wc/store/v1/cart',
    )
    expect(requestedUrl.searchParams.get('_woo_request')).toBeTruthy()
  })

  it('creates an isolated guest session before the first cart mutation', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse(
        { cartToken: 'visitor-cart-token' },
        { status: 201, headers: { 'Cart-Token': 'visitor-cart-token' } },
      ))
      .mockResolvedValueOnce(jsonResponse({ ...rawCart, items_count: 1 }))
    const session = new InMemorySessionStore({ cacheId: 'visitor' })
    const client = createStorefrontClient({ url: 'https://shop.example', fetch: fetchMock }, session)

    await client.cart.addItem.mutationFn({ id: 42 })

    const sessionUrl = new URL(String(fetchMock.mock.calls[0]?.[0]))
    expect(sessionUrl.origin + sessionUrl.pathname).toBe(
      'https://shop.example/wp-json/woo-storefront/v1/session',
    )
    expect(sessionUrl.searchParams.get('_woo_request')).toBeTruthy()
    expect(new Headers(fetchMock.mock.calls[1]?.[1]?.headers).get('Cart-Token')).toBe(
      'visitor-cart-token',
    )
  })

  it('mentions the feature plugin when hosted checkout is unavailable', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse(rawCart))
    const client = createStorefrontClient(
      { url: 'https://shop.example', fetch: fetchMock },
      new InMemorySessionStore({ cacheId: 'visitor' }),
    )

    await expect(client.cart.checkoutUrl()).rejects.toThrow(/feature plugin/iu)
  })

  it('stores a plugin customer JWT after login and removes it after logout', async () => {
    const fetchMock = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({
        customer_token: 'new-customer-jwt',
        customer: { id: 5, email: 'buyer@example.com', first_name: 'Ada', last_name: 'Lovelace' },
      }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
    const session = new InMemorySessionStore({ cacheId: 'visitor', cartToken: 'cart-token' })
    const client = createStorefrontClient({ url: 'https://shop.example', fetch: fetchMock }, session)

    await expect(client.auth.login.mutationFn({
      email: 'buyer@example.com',
      password: 'correct horse battery staple',
    })).resolves.toMatchObject({ firstName: 'Ada', lastName: 'Lovelace' })
    expect((await session.read()).customerToken).toBe('new-customer-jwt')

    await client.auth.logout.mutationFn()
    expect(await session.read()).toEqual({ cacheId: 'visitor', cartToken: 'cart-token' })
    expect(new Headers(fetchMock.mock.calls[1]?.[1]?.headers).get('Authorization')).toBe(
      'Bearer new-customer-jwt',
    )
  })
})
