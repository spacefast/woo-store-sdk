import type { SessionData, SessionStore } from '@woo/storefront-core'

function isSessionData(value: unknown): value is SessionData {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false
  const record = value as Record<string, unknown>
  return (
    typeof record.cacheId === 'string' &&
    record.cacheId.length > 0 &&
    (record.cartToken === undefined || typeof record.cartToken === 'string')
  )
}

function defaultStorage(): Storage | null {
  try {
    return globalThis.sessionStorage ?? null
  } catch {
    // Some privacy modes throw on access.
    return null
  }
}

/**
 * Keeps the Store API Cart-Token in `sessionStorage`, so the cart survives
 * page loads within the tab. Falls back to memory when storage is unavailable
 * (server rendering, blocked storage). Never stores a customer token: the
 * browser adapter has no customer accounts.
 */
export class SessionStorageSessionStore implements SessionStore {
  private memory: SessionData | null = null
  private readonly storage: Storage | null

  constructor(
    private readonly key = 'woo-storefront:session',
    storage?: Storage | null,
  ) {
    this.storage = storage === undefined ? defaultStorage() : storage
  }

  async read(): Promise<SessionData> {
    const stored = this.load()
    if (stored) return { ...stored }
    const fresh: SessionData = { cacheId: globalThis.crypto.randomUUID() }
    await this.write(fresh)
    return { ...fresh }
  }

  async write(data: SessionData): Promise<void> {
    const { customerToken: _dropped, ...kept } = data
    this.memory = { ...kept }
    try {
      this.storage?.setItem(this.key, JSON.stringify(kept))
    } catch {
      // Quota or blocked storage: memory keeps this page working.
    }
  }

  async clear(): Promise<void> {
    this.memory = null
    try {
      this.storage?.removeItem(this.key)
    } catch {
      // Nothing to clear.
    }
  }

  private load(): SessionData | null {
    try {
      const raw = this.storage?.getItem(this.key)
      if (raw) {
        const parsed: unknown = JSON.parse(raw)
        if (isSessionData(parsed)) return parsed
      }
    } catch {
      // Corrupt or unreadable: fall through to memory.
    }
    return this.memory
  }
}
