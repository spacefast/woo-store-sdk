import type { Cart, InvalidationTarget } from '@woo/storefront-core'
import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'

import {
  applyOptimisticCartUpdate,
  queryKeysForInvalidationTarget,
  rollbackOptimisticCartUpdate,
} from '../src/cache'

function cart(quantity: number): Cart {
  return {
    items: [{
      key: 'line-1',
      id: 7,
      name: 'Beanie',
      quantity,
      images: [],
      prices: { price: '1200', currencyCode: 'USD', currencyMinorUnit: 2 },
      totals: { lineTotal: '1200', lineSubtotal: '1200' },
      variation: [],
    }],
    itemsCount: quantity,
    totals: {
      totalItems: '1200',
      totalPrice: '1200',
      totalShipping: null,
      totalDiscount: '0',
      totalTax: '0',
      currencyCode: 'USD',
      currencyMinorUnit: 2,
    },
    coupons: [],
    needsShipping: true,
  }
}

describe('queryKeysForInvalidationTarget', () => {
  it('translates a resource target to its woo resource prefix', () => {
    expect(queryKeysForInvalidationTarget({ type: 'resource', resource: 'cart' })).toEqual([
      ['woo', 'cart'],
    ])
  })

  it('preserves an explicit query key', () => {
    const target: InvalidationTarget = {
      type: 'key',
      key: ['woo', 'products', 'byId', { id: 42 }],
    }
    expect(queryKeysForInvalidationTarget(target)).toEqual([target.key])
  })

  it('expands session-all without invalidating catalog data', () => {
    expect(queryKeysForInvalidationTarget({ type: 'resource', resource: 'session-all' })).toEqual([
      ['woo', 'cart'],
      ['woo', 'checkout'],
      ['woo', 'customer'],
      ['woo', 'orders'],
    ])
  })
})

describe('optimistic cart rollback', () => {
  it('restores every cart cache snapshot after an optimistic update fails', async () => {
    const queryClient = new QueryClient()
    const cartKey = ['woo', 'cart', 'get'] as const
    const alternateCartKey = ['woo', 'cart', 'summary'] as const
    const original = cart(1)
    const alternate = cart(3)
    queryClient.setQueryData(cartKey, original)
    queryClient.setQueryData(alternateCartKey, alternate)
    queryClient.setQueryData(['woo', 'products', 'list'], { items: [] })

    const context = await applyOptimisticCartUpdate(
      queryClient,
      ({ quantity }: { quantity: number }, current) => current
        ? { ...current, itemsCount: quantity }
        : undefined,
      { quantity: 9 },
    )

    expect(queryClient.getQueryData<Cart>(cartKey)?.itemsCount).toBe(9)
    expect(queryClient.getQueryData<Cart>(alternateCartKey)?.itemsCount).toBe(9)

    rollbackOptimisticCartUpdate(queryClient, context)

    expect(queryClient.getQueryData(cartKey)).toEqual(original)
    expect(queryClient.getQueryData(alternateCartKey)).toEqual(alternate)
    expect(queryClient.getQueryData(['woo', 'products', 'list'])).toEqual({ items: [] })
  })
})
