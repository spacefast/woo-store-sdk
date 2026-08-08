import { revalidateTag, unstable_cache } from 'next/cache'

import {
  CACHE_PROFILES,
  type CacheProfile,
  type InvalidationTarget,
  type SessionData,
  type WooQueryOptions,
} from '@woo/storefront-core'

export type EagerWooQueryOptions<TData> = WooQueryOptions<TData> & Promise<TData>

const LIFE_SECONDS: Record<Exclude<ReturnType<typeof serverLife>, false>, number> = {
  minutes: 5 * 60,
  hours: 60 * 60,
  days: 24 * 60 * 60,
}

function serverLife(profile: CacheProfile): 'minutes' | 'hours' | 'days' | false {
  const life = CACHE_PROFILES[profile].serverLife
  return life === 'none' ? false : life
}

function stableValue(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(stableValue)
  if (typeof value !== 'object' || value === null) return value

  const sorted: Record<string, unknown> = {}
  for (const key of Object.keys(value).sort()) {
    const property = (value as Record<string, unknown>)[key]
    if (property !== undefined) sorted[key] = stableValue(property)
  }
  return sorted
}

function stableSerialize(value: unknown): string {
  return JSON.stringify(stableValue(value)) ?? 'undefined'
}

function queryParams(options: WooQueryOptions<unknown>): unknown {
  return options.queryKey[3]
}

export async function executeServerQuery<TData>(
  options: WooQueryOptions<TData>,
  session: SessionData,
  origin: string,
): Promise<TData> {
  const profile = CACHE_PROFILES[options.profile]
  const life = serverLife(options.profile)

  if (!life || session.customerToken) {
    return options.queryFn()
  }

  const params = queryParams(options)
  const tags = profile.serverTags({ cacheId: session.cacheId, params })
  const cached = unstable_cache(
    options.queryFn,
    ['woo-storefront-next', origin, stableSerialize(options.queryKey)],
    { tags, revalidate: LIFE_SECONDS[life] },
  )
  return cached()
}

export function eagerQuery<TData>(
  options: WooQueryOptions<TData>,
  execution: Promise<TData>,
): EagerWooQueryOptions<TData> {
  return Object.assign(execution, options)
}

function resourceTags(resource: InvalidationTarget & { type: 'resource' }, cacheId: string): string[] {
  switch (resource.resource) {
    case 'cart':
      return [`cart-${cacheId}`]
    case 'customer':
      return [`customer-${cacheId}`]
    case 'orders':
      return [`orders-${cacheId}`]
    case 'products':
      return ['products']
    case 'session-all':
      return [`cart-${cacheId}`, `customer-${cacheId}`, `orders-${cacheId}`]
  }
}

function keyTags(target: InvalidationTarget & { type: 'key' }, session: SessionData): string[] {
  const resource = target.key[1]
  if (resource === 'products' || resource === 'categories') {
    return CACHE_PROFILES.catalog.serverTags({ cacheId: session.cacheId, params: target.key[3] })
  }

  return [`${resource}-${session.cacheId}`]
}

export async function invalidateTargets(
  targets: InvalidationTarget[],
  session: SessionData,
): Promise<void> {
  const tags = new Set<string>()

  for (const target of targets) {
    const targetTags = target.type === 'resource' ? resourceTags(target, session.cacheId) : keyTags(target, session)
    for (const tag of targetTags) tags.add(tag)
  }

  await Promise.all([...tags].map(async (tag) => revalidateTag(tag)))
}
