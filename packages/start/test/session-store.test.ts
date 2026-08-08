import { cookieState, resetCookieState } from './start-server.mock'

import { SessionCodec } from '@woo/storefront-core'
import { beforeEach, describe, expect, it } from 'vitest'

import {
  SESSION_COOKIE_NAME,
  SESSION_MAX_AGE_SECONDS,
  StartCookieSessionStore,
} from '../src/index'

const secret = 'test-session-secret'

beforeEach(resetCookieState)

describe('StartCookieSessionStore', () => {
  it('round-trips a core-sealed session with the adapter cookie attributes', async () => {
    const store = new StartCookieSessionStore(secret)
    await store.write({
      cacheId: 'visitor-1',
      cartToken: 'cart-token',
      customerToken: 'customer-token',
    })

    const cookie = cookieState.values.get(SESSION_COOKIE_NAME)
    expect(cookie).toBeTypeOf('string')
    await expect(new SessionCodec().unseal(cookie!, secret)).resolves.toEqual({
      cacheId: 'visitor-1',
      cartToken: 'cart-token',
      customerToken: 'customer-token',
    })
    await expect(new StartCookieSessionStore(secret).read()).resolves.toEqual({
      cacheId: 'visitor-1',
      cartToken: 'cart-token',
      customerToken: 'customer-token',
    })
    expect(cookieState.setCalls.at(-1)).toMatchObject({
      name: SESSION_COOKIE_NAME,
      options: {
        httpOnly: true,
        secure: true,
        sameSite: 'lax',
        path: '/',
        maxAge: SESSION_MAX_AGE_SECONDS,
      },
    })
  })

  it('rejects a tampered cookie and replaces it with a fresh guest session', async () => {
    const codec = new SessionCodec()
    const valid = await codec.seal({ cacheId: 'visitor-1', cartToken: 'cart-token' }, secret)
    cookieState.values.set(SESSION_COOKIE_NAME, `${valid}tampered`)

    const session = await new StartCookieSessionStore(secret).read()

    expect(session.cacheId).not.toBe('visitor-1')
    expect(session.cacheId).toBeTypeOf('string')
    expect(session.cartToken).toBeUndefined()
    expect(cookieState.setCalls).toHaveLength(1)
    await expect(codec.unseal(cookieState.values.get(SESSION_COOKIE_NAME)!, secret)).resolves.toEqual(session)
  })

  it('clears the cookie using the same security and path attributes', async () => {
    const store = new StartCookieSessionStore(secret)
    await store.write({ cacheId: 'visitor-1' })
    await store.clear()

    expect(cookieState.values.has(SESSION_COOKIE_NAME)).toBe(false)
    expect(cookieState.deleteCalls).toEqual([
      {
        name: SESSION_COOKIE_NAME,
        options: {
          httpOnly: true,
          secure: true,
          sameSite: 'lax',
          path: '/',
        },
      },
    ])
  })
})
