import { describe, expect, it, vi } from 'vitest'

import { CACHE_PROFILES, createStorefrontClient, InMemorySessionStore } from '../src/index'
import type { Cart, Transport } from '../src/contracts'

function unusedTransport(): Transport {
  return {
    request: vi.fn(() => Promise.reject(new Error('Unexpected transport call'))) as Transport['request'],
  }
}

const cart: Cart = {
  items: [
    {
      key: 'line-a',
      id: 11,
      name: 'Mug',
      quantity: 2,
      images: [],
      prices: { price: '1000', currencyCode: 'USD', currencyMinorUnit: 2 },
      totals: { lineTotal: '2000', lineSubtotal: '2000' },
      variation: [],
    },
    {
      key: 'line-b',
      id: 12,
      name: 'Shirt',
      quantity: 1,
      images: [],
      prices: { price: '2500', currencyCode: 'USD', currencyMinorUnit: 2 },
      totals: { lineTotal: '2500', lineSubtotal: '2500' },
      variation: [{ attribute: 'size', value: 'M' }],
    },
  ],
  itemsCount: 3,
  totals: {
    totalItems: '4500',
    totalPrice: '4500',
    totalShipping: null,
    totalDiscount: '0',
    totalTax: '0',
    currencyCode: 'USD',
    currencyMinorUnit: 2,
  },
  coupons: [],
  needsShipping: true,
  raw: { original: true },
}

describe('cache profiles and query factories', () => {
  const client = createStorefrontClient(
    { url: 'https://shop.example' },
    new InMemorySessionStore({ cacheId: 'cache-123' }),
    unusedTransport(),
  )

  it('exposes the specified cache timings, tags, and no-store invariant', () => {
    expect(CACHE_PROFILES.catalog.staleTime).toBe(5 * 60_000)
    expect(CACHE_PROFILES.settings.staleTime).toBe(60 * 60_000)
    expect(CACHE_PROFILES.session.staleTime).toBe(0)
    expect(CACHE_PROFILES.catalog.serverTags({ cacheId: 'x', params: { id: 42 } })).toEqual([
      'products',
      'product-42',
    ])
    expect(CACHE_PROFILES.session.serverTags({ cacheId: 'cache-123' })).toEqual(['cart-cache-123'])
    expect(CACHE_PROFILES.session.noStoreWithCustomerToken).toBe(true)
  })

  it('assigns stable query keys and profiles', () => {
    expect(client.products.list({ perPage: 24 }).queryKey).toEqual([
      'woo',
      'products',
      'list',
      { perPage: 24 },
    ])
    expect(client.products.list().profile).toBe('catalog')
    expect(client.products.byId(17).queryKey).toEqual(['woo', 'products', 'byId', { id: 17 }])
    expect(client.categories.list().queryKey).toEqual(['woo', 'categories', 'list'])
    expect(client.cart.get()).toMatchObject({ queryKey: ['woo', 'cart', 'get'], profile: 'session' })
    expect(client.customer.orders({ page: 2 }).queryKey).toEqual([
      'woo',
      'orders',
      'list',
      { page: 2 },
    ])
  })
})

describe('mutation metadata and optimistic cart updates', () => {
  const client = createStorefrontClient(
    { url: 'https://shop.example' },
    new InMemorySessionStore({ cacheId: 'cache-456' }),
    unusedTransport(),
  )

  it('declares resource invalidation blast radii', () => {
    expect(client.cart.applyCoupon.invalidates).toEqual([{ type: 'resource', resource: 'cart' }])
    expect(client.cart.removeCoupon.invalidates).toEqual([{ type: 'resource', resource: 'cart' }])
    expect(client.auth.login.invalidates).toEqual([{ type: 'resource', resource: 'session-all' }])
    expect(client.auth.logout.invalidates).toEqual([{ type: 'resource', resource: 'session-all' }])
    expect(client.customer.updateProfile.invalidates).toEqual([
      { type: 'resource', resource: 'customer' },
    ])
    expect(client.customer.updateAddress.invalidates).toEqual([
      { type: 'resource', resource: 'customer' },
    ])
    expect(client.checkout.submit.invalidates).toEqual([
      { type: 'resource', resource: 'cart' },
      { type: 'resource', resource: 'orders' },
    ])
  })

  it('optimistically increments an existing matching item without mutating the current cart', () => {
    const next = client.cart.addItem.optimisticUpdate?.({ id: 11, quantity: 3 }, cart)

    expect(next?.items[0]?.quantity).toBe(5)
    expect(next?.itemsCount).toBe(6)
    expect(next?.raw).toBeUndefined()
    expect(cart.items[0]?.quantity).toBe(2)
    expect(cart.itemsCount).toBe(3)
  })

  it('adds a temporary line when the product is not already in the cart', () => {
    const next = client.cart.addItem.optimisticUpdate?.(
      { id: 99, quantity: 2, variation: { color: 'blue' } },
      cart,
    )

    expect(next?.items.at(-1)).toMatchObject({
      key: expect.stringMatching(/^optimistic-99-/u),
      id: 99,
      quantity: 2,
      variation: [{ attribute: 'color', value: 'blue' }],
    })
    expect(next?.itemsCount).toBe(5)
  })

  it('optimistically updates and removes lines', () => {
    const updated = client.cart.updateItem.optimisticUpdate?.({ key: 'line-b', quantity: 4 }, cart)
    const removed = client.cart.removeItem.optimisticUpdate?.({ key: 'line-a' }, cart)

    expect(updated?.items.find(({ key }) => key === 'line-b')?.quantity).toBe(4)
    expect(updated?.itemsCount).toBe(6)
    expect(removed?.items.map(({ key }) => key)).toEqual(['line-b'])
    expect(removed?.itemsCount).toBe(1)
  })

  it('leaves an absent cache value absent', () => {
    expect(client.cart.addItem.optimisticUpdate?.({ id: 1 }, undefined)).toBeUndefined()
    expect(client.cart.updateItem.optimisticUpdate?.({ key: 'x', quantity: 1 }, undefined)).toBeUndefined()
    expect(client.cart.removeItem.optimisticUpdate?.({ key: 'x' }, undefined)).toBeUndefined()
  })
})
