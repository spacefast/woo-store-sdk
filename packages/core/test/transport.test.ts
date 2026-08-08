import { describe, expect, it, vi } from 'vitest'

import {
  AuthExpiredError,
  CartConflictError,
  FetchTransport,
  InMemorySessionStore,
  NotFoundError,
  RateLimitedError,
  StoreApiError,
} from '../src/index'

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  const headers = new Headers(init.headers)
  headers.set('Content-Type', 'application/json')
  return new Response(JSON.stringify(body), {
    ...init,
    status: init.status ?? 200,
    headers,
  })
}

describe('FetchTransport', () => {
  it('attaches session credentials, disables caching, and persists Cart-Token rotation', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse(
      {
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
      },
      { headers: { 'Cart-Token': 'rotated-cart-token' } },
    ))
    const session = new InMemorySessionStore({
      cacheId: 'visitor-1',
      cartToken: 'original-cart-token',
      customerToken: 'customer-jwt',
    })
    const transport = new FetchTransport({ url: 'https://shop.example', fetch: fetchMock })

    const result = await transport.request({
      method: 'GET',
      path: '/wp-json/wc/store/v1/cart',
      query: { calculate_totals: true, omitted: undefined },
      profile: 'session',
    }, session)

    const [url, init] = fetchMock.mock.calls[0] ?? []
    expect(String(url)).toBe('https://shop.example/wp-json/wc/store/v1/cart?calculate_totals=true')
    expect(new Headers(init?.headers).get('Cart-Token')).toBe('original-cart-token')
    expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer customer-jwt')
    expect(init?.cache).toBe('no-store')
    expect(result.cartToken).toBe('rotated-cart-token')
    expect((await session.read()).cartToken).toBe('rotated-cart-token')
  })

  it('reads pagination headers and maps product payloads to camelCase while retaining raw data', async () => {
    const rawProduct = {
      id: 7,
      name: 'Coffee',
      slug: 'coffee',
      permalink: 'https://shop.example/product/coffee',
      description: '<p>Coffee</p>',
      short_description: 'Good coffee',
      sku: 'COFFEE',
      prices: {
        price: '1299',
        regular_price: '1499',
        sale_price: '1299',
        currency_code: 'USD',
        currency_minor_unit: 2,
      },
      images: [{ id: 3, src: 'large.jpg', thumbnail: 'small.jpg', alt: 'Coffee' }],
      categories: [{ id: 2, name: 'Drinks', slug: 'drinks' }],
      is_in_stock: true,
      is_on_sale: true,
      average_rating: '4.50',
      review_count: 8,
    }
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse([rawProduct], {
      headers: { 'X-WP-Total': '23', 'X-WP-TotalPages': '3' },
    }))
    const transport = new FetchTransport({ url: 'https://shop.example/', fetch: fetchMock })

    const result = await transport.request<Array<Record<string, unknown>>>({
      method: 'GET',
      path: '/wp-json/wc/store/v1/products',
      profile: 'catalog',
    }, new InMemorySessionStore({ cacheId: 'visitor-2' }))

    expect(result.total).toBe(23)
    expect(result.totalPages).toBe(3)
    expect(result.data[0]).toMatchObject({
      shortDescription: 'Good coffee',
      isInStock: true,
      isOnSale: true,
      reviewCount: 8,
      prices: { regularPrice: '1499', currencyCode: 'USD', currencyMinorUnit: 2 },
    })
    expect(result.data[0]?.raw).toEqual(rawProduct)
  })

  it('maps a 409 response to CartConflictError with its refreshed cart', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({
      code: 'woocommerce_rest_cart_error',
      message: 'The cart changed',
      data: {
        status: 409,
        cart: {
          items: [],
          items_count: 0,
          totals: {
            total_items: '0',
            total_price: '1200',
            total_shipping: null,
            total_discount: '0',
            total_tax: '0',
            currency_code: 'USD',
            currency_minor_unit: 2,
          },
          coupons: [],
          needs_shipping: false,
        },
      },
    }, { status: 409 }))
    const transport = new FetchTransport({ url: 'https://shop.example', fetch: fetchMock })

    const error = await transport.request({
      method: 'POST',
      path: '/wp-json/wc/store/v1/checkout',
      profile: 'session',
    }, new InMemorySessionStore({ cacheId: 'visitor-3' })).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(CartConflictError)
    expect(error).toMatchObject({ status: 409, code: 'woocommerce_rest_cart_error' })
    expect((error as CartConflictError).refreshedCart.totals.totalPrice).toBe('1200')
  })

  it.each([
    [401, AuthExpiredError],
    [403, AuthExpiredError],
    [404, NotFoundError],
    [429, RateLimitedError],
    [500, StoreApiError],
  ] as const)('maps HTTP %i to its typed error', async (status: number, ErrorType: Function) => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse(
      { code: `error_${status}`, message: `Failure ${status}` },
      { status, headers: status === 429 ? { 'Retry-After': '17' } : {} },
    ))
    const transport = new FetchTransport({ url: 'https://shop.example', fetch: fetchMock })

    const error = await transport.request({
      method: 'GET',
      path: '/wp-json/wc/store/v1/cart',
      profile: 'session',
    }, new InMemorySessionStore({ cacheId: 'visitor-4' })).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ErrorType)
    expect(error).toMatchObject({ status, code: `error_${status}` })
    if (status === 429) expect((error as RateLimitedError).retryAfterSeconds).toBe(17)
  })
})
