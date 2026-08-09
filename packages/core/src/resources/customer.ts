import type { Customer, Order, OrderSummary, SessionStore, StorefrontClient, Transport } from '../contracts'
import { addressPayload, compactQuery, PLUGIN_API, queryKey } from './shared'

export function createCustomerResource(
  transport: Transport,
  session: SessionStore,
): StorefrontClient['customer'] {
  return {
    get() {
      return {
        queryKey: queryKey('customer', 'get'),
        profile: 'session',
        queryFn: async () => {
          const response = await transport.request<Customer>({
            method: 'GET',
            path: `${PLUGIN_API}/customer`,
            profile: 'session',
          }, session)
          return response.data
        },
      }
    },

    orders(params?: { page?: number; perPage?: number }) {
      return {
        queryKey: queryKey('orders', 'list', params),
        profile: 'session',
        queryFn: async () => {
          const response = await transport.request<OrderSummary[]>({
            method: 'GET',
            path: `${PLUGIN_API}/customer/orders`,
            ...(params === undefined
              ? {}
              : { query: compactQuery({ page: params.page, per_page: params.perPage }) }),
            profile: 'session',
          }, session)
          return { items: response.data, total: response.total ?? response.data.length }
        },
      }
    },

    order(id: number) {
      return {
        queryKey: queryKey('orders', 'byId', { id }),
        profile: 'session',
        queryFn: async () => {
          const response = await transport.request<Order>({
            method: 'GET',
            path: `${PLUGIN_API}/customer/orders/${id}`,
            profile: 'session',
          }, session)
          return response.data
        },
      }
    },

    updateAddress: {
      mutationKey: queryKey('customer', 'updateAddress'),
      invalidates: [{ type: 'resource', resource: 'customer' }],
      mutationFn: async (variables) => {
        const response = await transport.request<Customer>({
          method: 'PUT',
          path: `${PLUGIN_API}/customer/address`,
          body: { type: variables.type, address: addressPayload(variables.address) },
          profile: 'session',
        }, session)
        return response.data
      },
    },
  }
}
