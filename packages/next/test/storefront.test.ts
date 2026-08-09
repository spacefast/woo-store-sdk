import { beforeEach, describe, expect, it, vi } from 'vitest'

import { RateLimitedError } from '@woo/storefront-core'
import type {
  CacheProfile,
  InvalidationTarget,
  SessionStore,
  StorefrontClient,
  WooMutationOptions,
  WooQueryOptions,
} from '@woo/storefront-core'

const state = vi.hoisted(() => ({
  cookieJar: new Map<string, string>(),
  cookieOptions: new Map<string, unknown>(),
  cacheWraps: 0,
  revalidatedTags: [] as string[],
  clientFactory: undefined as unknown as (config: unknown, session: unknown) => unknown,
}))

vi.mock('next/headers', () => ({
  cookies: vi.fn(async () => ({
    get(name: string) {
      const value = state.cookieJar.get(name)
      return value === undefined ? undefined : { value }
    },
    set(name: string, value: string, options: unknown) {
      state.cookieJar.set(name, value)
      state.cookieOptions.set(name, options)
    },
    delete(name: string) {
      state.cookieJar.delete(name)
      state.cookieOptions.delete(name)
    },
  })),
}))

vi.mock('next/cache', () => ({
  unstable_cache<TArgs extends unknown[], TResult>(callback: (...args: TArgs) => TResult) {
    state.cacheWraps += 1
    return callback
  },
  revalidateTag(tag: string) {
    state.revalidatedTags.push(tag)
  },
}))

vi.mock('@woo/storefront-core', () => {
  class StoreApiError extends Error {
    constructor(
      message: string,
      readonly status: number,
      readonly code: string,
    ) {
      super(message)
    }
  }

  class CartConflictError extends StoreApiError {
    constructor(
      message: string,
      readonly refreshedCart: unknown,
    ) {
      super(message, 409, 'cart_conflict')
    }
  }

  class RateLimitedError extends StoreApiError {
    constructor(
      message: string,
      readonly retryAfterSeconds?: number,
    ) {
      super(message, 429, 'rate_limited')
    }
  }

  return {
    CACHE_PROFILES: {
      catalog: {
        staleTime: 300_000,
        gcTime: 3_600_000,
        serverTags: ({ params }: { params?: unknown }) => {
          const serialized = JSON.stringify(params)
          const id = serialized?.match(/\d+/u)?.[0]
          return id ? ['products', `product-${id}`] : ['products']
        },
        serverLife: 'minutes',
        noStoreWithCustomerToken: true,
      },
      settings: {
        staleTime: 3_600_000,
        gcTime: 86_400_000,
        serverTags: () => ['settings'],
        serverLife: 'days',
        noStoreWithCustomerToken: true,
      },
      session: {
        staleTime: 0,
        gcTime: 0,
        serverTags: ({ cacheId }: { cacheId: string }) => [`cart-${cacheId}`],
        serverLife: 'none',
        noStoreWithCustomerToken: true,
      },
    },
    StoreApiError,
    CartConflictError,
    RateLimitedError,
    createStorefrontClient(config: unknown, session: unknown) {
      return state.clientFactory(config, session)
    },
  }
})

import {
  CookieSessionStore,
  SESSION_COOKIE_NAME,
  SESSION_MAX_AGE_SECONDS,
  createStorefront,
  sessionCodec,
} from '../src/index'

function query<TData>(
  resource: string,
  operation: string,
  queryFn: () => Promise<TData>,
  profile: CacheProfile = 'catalog',
): WooQueryOptions<TData> {
  return { queryKey: ['woo', resource, operation], queryFn, profile }
}

function mutation<TData, TVariables>(
  data: TData,
  invalidates: InvalidationTarget[] = [],
): WooMutationOptions<TData, TVariables> {
  return {
    mutationKey: ['woo', 'test', 'mutation'],
    mutationFn: async () => data,
    invalidates,
  }
}

function clientFor(session: SessionStore): StorefrontClient {
  return {
    products: {
      list: () => query('products', 'list', async () => ({ items: [], total: 0, totalPages: 0 })),
      bySlug: (slug) => query('products', 'bySlug', async () => ({ slug }) as never),
      byId: (id) => query('products', 'byId', async () => ({ id }) as never),
    },
    categories: {
      list: () => query('categories', 'list', async () => []),
      bySlug: (slug) => query('categories', 'bySlug', async () => ({ slug }) as never),
    },
    cart: {
      get: () => query('cart', 'get', async () => ({}) as never, 'session'),
      addItem: mutation({}, [{ type: 'resource', resource: 'cart' }]) as never,
      updateItem: mutation({}, [{ type: 'resource', resource: 'cart' }]) as never,
      removeItem: mutation({}, [{ type: 'resource', resource: 'cart' }]) as never,
      applyCoupon: mutation({}, [{ type: 'resource', resource: 'cart' }]) as never,
      removeCoupon: mutation({}, [{ type: 'resource', resource: 'cart' }]) as never,
      checkoutUrl: async () => 'https://woo.test/checkout',
    },
    checkout: {
      get: () => query('checkout', 'get', async () => ({}), 'session'),
      submit: mutation({}, [{ type: 'resource', resource: 'cart' }]),
    },
    auth: {
      login: mutation({}, [{ type: 'resource', resource: 'session-all' }]) as never,
      logout: mutation<void, void>(undefined, [{ type: 'resource', resource: 'session-all' }]),
      register: mutation({}, [{ type: 'resource', resource: 'session-all' }]) as never,
    },
    customer: {
      get: () => query('customer', 'get', async () => ({}) as never, 'session'),
      orders: () => query('orders', 'list', async () => ({ items: [], total: 0 }), 'session'),
      order: () => query('orders', 'byId', async () => ({}) as never, 'session'),
      updateProfile: mutation({}, [{ type: 'resource', resource: 'customer' }]) as never,
      updateAddress: mutation({}, [{ type: 'resource', resource: 'customer' }]) as never,
    },
  }
}

beforeEach(() => {
  state.cookieJar.clear()
  state.cookieOptions.clear()
  state.cacheWraps = 0
  state.revalidatedTags.length = 0
  state.clientFactory = (_config, session) => clientFor(session as SessionStore)
})

describe('RPC handlers', () => {
  it('dispatches GET query and POST mutation dot paths', async () => {
    const storefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })
    const getResponse = await storefront.handlers.GET(
      new Request('https://app.test/api/store/query?op=products.list&args=%7B%22perPage%22%3A12%7D'),
    )

    expect(getResponse.status).toBe(200)
    expect(getResponse.headers.get('cache-control')).toBe('private, no-store')
    expect(await getResponse.json()).toEqual({ data: { items: [], total: 0, totalPages: 0 } })

    const postResponse = await storefront.handlers.POST(
      new Request('https://app.test/api/store/rpc', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ op: 'cart.addItem', args: { id: 42, quantity: 2 } }),
      }),
    )

    expect(postResponse.status).toBe(200)
    expect(postResponse.headers.get('cache-control')).toBe('private, no-store')
    expect(await postResponse.json()).toEqual({ data: {} })

    state.clientFactory = (_config, session) => {
      const client = clientFor(session as SessionStore)
      client.auth.logout = {
        mutationKey: ['woo', 'auth', 'logout'],
        invalidates: [],
        mutationFn: async () => {
          throw new RateLimitedError('Slow down.', 17)
        },
      }
      return client
    }
    const limitedStorefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })
    const limitedResponse = await limitedStorefront.handlers.POST(
      new Request('https://app.test/api/store/rpc', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ op: 'auth.logout' }),
      }),
    )

    expect(limitedResponse.status).toBe(429)
    expect(limitedResponse.headers.get('retry-after')).toBe('17')
    expect(await limitedResponse.json()).toEqual({
      error: {
        code: 'rate_limited',
        status: 429,
        message: 'Slow down.',
        retryAfterSeconds: 17,
      },
    })
  })

  it('serializes void mutation results as data: null so the RPC envelope survives', async () => {
    const storefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })
    const response = await storefront.handlers.POST(
      new Request('https://app.test/api/store/rpc', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ op: 'auth.logout' }),
      }),
    )

    expect(response.status).toBe(200)
    const body = await response.json()
    expect(body).toEqual({ data: null })
    expect(Object.prototype.hasOwnProperty.call(body, 'data')).toBe(true)
  })

  it('reads the signed session from the Route Handler request', async () => {
    const sealed = await sessionCodec.seal(
      { cacheId: 'visitor-from-request', cartToken: 'request-cart-token' },
      'test-secret',
    )
    state.clientFactory = (_config, rawSession) => {
      const requestSession = rawSession as SessionStore
      const client = clientFor(requestSession)
      client.cart.get = () =>
        query('cart', 'get', async () => {
          const session = await requestSession.read()
          return { items: [], itemsCount: session.cartToken === 'request-cart-token' ? 2 : 0 } as never
        }, 'session')
      return client
    }

    const storefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })
    const response = await storefront.handlers.GET(
      new Request('https://app.test/api/store/query?op=cart.get', {
        headers: { cookie: `${SESSION_COOKIE_NAME}=${sealed}` },
      }),
    )

    expect(response.status).toBe(200)
    expect(await response.json()).toEqual({ data: { items: [], itemsCount: 2 } })
  })
})

describe('cookie sessions', () => {
  it('seals, persists, and unseals a session with hardened cookie attributes', async () => {
    const store = new CookieSessionStore('test-secret')
    await store.write({ cacheId: 'visitor-1', cartToken: 'cart-token' })

    const cookie = state.cookieJar.get(SESSION_COOKIE_NAME)
    expect(cookie).toBeTypeOf('string')
    expect(await sessionCodec.unseal(cookie!, 'test-secret')).toEqual({
      cacheId: 'visitor-1',
      cartToken: 'cart-token',
    })
    expect(await sessionCodec.unseal(`${cookie!}tampered`, 'test-secret')).toBeNull()
    expect(state.cookieOptions.get(SESSION_COOKIE_NAME)).toEqual({
      httpOnly: true,
      secure: true,
      sameSite: 'lax',
      maxAge: SESSION_MAX_AGE_SECONDS,
      path: '/',
    })
  })

  it('re-seals a rotated Cart-Token before returning an RPC response', async () => {
    state.clientFactory = (_config, rawSession) => {
      const session = rawSession as SessionStore
      const client = clientFor(session)
      client.cart.addItem = {
        ...client.cart.addItem,
        async mutationFn() {
          const current = await session.read()
          await session.write({ ...current, cartToken: 'rotated-cart-token' })
          return {} as never
        },
      }
      return client
    }

    const storefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })
    const response = await storefront.handlers.POST(
      new Request('https://app.test/api/store/rpc', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ op: 'cart.addItem', args: { id: 42 } }),
      }),
    )

    expect(response.status).toBe(200)
    const cookie = state.cookieJar.get(SESSION_COOKIE_NAME)
    expect(cookie).toBeDefined()
    expect(await sessionCodec.unseal(cookie!, 'test-secret')).toMatchObject({
      cartToken: 'rotated-cart-token',
    })
  })
})

describe('server caching', () => {
  it('awaits request-scoped cart queries as cart data', async () => {
    const expected = { items: [], itemsCount: 0 }
    state.clientFactory = (_config, rawSession) => {
      const client = clientFor(rawSession as SessionStore)
      client.cart.get = () => query('cart', 'get', async () => expected as never, 'session')
      return client
    }

    const storefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })

    expect(await storefront.cart.get()).toBe(expected)
  })

  it('bypasses Next caching whenever the request session has a customer token', async () => {
    state.cookieJar.set(
      SESSION_COOKIE_NAME,
      await sessionCodec.seal(
        { cacheId: 'customer-1', cartToken: 'cart-token', customerToken: 'customer-token' },
        'test-secret',
      ),
    )
    const queryFn = vi.fn(async () => ({ items: [], total: 0, totalPages: 0 }))
    state.clientFactory = (_config, rawSession) => {
      const client = clientFor(rawSession as SessionStore)
      client.products.list = () => query('products', 'list', queryFn)
      return client
    }

    const storefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })
    await storefront.products.list()

    expect(queryFn).toHaveBeenCalledOnce()
    expect(state.cacheWraps).toBe(0)
  })
})

describe('webhook revalidation', () => {
  it('accepts the feature plugin HMAC signature over the exact request body', async () => {
    const storefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })
    const handler = storefront.revalidateHandler({ secret: 'webhook-secret' })
    const body = JSON.stringify({ event: 'stock.updated', productIds: [42] })
    const key = await crypto.subtle.importKey(
      'raw',
      new TextEncoder().encode('webhook-secret'),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['sign'],
    )
    const bytes = new Uint8Array(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body)))
    const signature = `sha256=${[...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')}`

    const response = await handler(
      new Request('https://app.test/api/store/revalidate', {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'x-woo-storefront-signature': signature,
        },
        body,
      }),
    )

    expect(response.status).toBe(200)
    expect(await response.json()).toEqual({ revalidated: true, tags: ['products', 'product-42'] })
  })

  it('maps product ids and scopes to the shared cache tag taxonomy', async () => {
    const storefront = createStorefront({ url: 'https://woo.test', sessionSecret: 'test-secret' })
    const handler = storefront.revalidateHandler({ secret: 'webhook-secret' })
    const response = await handler(
      new Request('https://app.test/api/store/revalidate', {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'x-woo-webhook-secret': 'webhook-secret',
        },
        body: JSON.stringify({ product_ids: [12, 34], scopes: ['stock', 'settings'] }),
      }),
    )

    expect(response.status).toBe(200)
    expect(await response.json()).toEqual({
      revalidated: true,
      tags: ['products', 'product-12', 'product-34', 'settings'],
    })
    expect(state.revalidatedTags).toEqual(['products', 'product-12', 'product-34', 'settings'])
  })
})
