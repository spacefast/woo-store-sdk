import { cookies } from 'next/headers'

import type { SessionCodec, SessionData, SessionStore } from '@woo/storefront-core'

export const SESSION_COOKIE_NAME = 'woo_session'
export const SESSION_MAX_AGE_SECONDS = 60 * 60 * 24 * 14

const encoder = new TextEncoder()
const decoder = new TextDecoder()

export interface CookieStoreLike {
  get(name: string): { value: string } | undefined
  set(
    name: string,
    value: string,
    options: {
      httpOnly: boolean
      secure: boolean
      sameSite: 'lax'
      maxAge: number
      path: string
    },
  ): void
  delete(name: string): void
}

export type CookieStoreProvider = () => CookieStoreLike | Promise<CookieStoreLike>

function bytesToBase64Url(bytes: Uint8Array): string {
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)

  return btoa(binary).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/u, '')
}

function base64UrlToBytes(value: string): Uint8Array | null {
  if (!/^[A-Za-z0-9_-]+$/u.test(value)) return null

  const base64 = value.replaceAll('-', '+').replaceAll('_', '/')
  const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, '=')

  try {
    const binary = atob(padded)
    return Uint8Array.from(binary, (character) => character.charCodeAt(0))
  } catch {
    return null
  }
}

async function hmac(payload: string, secret: string): Promise<Uint8Array> {
  const key = await globalThis.crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  )
  const signature = await globalThis.crypto.subtle.sign('HMAC', key, encoder.encode(payload))
  return new Uint8Array(signature)
}

function signaturesMatch(actual: Uint8Array, expected: Uint8Array): boolean {
  let difference = actual.length ^ expected.length
  const length = Math.max(actual.length, expected.length)

  for (let index = 0; index < length; index += 1) {
    difference |= (actual[index] ?? 0) ^ (expected[index] ?? 0)
  }

  return difference === 0
}

function isSessionData(value: unknown): value is SessionData {
  if (typeof value !== 'object' || value === null) return false

  const candidate = value as Record<string, unknown>
  return (
    typeof candidate.cacheId === 'string' &&
    candidate.cacheId.length > 0 &&
    (candidate.cartToken === undefined || typeof candidate.cartToken === 'string') &&
    (candidate.customerToken === undefined || typeof candidate.customerToken === 'string')
  )
}

/** HMAC-SHA256 implementation of core's SessionCodec contract. */
export const sessionCodec: SessionCodec = {
  async seal(data, secret) {
    if (!secret) throw new Error('A non-empty session secret is required')
    if (!isSessionData(data)) throw new TypeError('Cannot seal invalid session data')

    const payload = bytesToBase64Url(encoder.encode(JSON.stringify(data)))
    const signature = bytesToBase64Url(await hmac(payload, secret))
    return `${payload}.${signature}`
  },

  async unseal(cookieValue, secret) {
    if (!secret) return null

    const parts = cookieValue.split('.')
    if (parts.length !== 2) return null

    const [payload, encodedSignature] = parts
    if (!payload || !encodedSignature) return null

    const signature = base64UrlToBytes(encodedSignature)
    const payloadBytes = base64UrlToBytes(payload)
    if (!signature || !payloadBytes) return null

    const expected = await hmac(payload, secret)
    if (!signaturesMatch(signature, expected)) return null

    try {
      const parsed: unknown = JSON.parse(decoder.decode(payloadBytes))
      return isSessionData(parsed) ? parsed : null
    } catch {
      return null
    }
  },
}

function createCacheId(): string {
  if (typeof globalThis.crypto.randomUUID === 'function') {
    return globalThis.crypto.randomUUID()
  }

  return bytesToBase64Url(globalThis.crypto.getRandomValues(new Uint8Array(18)))
}

function requestCookieValue(header: string | undefined, name: string): string | undefined {
  if (!header) return undefined
  for (const pair of header.split(';')) {
    const separator = pair.indexOf('=')
    if (separator === -1 || pair.slice(0, separator).trim() !== name) continue
    const value = pair.slice(separator + 1).trim()
    if (!value) return undefined
    try {
      return decodeURIComponent(value)
    } catch {
      return value
    }
  }
  return undefined
}

function isReadOnlyCookieError(error: unknown): boolean {
  if (!(error instanceof Error)) return false
  return (
    error.name === 'ReadonlyRequestCookiesError' ||
    /cookies can only be modified|cookie.*read.?only/iu.test(error.message)
  )
}

/**
 * A request-scoped SessionStore backed by Next's async cookies() API.
 * Instantiate it inside a Route Handler, Server Action, or RSC invocation.
 */
export class CookieSessionStore implements SessionStore {
  private dataPromise: Promise<SessionData> | undefined

  constructor(
    private readonly secret: string,
    private readonly codec: SessionCodec = sessionCodec,
    private readonly cookieStoreProvider: CookieStoreProvider = cookies as CookieStoreProvider,
    private readonly requestCookieHeader?: string,
  ) {
    if (!secret) throw new Error('A non-empty session secret is required')
  }

  read(): Promise<SessionData> {
    this.dataPromise ??= this.readFromCookie()
    return this.dataPromise
  }

  async write(data: SessionData): Promise<void> {
    if (!isSessionData(data)) throw new TypeError('Cannot write invalid session data')

    const snapshot: SessionData = { ...data }
    this.dataPromise = Promise.resolve(snapshot)
    const cookieValue = await this.codec.seal(snapshot, this.secret)
    const cookieStore = await this.cookieStoreProvider()

    try {
      cookieStore.set(SESSION_COOKIE_NAME, cookieValue, {
        httpOnly: true,
        secure: true,
        sameSite: 'lax',
        maxAge: SESSION_MAX_AGE_SECONDS,
        path: '/',
      })
    } catch (error) {
      // RSC cookie stores are read-only. Reads still work; Route Handlers and
      // Server Actions remain writable and persist token rotations.
      if (!isReadOnlyCookieError(error)) throw error
    }
  }

  async clear(): Promise<void> {
    this.dataPromise = undefined
    const cookieStore = await this.cookieStoreProvider()

    try {
      cookieStore.delete(SESSION_COOKIE_NAME)
    } catch (error) {
      if (!isReadOnlyCookieError(error)) throw error
    }
  }

  private async readFromCookie(): Promise<SessionData> {
    const cookieStore = await this.cookieStoreProvider()
    const cookieValue =
      requestCookieValue(this.requestCookieHeader, SESSION_COOKIE_NAME) ??
      cookieStore.get(SESSION_COOKIE_NAME)?.value

    if (cookieValue) {
      const session = await this.codec.unseal(cookieValue, this.secret)
      if (session) return session
    }

    const session: SessionData = { cacheId: createCacheId() }
    await this.write(session)
    return session
  }
}
