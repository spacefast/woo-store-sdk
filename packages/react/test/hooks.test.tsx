// @vitest-environment happy-dom
/**
 * One hook suite, two transports. Every case runs through `RpcTransport` (the
 * server-adapter protocol, here backed by a minimal RPC route over the core
 * client) and through the browser adapter, against the same Woo stand-in built
 * from captured Store API responses. A hook that behaves differently on one
 * transport fails here.
 */
import {
  createStorefrontClient,
  InMemorySessionStore,
  NotFoundError,
  StoreApiError,
  type StorefrontClient,
} from '@woo/storefront-core'
import { BrowserTransport, SessionStorageSessionStore } from '@woo/storefront-browser'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'

import {
  createWooQueryClient,
  RpcTransport,
  StoreProvider,
  useAddToCart,
  useCart,
  useCheckoutUrl,
  useProduct,
  useProducts,
  useRemoveCartItem,
  useUpdateCartItem,
  type StoreTransport,
} from '../src'
import { createFakeWoo, WOO_ORIGIN, type FakeWoo } from '../../browser/test/support/fake-woo'
import { MemoryStorage } from '../../browser/test/support/memory-storage'

const PAPERBACK = { id: 20, slug: 'the-field-guide-paperback' }

/** The `/api/store` protocol as the Next/Start adapters serve it, minus their caching. */
function rpcRoute(woo: FakeWoo): typeof fetch {
  const client = createStorefrontClient(
    { url: WOO_ORIGIN, fetch: woo.fetch },
    new InMemorySessionStore({ cacheId: 'rpc-visitor' }),
  )
  return async (input, init) => {
    const url = new URL(String(input), 'https://shop.example')
    const { op, args } = init?.method === 'POST'
      ? JSON.parse(String(init.body)) as { op: string; args?: unknown }
      : {
          op: url.searchParams.get('op')!,
          args: url.searchParams.has('args') ? JSON.parse(url.searchParams.get('args')!) as unknown : undefined,
        }
    const [resource, operation] = op.split('.') as [keyof StorefrontClient, string]
    const target = (client[resource] as Record<string, unknown>)[operation]
    try {
      let data: unknown
      if (typeof target === 'function') {
        data = await (args === undefined ? target() : target(args))
        if (data && typeof data === 'object' && 'queryFn' in data) {
          data = await (data as { queryFn: () => Promise<unknown> }).queryFn()
        }
      } else {
        data = await (target as { mutationFn: (variables: unknown) => Promise<unknown> }).mutationFn(args)
      }
      return Response.json({ data: data ?? null })
    } catch (error) {
      const { code, status, message } = error as StoreApiError
      return Response.json({ error: { code, status, message } }, { status })
    }
  }
}

const transports: Array<[string, (woo: FakeWoo) => StoreTransport]> = [
  ['RpcTransport', (woo) => new RpcTransport({ fetch: rpcRoute(woo) })],
  ['BrowserTransport', (woo) => new BrowserTransport({
    url: WOO_ORIGIN,
    fetch: woo.fetch,
    source: 'store',
    session: new SessionStorageSessionStore('hooks', new MemoryStorage()),
  })],
]

describe.each(transports)('hooks over %s', (_name: string, createTransport: (woo: FakeWoo) => StoreTransport) => {
  function wrapper() {
    const transport = createTransport(createFakeWoo())
    const queryClient = createWooQueryClient({ defaultOptions: { queries: { retry: false } } })
    return ({ children }: { children: ReactNode }) => (
      <StoreProvider transport={transport} queryClient={queryClient}>{children}</StoreProvider>
    )
  }

  it('lists products and resolves one by slug and by id', async () => {
    const { result } = renderHook(() => ({
      list: useProducts({ perPage: 10 }),
      bySlug: useProduct(PAPERBACK.slug),
      byId: useProduct(PAPERBACK.id),
    }), { wrapper: wrapper() })

    await waitFor(() => expect(result.current.byId.isSuccess).toBe(true))
    await waitFor(() => expect(result.current.list.isSuccess && result.current.bySlug.isSuccess).toBe(true))
    expect(result.current.list.data?.items.map((product) => product.sku).sort()).toEqual([
      'field-guide-ebook',
      'field-guide-print',
    ])
    expect(result.current.bySlug.data?.id).toBe(PAPERBACK.id)
    expect(result.current.byId.data).toMatchObject({ slug: PAPERBACK.slug, prices: { price: '2900' } })
  })

  it('passes typed errors through unchanged', async () => {
    const { result } = renderHook(() => useProduct(999_999), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.isError).toBe(true))
    expect(result.current.error).toBeInstanceOf(NotFoundError)
    expect(result.current.error).toMatchObject({ status: 404, code: 'woocommerce_rest_product_invalid_id' })
  })

  it('adds, updates and removes cart lines, then hands off to checkout', async () => {
    const { result } = renderHook(() => ({
      cart: useCart(),
      add: useAddToCart(),
      update: useUpdateCartItem(),
      remove: useRemoveCartItem(),
      checkout: useCheckoutUrl(),
    }), { wrapper: wrapper() })

    await waitFor(() => expect(result.current.cart.data?.itemsCount).toBe(0))

    await act(() => result.current.add.mutateAsync({ id: PAPERBACK.id, quantity: 2 }))
    await waitFor(() => expect(result.current.cart.data?.itemsCount).toBe(2))
    const line = result.current.cart.data!.items[0]!
    expect(line).toMatchObject({ name: 'The Field Guide (paperback)', totals: { lineTotal: '5800' } })
    expect(result.current.cart.data?.totals).toMatchObject({ totalShipping: '1500', totalPrice: '7300' })

    const handoff = await act(() => result.current.checkout.mutateAsync())
    expect(new URL(handoff).pathname).toMatch(/^\/checkout\/c\/[^/]+$/u)

    await act(() => result.current.update.mutateAsync({ key: line.key, quantity: 3 }))
    await waitFor(() => expect(result.current.cart.data?.itemsCount).toBe(3))

    await act(() => result.current.remove.mutateAsync({ key: line.key }))
    await waitFor(() => expect(result.current.cart.data?.itemsCount).toBe(0))
  })
})
