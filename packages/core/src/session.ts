import type { SessionCodec as SessionCodecContract, SessionData, SessionStore } from './contracts'

const encoder = new TextEncoder()
const decoder = new TextDecoder()

function webCrypto(): Crypto {
  const cryptoApi = globalThis.crypto
  if (!cryptoApi?.subtle) {
    throw new Error('SessionCodec requires a WebCrypto implementation')
  }
  return cryptoApi
}

function bytesToBase64Url(bytes: Uint8Array): string {
  let binary = ''
  for (let index = 0; index < bytes.length; index += 1) {
    binary += String.fromCharCode(bytes[index] ?? 0)
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/u, '')
}

function base64UrlToBytes(value: string): Uint8Array | null {
  if (!/^[A-Za-z0-9_-]+$/u.test(value)) return null
  const padding = '='.repeat((4 - (value.length % 4)) % 4)
  try {
    const binary = atob(value.replace(/-/g, '+').replace(/_/g, '/') + padding)
    return Uint8Array.from(binary, (character) => character.charCodeAt(0))
  } catch {
    return null
  }
}

async function signingKey(secret: string): Promise<CryptoKey> {
  return webCrypto().subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  )
}

async function signature(payload: string, secret: string): Promise<Uint8Array> {
  const signed = await webCrypto().subtle.sign('HMAC', await signingKey(secret), encoder.encode(payload))
  return new Uint8Array(signed)
}

/** Compares every byte even when the lengths differ. */
function constantTimeEqual(left: Uint8Array, right: Uint8Array): boolean {
  const length = Math.max(left.length, right.length)
  let difference = left.length ^ right.length
  for (let index = 0; index < length; index += 1) {
    difference |= (left[index] ?? 0) ^ (right[index] ?? 0)
  }
  return difference === 0
}

function isSessionData(value: unknown): value is SessionData {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false
  const record = value as Record<string, unknown>
  return (
    typeof record.cacheId === 'string' &&
    record.cacheId.length > 0 &&
    (record.cartToken === undefined || typeof record.cartToken === 'string') &&
    (record.customerToken === undefined || typeof record.customerToken === 'string')
  )
}

export class SessionCodec implements SessionCodecContract {
  async seal(data: SessionData, secret: string): Promise<string> {
    if (!secret) throw new Error('A non-empty session secret is required')
    if (!isSessionData(data)) throw new TypeError('Invalid session data')

    const payload = bytesToBase64Url(encoder.encode(JSON.stringify(data)))
    const mac = bytesToBase64Url(await signature(payload, secret))
    return `${payload}.${mac}`
  }

  async unseal(cookieValue: string, secret: string): Promise<SessionData | null> {
    if (!secret) return null
    const parts = cookieValue.split('.')
    if (parts.length !== 2) return null

    const payload = parts[0]
    const suppliedMac = base64UrlToBytes(parts[1] ?? '')
    if (!payload || !suppliedMac) return null

    const expectedMac = await signature(payload, secret)
    if (!constantTimeEqual(suppliedMac, expectedMac)) return null

    const payloadBytes = base64UrlToBytes(payload)
    if (!payloadBytes) return null
    try {
      const parsed: unknown = JSON.parse(decoder.decode(payloadBytes))
      if (!isSessionData(parsed)) return null
      return {
        cacheId: parsed.cacheId,
        ...(parsed.cartToken === undefined ? {} : { cartToken: parsed.cartToken }),
        ...(parsed.customerToken === undefined ? {} : { customerToken: parsed.customerToken }),
      }
    } catch {
      return null
    }
  }
}

function newCacheId(): string {
  return webCrypto().randomUUID()
}

/** Request-local in-memory session implementation intended for tests and tools. */
export class InMemorySessionStore implements SessionStore {
  private data: SessionData

  constructor(initial?: SessionData) {
    this.data = { ...(initial ?? { cacheId: newCacheId() }) }
  }

  async read(): Promise<SessionData> {
    return { ...this.data }
  }

  async write(data: SessionData): Promise<void> {
    this.data = { ...data }
  }

  async clear(): Promise<void> {
    this.data = { cacheId: newCacheId() }
  }
}
