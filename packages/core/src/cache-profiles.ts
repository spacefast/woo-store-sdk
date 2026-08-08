import type { CacheProfileConfig } from './contracts'

const MINUTE = 60_000
const HOUR = 60 * MINUTE
const DAY = 24 * HOUR

function productIdFrom(value: unknown): number | undefined {
  if (Array.isArray(value)) {
    for (const entry of value) {
      const id = productIdFrom(entry)
      if (id !== undefined) return id
    }
    return undefined
  }

  if (value === null || typeof value !== 'object') return undefined

  const record = value as Record<string, unknown>
  const candidate = record.productId ?? record.id
  if (typeof candidate === 'number' && Number.isFinite(candidate)) return candidate

  for (const entry of Object.values(record)) {
    const id = productIdFrom(entry)
    if (id !== undefined) return id
  }
  return undefined
}

/** Shared client/server cache policy taxonomy from the design specification. */
export const CACHE_PROFILES = {
  catalog: {
    staleTime: 5 * MINUTE,
    // Keep stale catalog data available while a background refresh runs.
    gcTime: 30 * MINUTE,
    serverTags: ({ params }) => {
      const productId = productIdFrom(params)
      return productId === undefined ? ['products'] : ['products', `product-${productId}`]
    },
    serverLife: 'hours',
    noStoreWithCustomerToken: false,
  },
  settings: {
    staleTime: HOUR,
    gcTime: DAY,
    serverTags: () => ['settings'],
    serverLife: 'days',
    noStoreWithCustomerToken: false,
  },
  session: {
    staleTime: 0,
    gcTime: 5 * MINUTE,
    serverTags: ({ cacheId }) => [`cart-${cacheId}`],
    serverLife: 'none',
    noStoreWithCustomerToken: true,
  },
} satisfies Record<'catalog' | 'settings' | 'session', CacheProfileConfig>
