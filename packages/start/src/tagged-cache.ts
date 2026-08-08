import type { CacheProfileConfig } from '@woo/storefront-core'

export type ServerLife = CacheProfileConfig['serverLife']

export const SERVER_LIFE_MS: Readonly<Record<Exclude<ServerLife, 'none'>, number>> = {
  minutes: 5 * 60_000,
  hours: 60 * 60_000,
  days: 24 * 60 * 60_000,
}

interface CacheEntry<T> {
  expiresAt: number
  tags: ReadonlySet<string>
  value: Promise<T>
}

/**
 * TanStack Start has no framework data-cache equivalent to Next's tagged
 * cache. This intentionally small substitute is process-local: entries are
 * neither shared between instances nor durable across deploys/restarts.
 */
export class TaggedCache {
  private readonly entries = new Map<string, CacheEntry<unknown>>()
  private readonly now: () => number

  constructor(now: () => number = Date.now) {
    this.now = now
  }

  async getOrSet<T>(
    key: string,
    life: ServerLife,
    tags: readonly string[],
    load: () => Promise<T>,
  ): Promise<T> {
    if (life === 'none') return load()

    const existing = this.entries.get(key) as CacheEntry<T> | undefined
    if (existing && existing.expiresAt > this.now()) return existing.value
    if (existing) this.entries.delete(key)

    const value = Promise.resolve().then(load)
    const entry: CacheEntry<T> = {
      expiresAt: this.now() + SERVER_LIFE_MS[life],
      tags: new Set(tags),
      value,
    }
    this.entries.set(key, entry as CacheEntry<unknown>)

    void value.catch(() => {
      if (this.entries.get(key) === entry) this.entries.delete(key)
    })
    return value
  }

  invalidate(tag: string): number {
    let invalidated = 0
    for (const [key, entry] of this.entries) {
      if (entry.tags.has(tag)) {
        this.entries.delete(key)
        invalidated += 1
      }
    }
    return invalidated
  }

  delete(key: string): boolean {
    return this.entries.delete(key)
  }

  clear(): void {
    this.entries.clear()
  }

  get size(): number {
    return this.entries.size
  }
}
