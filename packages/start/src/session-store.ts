import { SessionCodec } from '@woo/storefront-core'
import type {
  SessionCodec as SessionCodecContract,
  SessionData,
  SessionStore,
} from '@woo/storefront-core'

import { deleteCookie, getCookie, setCookie } from './start-server'

export const SESSION_COOKIE_NAME = 'woo_session'
export const SESSION_MAX_AGE_SECONDS = 14 * 24 * 60 * 60

const COOKIE_OPTIONS = {
  httpOnly: true,
  secure: true,
  sameSite: 'lax' as const,
  path: '/',
  maxAge: SESSION_MAX_AGE_SECONDS,
}

function cacheId(): string {
  if (!globalThis.crypto?.randomUUID) {
    throw new Error('The Woo session store requires crypto.randomUUID()')
  }
  return globalThis.crypto.randomUUID()
}

/**
 * A request-context SessionStore backed by TanStack Start's cookie helpers.
 * Calls must run inside a Start server route, loader, or server function.
 */
export class StartCookieSessionStore implements SessionStore {
  private readonly secret: string
  private readonly codec: SessionCodecContract
  private dataPromise: Promise<SessionData> | undefined

  constructor(secret: string, codec: SessionCodecContract = new SessionCodec()) {
    if (!secret) throw new Error('createStorefront requires a non-empty sessionSecret')
    this.secret = secret
    this.codec = codec
  }

  read(): Promise<SessionData> {
    this.dataPromise ??= this.readFromCookie()
    return this.dataPromise
  }

  private async readFromCookie(): Promise<SessionData> {
    const cookie = getCookie(SESSION_COOKIE_NAME)
    if (cookie) {
      const session = await this.codec.unseal(cookie, this.secret)
      if (session) return session
    }

    const session = { cacheId: cacheId() }
    await this.write(session)
    return session
  }

  async write(data: SessionData): Promise<void> {
    const snapshot = { ...data }
    this.dataPromise = Promise.resolve(snapshot)
    const sealed = await this.codec.seal(snapshot, this.secret)
    setCookie(SESSION_COOKIE_NAME, sealed, COOKIE_OPTIONS)
  }

  async clear(): Promise<void> {
    this.dataPromise = undefined
    deleteCookie(SESSION_COOKIE_NAME, {
      httpOnly: COOKIE_OPTIONS.httpOnly,
      secure: COOKIE_OPTIONS.secure,
      sameSite: COOKIE_OPTIONS.sameSite,
      path: COOKIE_OPTIONS.path,
    })
  }
}
