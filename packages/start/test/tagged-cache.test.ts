import { describe, expect, it, vi } from 'vitest'

import { SERVER_LIFE_MS, TaggedCache } from '../src/tagged-cache'

describe('TaggedCache', () => {
  it('reuses an entry for its serverLife and reloads it after expiry', async () => {
    let now = 1_000
    const cache = new TaggedCache(() => now)
    let version = 0
    const load = vi.fn(async () => ({ version: ++version }))

    const first = await cache.getOrSet('products', 'minutes', ['products'], load)
    const cached = await cache.getOrSet('products', 'minutes', ['products'], load)

    expect(cached).toBe(first)
    expect(load).toHaveBeenCalledOnce()

    now += SERVER_LIFE_MS.minutes
    const refreshed = await cache.getOrSet('products', 'minutes', ['products'], load)

    expect(refreshed).not.toBe(first)
    expect(load).toHaveBeenCalledTimes(2)
  })

  it('invalidates every entry carrying a tag and leaves unrelated entries intact', async () => {
    const cache = new TaggedCache()
    await cache.getOrSet('product-1', 'hours', ['products', 'product-1'], async () => 1)
    await cache.getOrSet('product-2', 'hours', ['products', 'product-2'], async () => 2)
    await cache.getOrSet('settings', 'days', ['settings'], async () => 3)

    expect(cache.invalidate('products')).toBe(2)
    expect(cache.size).toBe(1)
    expect(cache.invalidate('settings')).toBe(1)
    expect(cache.size).toBe(0)
  })

  it('never stores entries whose serverLife is none', async () => {
    const cache = new TaggedCache()
    const load = vi.fn(async () => 'cart')

    await cache.getOrSet('cart', 'none', ['cart-visitor-1'], load)
    await cache.getOrSet('cart', 'none', ['cart-visitor-1'], load)

    expect(load).toHaveBeenCalledTimes(2)
    expect(cache.size).toBe(0)
  })

  it('evicts a rejected load so a later request can retry', async () => {
    const cache = new TaggedCache()
    const failure = new Error('temporary failure')

    await expect(cache.getOrSet('products', 'minutes', ['products'], async () => {
      throw failure
    })).rejects.toBe(failure)
    expect(cache.size).toBe(0)
    await expect(cache.getOrSet('products', 'minutes', ['products'], async () => 'recovered'))
      .resolves.toBe('recovered')
  })
})
