import {
  AuthExpiredError,
  CartConflictError,
  NotFoundError,
  RateLimitedError,
  StoreApiError,
  type Cart,
} from '@woo/storefront-core'
import { describe, expect, it, vi } from 'vitest'

import { deserializeRpcResponse, RpcTransport } from '../src/rpc-transport'

const refreshedCart = {
  items: [],
  itemsCount: 0,
  totals: {
    totalItems: '0',
    totalPrice: '0',
    totalShipping: null,
    totalDiscount: '0',
    totalTax: '0',
    currencyCode: 'USD',
    currencyMinorUnit: 2,
  },
  coupons: [],
  needsShipping: false,
} satisfies Cart

describe('RpcTransport serialization', () => {
  it('serializes queries into GET query parameters and unwraps data', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(
      Response.json({ data: { items: [], total: 0, totalPages: 0 } }),
    )
    const transport = new RpcTransport({ basePath: '/custom/store/', fetch: fetchMock })

    await expect(transport.query('products.list', { perPage: 12 })).resolves.toEqual({
      items: [],
      total: 0,
      totalPages: 0,
    })

    const [url, init] = fetchMock.mock.calls[0]!
    const parsed = new URL(String(url), 'https://app.example')
    expect(parsed.pathname).toBe('/custom/store/query')
    expect(parsed.searchParams.get('op')).toBe('products.list')
    expect(JSON.parse(parsed.searchParams.get('args')!)).toEqual({ perPage: 12 })
    expect(init).toMatchObject({ method: 'GET', credentials: 'same-origin' })
  })

  it('serializes mutations as RpcRequest JSON', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(Response.json({ data: refreshedCart }))
    const transport = new RpcTransport({ fetch: fetchMock })

    await transport.mutate('cart.addItem', { id: 7, quantity: 2 })

    expect(fetchMock).toHaveBeenCalledWith('/api/store/rpc', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ op: 'cart.addItem', args: { id: 7, quantity: 2 } }),
    }))
  })
})

describe('RPC error deserialization', () => {
  it('reconstructs CartConflictError with its refreshed cart', () => {
    let thrown: unknown
    try {
      deserializeRpcResponse({
        error: {
          code: 'cart_conflict',
          status: 409,
          message: 'Cart changed',
          refreshedCart,
        },
      }, 409)
    } catch (error) {
      thrown = error
    }

    expect(thrown).toBeInstanceOf(CartConflictError)
    expect(thrown).toMatchObject({
      name: 'CartConflictError',
      code: 'cart_conflict',
      status: 409,
      refreshedCart,
    })
  })

  it.each([
    { status: 404, ErrorType: NotFoundError },
    { status: 401, ErrorType: AuthExpiredError },
    { status: 403, ErrorType: AuthExpiredError },
    { status: 429, ErrorType: RateLimitedError },
    { status: 500, ErrorType: StoreApiError },
  ])('maps status $status to the typed error hierarchy', ({
    status,
    ErrorType,
  }: { status: number; ErrorType: Function }) => {
    expect(() => deserializeRpcResponse({
      error: { code: 'failure', status, message: 'Failed' },
    }, status)).toThrow(ErrorType)
  })

  it('rejects malformed successful envelopes', () => {
    expect(() => deserializeRpcResponse({ ok: true })).toThrow(StoreApiError)
  })
})
