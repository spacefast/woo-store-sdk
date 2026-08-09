import type { Cart, CartItem, SessionStore, StorefrontClient, Transport } from '../contracts'
import { PLUGIN_API, queryKey, STORE_API } from './shared'

const CART_INVALIDATION = [{ type: 'resource', resource: 'cart' }] as const

function itemsCount(items: CartItem[]): number {
  return items.reduce((total, item) => total + item.quantity, 0)
}

function variationEntries(variation?: Record<string, string>): { attribute: string; value: string }[] {
  return Object.entries(variation ?? {}).map(([attribute, value]) => ({ attribute, value }))
}

function variationMatches(item: CartItem, variation?: Record<string, string>): boolean {
  const expected = Object.entries(variation ?? {}).sort(([left], [right]) => left.localeCompare(right))
  const actual = item.variation
    .map(({ attribute, value }) => [attribute, value] as const)
    .sort(([left], [right]) => left.localeCompare(right))
  return JSON.stringify(actual) === JSON.stringify(expected)
}

function optimisticAdd(
  variables: { id: number; quantity?: number; variation?: Record<string, string> },
  current: Cart | undefined,
): Cart | undefined {
  if (!current) return undefined
  const quantity = variables.quantity ?? 1
  const existingIndex = current.items.findIndex(
    (item) => item.id === variables.id && variationMatches(item, variables.variation),
  )
  const items = current.items.map((item) => ({ ...item }))

  if (existingIndex >= 0) {
    const existing = items[existingIndex]
    if (existing) items[existingIndex] = { ...existing, quantity: existing.quantity + quantity }
  } else {
    const variation = variationEntries(variables.variation)
    items.push({
      key: `optimistic-${variables.id}-${encodeURIComponent(JSON.stringify(variation))}`,
      id: variables.id,
      name: '',
      quantity,
      images: [],
      prices: {
        price: '',
        currencyCode: current.totals.currencyCode,
        currencyMinorUnit: current.totals.currencyMinorUnit,
      },
      totals: { lineTotal: '', lineSubtotal: '' },
      variation,
    })
  }

  return { ...current, items, itemsCount: itemsCount(items), raw: undefined }
}

function optimisticUpdate(
  variables: { key: string; quantity: number },
  current: Cart | undefined,
): Cart | undefined {
  if (!current) return undefined
  const items = current.items.map((item) =>
    item.key === variables.key ? { ...item, quantity: variables.quantity } : { ...item },
  )
  return { ...current, items, itemsCount: itemsCount(items), raw: undefined }
}

function optimisticRemove(variables: { key: string }, current: Cart | undefined): Cart | undefined {
  if (!current) return undefined
  const items = current.items.filter((item) => item.key !== variables.key).map((item) => ({ ...item }))
  return { ...current, items, itemsCount: itemsCount(items), raw: undefined }
}

export function createCartResource(transport: Transport, session: SessionStore): StorefrontClient['cart'] {
  const freshCart = async (): Promise<Cart> => {
    const response = await transport.request<Cart>({
      method: 'GET',
      path: `${STORE_API}/cart`,
      profile: 'session',
    }, session)
    return response.data
  }

  /** Store API mutations require an isolated Cart-Token for this visitor. */
  const ensureCartToken = async (): Promise<void> => {
    const { cartToken } = await session.read()
    if (!cartToken) {
      await transport.request<unknown>({
        method: 'POST',
        path: `${PLUGIN_API}/session`,
        profile: 'session',
      }, session)
    }
  }

  return {
    get() {
      return {
        queryKey: queryKey('cart', 'get'),
        profile: 'session',
        queryFn: freshCart,
      }
    },

    addItem: {
      mutationKey: queryKey('cart', 'addItem'),
      invalidates: [...CART_INVALIDATION],
      optimisticUpdate: optimisticAdd,
      mutationFn: async (variables) => {
        await ensureCartToken()
        const response = await transport.request<Cart>({
          method: 'POST',
          path: `${STORE_API}/cart/add-item`,
          body: {
            id: variables.id,
            quantity: variables.quantity ?? 1,
            ...(variables.variation === undefined ? {} : { variation: variationEntries(variables.variation) }),
          },
          profile: 'session',
        }, session)
        return response.data
      },
    },

    updateItem: {
      mutationKey: queryKey('cart', 'updateItem'),
      invalidates: [...CART_INVALIDATION],
      optimisticUpdate,
      mutationFn: async (variables) => {
        await ensureCartToken()
        const response = await transport.request<Cart>({
          method: 'POST',
          path: `${STORE_API}/cart/update-item`,
          body: variables,
          profile: 'session',
        }, session)
        return response.data
      },
    },

    removeItem: {
      mutationKey: queryKey('cart', 'removeItem'),
      invalidates: [...CART_INVALIDATION],
      optimisticUpdate: optimisticRemove,
      mutationFn: async (variables) => {
        await ensureCartToken()
        const response = await transport.request<Cart>({
          method: 'POST',
          path: `${STORE_API}/cart/remove-item`,
          body: variables,
          profile: 'session',
        }, session)
        return response.data
      },
    },

    applyCoupon: {
      mutationKey: queryKey('cart', 'applyCoupon'),
      invalidates: [...CART_INVALIDATION],
      mutationFn: async (variables) => {
        await ensureCartToken()
        const response = await transport.request<Cart>({
          method: 'POST',
          path: `${STORE_API}/cart/apply-coupon`,
          body: variables,
          profile: 'session',
        }, session)
        return response.data
      },
    },

    removeCoupon: {
      mutationKey: queryKey('cart', 'removeCoupon'),
      invalidates: [...CART_INVALIDATION],
      mutationFn: async (variables) => {
        await ensureCartToken()
        const response = await transport.request<Cart>({
          method: 'POST',
          path: `${STORE_API}/cart/remove-coupon`,
          body: variables,
          profile: 'session',
        }, session)
        return response.data
      },
    },

    async checkoutUrl() {
      const cart = await freshCart()
      if (!cart.checkoutUrl) {
        throw new Error(
          'Hosted checkout URL is unavailable. Install and activate the Woo Storefront feature plugin.',
        )
      }
      return cart.checkoutUrl
    },
  }
}
